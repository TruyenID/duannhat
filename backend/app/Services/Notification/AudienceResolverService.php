<?php

namespace App\Services\Notification;

use App\Contracts\Notifiable;
use App\Exceptions\NotificationException;
use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Services\Notification\AudienceResolvers\AudienceResolver;
use App\Services\Notification\AudienceResolvers\BrandResolver;
use App\Services\Notification\AudienceResolvers\DeviceResolver;
use App\Services\Notification\AudienceResolvers\RoleResolver;
use App\Services\Notification\AudienceResolvers\ShopResolver;
use App\Services\Notification\AudienceResolvers\UserResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Orchestrates sub-resolvers to expand a rule JSON (stored on
 * `notification_audiences.rule` or produced by `Audience::toRule()`) into
 * a concrete Collection<Notifiable>.
 *
 * Rule shape contract (enforced here, detailed in
 * `NotificationAudience.yaml` header comment):
 *
 *   {
 *     version: 1,
 *     combinator: 'or' | 'and',        // default 'or'
 *     rules: [ { type: '<resolver>', ... }, ... ],
 *     exclude: [ { type: '<resolver>', ... } ]   // optional
 *   }
 *
 * Invariants:
 *   - Sub-rule `type` MUST match a registered resolver's `type()` — else
 *     throws NotificationException('unknown_resolver_type').
 *   - Dedup is keyed on `{morphClass}:{primaryKey}` so the same entity
 *     matched by multiple sub-rules appears once.
 *   - Excludes are applied LAST, after combinator collapse.
 *   - Resolved set > `MAX_RECIPIENTS` (10 000) throws
 *     NotificationException('audience_too_large', 422). Admin compose UI
 *     pre-checks via `/audiences/preview` before submit.
 *
 * Trace output:
 *   `resolveWithTrace` returns a map keyed by `{morphClass}:{key}` → the
 *   resolver's trace string (e.g. `role:warehouse_manager:warehouse_id:...`).
 *   `NotificationService::dispatch` plumbs these into
 *   `notification_recipients.resolved_via` for audit/debug.
 */
class AudienceResolverService
{
    public const int MAX_RECIPIENTS = 10_000;

    /** @var array<string, AudienceResolver> */
    private array $resolvers;

    /**
     * @param  iterable<AudienceResolver>|null  $resolvers  Override for tests
     */
    public function __construct(?iterable $resolvers = null)
    {
        $resolvers ??= [
            app(RoleResolver::class),
            app(UserResolver::class),
            app(ShopResolver::class),
            app(BrandResolver::class),
            app(DeviceResolver::class),
        ];

        $this->resolvers = [];
        foreach ($resolvers as $resolver) {
            $this->resolvers[$resolver->type()] = $resolver;
        }
    }

    /**
     * @return array<string, AudienceResolver>
     */
    public function resolvers(): array
    {
        return $this->resolvers;
    }

    /**
     * Resolve a rule (NotificationAudience or raw rule array) to its
     * recipient Collection.
     *
     * @param  NotificationAudience|array<string, mixed>  $input
     * @return Collection<int, Model>
     */
    public function resolve(NotificationAudience|array $input, Brand $brand): Collection
    {
        return $this->resolveWithTrace($input, $brand)['recipients'];
    }

    /**
     * Resolve + trace. See class docblock.
     *
     * @param  NotificationAudience|array<string, mixed>  $input
     * @return array{recipients: Collection<int, Model>, trace: Collection<string, string>}
     */
    public function resolveWithTrace(NotificationAudience|array $input, Brand $brand): array
    {
        $rule = $input instanceof NotificationAudience ? (array) $input->rule : $input;

        $combinator = $rule['combinator'] ?? 'or';
        $subRules = (array) ($rule['rules'] ?? []);
        $excludeRules = (array) ($rule['exclude'] ?? []);

        $includeSets = array_map(
            fn (array $sub) => $this->runSubRule($sub, $brand),
            $subRules,
        );

        $entries = $this->combine($combinator, $includeSets);

        if ($excludeRules !== []) {
            $excludeKeys = collect();
            foreach ($excludeRules as $excludeRule) {
                foreach ($this->runSubRule($excludeRule, $brand) as $entry) {
                    $excludeKeys->push($entry['key']);
                }
            }
            $keyLookup = $excludeKeys->flip();
            $entries = $entries->reject(fn (array $entry) => $keyLookup->has($entry['key']))->values();
        }

        if ($entries->count() > self::MAX_RECIPIENTS) {
            throw NotificationException::audienceTooLarge($entries->count(), self::MAX_RECIPIENTS);
        }

        $recipients = $entries->map(fn (array $entry) => $entry['notifiable'])->values();
        $trace = $entries->mapWithKeys(fn (array $entry) => [$entry['key'] => $entry['trace']]);

        return [
            'recipients' => $recipients,
            'trace' => $trace,
        ];
    }

    /**
     * Look up the resolver for a sub-rule's type and delegate.
     *
     * @param  array<string, mixed>  $subRule
     * @return Collection<int, array{notifiable: Notifiable, key: string, trace: string}>
     */
    private function runSubRule(array $subRule, Brand $brand): Collection
    {
        $type = (string) ($subRule['type'] ?? '');
        $resolver = $this->resolvers[$type] ?? null;

        if ($resolver === null) {
            throw NotificationException::unknownResolverType($type);
        }

        return $resolver->resolve($subRule, $brand);
    }

    /**
     * Combine include sets under the given combinator.
     *
     * - `or`  → union, dedup by `key`, first trace wins.
     * - `and` → intersection, only entries present in every set, first
     *           trace wins.
     *
     * @param  array<int, Collection<int, array{notifiable: Notifiable, key: string, trace: string}>>  $sets
     * @return Collection<int, array{notifiable: Notifiable, key: string, trace: string}>
     */
    private function combine(string $combinator, array $sets): Collection
    {
        if ($sets === []) {
            return collect();
        }

        if ($combinator === 'and') {
            $first = $sets[0];
            $rest = array_slice($sets, 1);
            $keySets = array_map(
                fn (Collection $set) => $set->pluck('key')->flip(),
                $rest,
            );

            return $first
                ->filter(function (array $entry) use ($keySets): bool {
                    foreach ($keySets as $lookup) {
                        if (! $lookup->has($entry['key'])) {
                            return false;
                        }
                    }

                    return true;
                })
                ->unique('key')
                ->values();
        }

        // Default: union
        $seen = [];
        $out = collect();
        foreach ($sets as $set) {
            foreach ($set as $entry) {
                if (isset($seen[$entry['key']])) {
                    continue;
                }
                $seen[$entry['key']] = true;
                $out->push($entry);
            }
        }

        return $out->values();
    }
}
