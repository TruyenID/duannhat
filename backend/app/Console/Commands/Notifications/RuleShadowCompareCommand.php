<?php

namespace App\Console\Commands\Notifications;

use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Plan-023 M7 T7.12 — parity reporter for the parallel-shadow rule
 * rollout.
 *
 * Compares, per Phase A emitter event in the `--since` window:
 *   - notifications produced by the hardcoded emitter (StockAlertObs
 *     / CustomerOrderObs / RecipeService), AND
 *   - shadow firings produced by the equivalent system rule (rows
 *     in notification_rule_firings with outcome='shadow' or
 *     'matched' filtered by the rule_id that maps to that emitter).
 *
 * Emits CSV columns:
 *   emitter, rule_id, trigger_id, hardcoded_count, shadow_count,
 *   match, drift_reason
 *
 * Exit code:
 *   0 — every row matches
 *   1 — at least one row drifts; CSV details printed
 *
 * Intended usage:
 *   php artisan notifications:rule-shadow-compare --since=14d --output=storage/app/shadow-parity-2026-05-15.csv
 *
 * Flip the NOTIFICATION_USE_RULES flag in staging/prod only after
 * two consecutive 14-day windows return exit 0.
 */
#[Signature('notifications:rule-shadow-compare {--since=7d : Window to scan, e.g. 1d, 7d, 30d} {--output= : File path; defaults to stdout} {--brand= : Limit to one brand UUID}')]
#[Description('Plan-023 M7 T7.12 — diff hardcoded Phase A emitter output vs shadow firings.')]
final class RuleShadowCompareCommand extends Command
{
    public function handle(): int
    {
        $since = $this->resolveSince((string) $this->option('since'));
        $output = $this->option('output');
        $brandFilter = $this->option('brand');

        $rows = collect(['emitter,rule_id,trigger_id,hardcoded_count,shadow_count,match,drift_reason']);
        $hasDrift = false;

        $shadowRules = NotificationRule::query()
            ->where('name', 'like', 'Shadow: %')
            ->when($brandFilter !== null, fn ($q) => $q->where('brand_id', $brandFilter))
            ->get();

        foreach ($shadowRules as $rule) {
            $emitter = $this->emitterFor($rule);

            $hardcoded = $this->hardcodedNotifications($emitter, $rule, $since);
            $shadow = $this->shadowFirings($rule, $since);

            $byTrigger = $this->indexByTrigger($hardcoded, $shadow);
            foreach ($byTrigger as $triggerId => $counts) {
                $match = $counts['hardcoded'] === $counts['shadow'];
                if (! $match) {
                    $hasDrift = true;
                }
                $rows->push(sprintf(
                    '%s,%s,%s,%d,%d,%s,%s',
                    $emitter,
                    $rule->id,
                    $triggerId,
                    $counts['hardcoded'],
                    $counts['shadow'],
                    $match ? 'true' : 'false',
                    $match ? '' : $this->reasonFor($counts),
                ));
            }
        }

        $csv = $rows->implode("\n")."\n";

        if ($output !== null) {
            file_put_contents($output, $csv);
            $this->info(sprintf('Wrote %d rows to %s', $rows->count() - 1, $output));
        } else {
            $this->line($csv);
        }

        if ($hasDrift) {
            $this->error('Drift detected — do NOT flip NOTIFICATION_USE_RULES yet.');

            return self::FAILURE;
        }

        $this->info('Parity confirmed. Hardcoded vs shadow output match across the window.');

        return self::SUCCESS;
    }

    private function emitterFor(NotificationRule $rule): string
    {
        return match ($rule->trigger_model_type) {
            'StockAlert' => 'stock_alert',
            'CustomerOrder' => 'customer_order',
            'Recipe' => str_contains((string) $rule->name, 'rejected') ? 'recipe_rejected' : 'recipe_approved',
            default => 'unknown',
        };
    }

    /**
     * Pull notifications produced by the hardcoded emitter in the window.
     * Keyed by the subject_id (= trigger id) for the diff index.
     */
    private function hardcodedNotifications(string $emitter, NotificationRule $rule, Carbon $since): Collection
    {
        return Notification::query()
            ->where('organization_id', $rule->organization_id)
            ->where('type', $rule->action['template_key'] ?? '')
            ->where('created_at', '>=', $since)
            ->get();
    }

    private function shadowFirings(NotificationRule $rule, Carbon $since): Collection
    {
        return NotificationRuleFiring::query()
            ->where('rule_id', $rule->id)
            ->whereIn('outcome', ['shadow', 'matched'])
            ->where('fired_at', '>=', $since)
            ->get();
    }

    /**
     * Index hardcoded and shadow rows by trigger id (notification
     * subject_id ↔ firing model_id) so per-event counts can be diffed.
     *
     * @return array<string, array{hardcoded: int, shadow: int}>
     */
    private function indexByTrigger(Collection $hardcoded, Collection $shadow): array
    {
        $index = [];

        $hardcoded->each(function (Notification $n) use (&$index) {
            $key = (string) ($n->subject_id ?? $n->id);
            $index[$key] ??= ['hardcoded' => 0, 'shadow' => 0];
            $index[$key]['hardcoded']++;
        });

        $shadow->each(function (NotificationRuleFiring $f) use (&$index) {
            $key = (string) ($f->model_id ?? $f->id);
            $index[$key] ??= ['hardcoded' => 0, 'shadow' => 0];
            $index[$key]['shadow']++;
        });

        return $index;
    }

    /**
     * @param  array{hardcoded: int, shadow: int}  $counts
     */
    private function reasonFor(array $counts): string
    {
        if ($counts['hardcoded'] > $counts['shadow']) {
            return 'rule_missed';
        }
        if ($counts['shadow'] > $counts['hardcoded']) {
            return 'rule_over_fired';
        }

        return 'unknown';
    }

    private function resolveSince(string $token): Carbon
    {
        if (preg_match('/^(\d+)([dhm])$/', $token, $m) !== 1) {
            $this->warn("Unrecognised --since={$token} — defaulting to 7d");

            return now()->subDays(7);
        }
        $qty = (int) $m[1];

        return match ($m[2]) {
            'd' => now()->subDays($qty),
            'h' => now()->subHours($qty),
            'm' => now()->subMinutes($qty),
        };
    }
}
