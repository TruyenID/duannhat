<?php

namespace App\Listeners\Notification;

use App\Events\Notification\CustomNotificationEvent;
use App\Jobs\Notification\EvaluateRuleJob;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/**
 * Plan-023 M7 T7.3 — wildcard Eloquent event bridge.
 *
 * AppServiceProvider::boot registers this listener for
 * `eloquent.created: *`, `eloquent.updated: *`, `eloquent.deleted: *`.
 * For each fired event, the bridge:
 *
 *   1. Resolves the model's morph alias via `Relation::morphMap`.
 *   2. Static-blocklists the four notification-domain models (loop
 *      protection — a rule that dispatches a Notification cannot
 *      fire on its own emission).
 *   3. Queries active NotificationRule rows matching
 *      (trigger_event=`model.{verb}`, trigger_model_type=alias,
 *       is_active=true).
 *   4. Dispatches one EvaluateRuleJob per match — the job re-loads
 *      the model fresh + applies the condition DSL + writes a
 *      firing row.
 *
 * Blocklist (T7.15 arch-tested):
 *   - Notification
 *   - NotificationRecipient
 *   - NotificationDelivery
 *   - NotificationRuleFiring
 *
 * No additions to this list without an explicit reason — the arch
 * test re-runs whenever the bridge file changes.
 */
class ModelEventToRuleBridge
{
    /**
     * Notification-domain models that MUST NOT trigger rule
     * evaluation. Static + final-ish so the arch test can match the
     * literal token list without parsing.
     */
    public const BLOCKLIST = [
        Notification::class,
        NotificationRecipient::class,
        NotificationDelivery::class,
        NotificationRuleFiring::class,
    ];

    /**
     * Wildcard handler — Laravel hands us the event name string
     * (e.g. "eloquent.updated: App\\Models\\Recipe") and the payload
     * array (single Model element for save/update/delete events).
     *
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $verb = $this->verbFor($eventName);
        if ($verb === null) {
            return;
        }

        $model = $payload[0] ?? null;
        if (! $model instanceof Model) {
            return;
        }

        if (in_array($model::class, self::BLOCKLIST, true)) {
            return;
        }

        $alias = $this->morphAlias($model);
        if ($alias === null) {
            return;
        }

        $triggerEvent = "model.{$verb}";
        $changes = $verb === 'updated'
            ? $this->normaliseChanges($model)
            : [];
        $modelId = (string) ($model->getKey() ?? '');

        NotificationRule::query()
            ->where('trigger_event', $triggerEvent)
            ->where('trigger_model_type', $alias)
            ->where('is_active', true)
            ->lazyById(200)
            ->each(function (NotificationRule $rule) use ($modelId, $model, $changes) {
                EvaluateRuleJob::dispatch(
                    $rule->id,
                    $model::class,
                    $modelId,
                    $changes,
                );
            });
    }

    /**
     * Plan-023 M8 T8.2 — custom.* event handler.
     *
     * Registered in AppServiceProvider::boot() via:
     *   Event::listen('custom.*', [ModelEventToRuleBridge::class, 'onCustomEvent']);
     *
     * Validates the payload is a CustomNotificationEvent, resolves active
     * rules matching the event name, and dispatches EvaluateRuleJob per match.
     *
     * @param  array<int, mixed>  $payload
     */
    public function onCustomEvent(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! $event instanceof CustomNotificationEvent) {
            Log::warning('notification.custom-event.bad-shape', [
                'event' => $eventName,
                'payload_type' => is_object($event) ? $event::class : gettype($event),
            ]);

            return;
        }

        $subject = $event->subject;
        $alias = $this->morphAlias($subject);

        if ($alias === null) {
            return;
        }

        $modelId = (string) ($subject->getKey() ?? '');

        NotificationRule::query()
            ->where('trigger_event', $eventName)
            ->where('is_active', true)
            ->lazyById(200)
            ->each(function (NotificationRule $rule) use ($subject, $event, $modelId) {
                EvaluateRuleJob::dispatch(
                    $rule->id,
                    $subject::class,
                    $modelId,
                    $event->changes,
                    $event->context,
                );
            });
    }

    /**
     * Parse the Laravel "eloquent.<verb>: <class>" event name.
     */
    private function verbFor(string $eventName): ?string
    {
        if (! str_starts_with($eventName, 'eloquent.')) {
            return null;
        }
        $tail = substr($eventName, strlen('eloquent.'));
        $colon = strpos($tail, ':');
        if ($colon === false) {
            return null;
        }
        $verb = substr($tail, 0, $colon);

        return in_array($verb, ['created', 'updated', 'deleted'], true) ? $verb : null;
    }

    private function morphAlias(Model $model): ?string
    {
        try {
            $alias = $model->getMorphClass();
        } catch (\Throwable) {
            // ClassMorphViolationException — morph map is enforced and
            // this model isn't registered. Don't route events on
            // unmapped models. The rule controller validates
            // trigger_model_type against the morph map, so an
            // unmapped class would never appear there anyway.
            return null;
        }

        if ($alias === $model::class && Relation::getMorphedModel($alias) === null) {
            return null;
        }

        return $alias;
    }

    /**
     * Convert Eloquent's `$model->getChanges()` (current values keyed
     * by column) + `$model->getOriginal()` (pre-save values) into the
     * evaluator's expected `[col => [old, new]]` shape.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function normaliseChanges(Model $model): array
    {
        $changes = $model->getChanges();
        $original = $model->getOriginal();
        $out = [];
        foreach ($changes as $col => $newVal) {
            $out[$col] = [$original[$col] ?? null, $newVal];
        }

        return $out;
    }
}
