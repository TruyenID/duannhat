<?php

namespace App\Jobs\Notification;

use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use App\Services\Notification\AudienceRuleResolver;
use App\Services\Notification\NotificationService;
use App\Services\Notification\RuleEvaluatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Plan-023 M7 T7.4 — queued evaluator for a single (rule, model)
 * match. Dispatched by ModelEventToRuleBridge.
 *
 * Per-invocation flow:
 *
 *   1. Re-load the NotificationRule fresh — defensive in case it was
 *      disabled between the bridge enqueue and the worker pick.
 *   2. Re-load the firing model fresh from its concrete class +
 *      primary key. Skip silently when the model has been deleted
 *      between event + job (e.g. delete + recreate inside a
 *      transaction that rolled back).
 *   3. Cooldown check — query notification_rule_firings for any row
 *      within `rule.cooldown_minutes` for the same (rule, model).
 *      Hit → write a `skipped_cooldown` firing, return.
 *   4. RuleEvaluatorService::evaluate with the (model, changes) pair.
 *   5. On match:
 *        - Parallel-shadow mode (NOTIFICATION_USE_RULES=false): write
 *          a `shadow` firing row, don't dispatch. This is the M7
 *          rollout gate — compare shadow firings to hardcoded emitter
 *          output before flipping the flag.
 *        - Normal mode (flag=true): build the params from the rule's
 *          action.param_template (referencing $model fields), dispatch
 *          via NotificationService, write a `matched` firing row +
 *          increment the rule's fire_count + last_fired_at.
 *      Mismatch → write a `skipped_condition` firing with the trace
 *      so the admin debug view has data.
 *
 * Errors land in a `error` firing row with the throwable message and
 * the rule is left untouched (no fire_count bump, no last_fired_at).
 * Job retries (3 attempts default) cover transient queue failures.
 */
class EvaluateRuleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * `{trigger_model_type}:{trigger_event}` pairs that still have a live
     * hardcoded emitter: StockAlertNotificationObserver,
     * CustomerOrderNotificationObserver, RecipeService::approve/reject.
     *
     * A rule matching one of these *shadows* its emitter. While
     * NOTIFICATION_USE_RULES is off such a rule records a `shadow` firing and
     * does NOT dispatch — otherwise it would double-notify alongside the
     * still-live hardcoded emitter. Every other rule (all M8 system-rule
     * domains: Product, Menu, StockTransaction, StockTransfer, Device, Coupon,
     * Brand, plus any admin-authored rule) has NO hardcoded emitter and is the
     * sole delivery path for its domain, so it MUST dispatch regardless of the
     * flag. Gating those behind the shadow flag silences the entire M8 emitter
     * coverage under the shipped NOTIFICATION_USE_RULES=false default.
     */
    private const SHADOWED_EMITTERS = [
        'StockAlert:model.created',
        'CustomerOrder:model.updated',
        'Recipe:model.updated',
    ];

    public function __construct(
        public readonly string $ruleId,
        public readonly string $modelClass,
        public readonly string $modelId,
        public readonly array $changes = [],
        public readonly array $context = [],
    ) {
        $this->onQueue('notifications-rule-evaluation');
    }

    public function handle(RuleEvaluatorService $evaluator, NotificationService $notifications, AudienceRuleResolver $audience): void
    {
        $rule = NotificationRule::query()->find($this->ruleId);
        if ($rule === null || ! $rule->is_active) {
            return;
        }

        $model = $this->loadModel();
        if ($model === null) {
            return;
        }

        if ($this->isInCooldown($rule)) {
            $this->logFiring($rule, 'skipped_cooldown', null, null);

            return;
        }

        try {
            $result = $evaluator->evaluate($rule, $model, $this->changes);
        } catch (Throwable $e) {
            $this->logFiring($rule, 'error', null, null, $e->getMessage());

            return;
        }

        if (! $result->matched) {
            $this->logFiring($rule, 'skipped_condition', $result->trace, null);

            return;
        }

        if ($this->shadowsHardcodedEmitter($rule) && ! $this->useRules()) {
            // Parallel-shadow mode — match recorded, dispatch suppressed so we
            // don't double-notify alongside the still-live hardcoded emitter.
            // Only rules that shadow a Phase A emitter are gated here; M8 system
            // rules (no hardcoded emitter) always dispatch — see SHADOWED_EMITTERS.
            $this->logFiring($rule, 'shadow', $result->trace, null);

            return;
        }

        // Resolve the rule's audience BEFORE dispatch. Without this the
        // NotificationService receives an empty recipient list and throws
        // recipientsEmpty() for every matched rule (plan-023 M8 bug).
        $recipients = $this->resolveRecipients($rule, $model, $audience);
        if ($recipients->isEmpty()) {
            // Matched, but the audience resolved to nobody (e.g. no brand admin
            // exists yet). Record a distinct outcome so the admin debug view
            // shows it as an audience gap rather than a noisy `error` firing.
            $this->logFiring($rule, 'skipped_no_recipients', $result->trace, null);

            return;
        }

        try {
            $notificationId = $this->dispatchFromAction($rule, $model, $recipients, $notifications);
            $rule->update([
                'last_fired_at' => Carbon::now(),
                'fire_count' => ($rule->fire_count ?? 0) + 1,
            ]);
            $this->logFiring($rule, 'matched', $result->trace, $notificationId);
        } catch (Throwable $e) {
            $this->logFiring($rule, 'error', $result->trace, null, $e->getMessage());
        }
    }

    private function loadModel(): ?Model
    {
        if (! class_exists($this->modelClass)) {
            return null;
        }
        /** @var class-string<Model> $cls */
        $cls = $this->modelClass;

        return $cls::find($this->modelId);
    }

    private function isInCooldown(NotificationRule $rule): bool
    {
        $cooldown = (int) ($rule->cooldown_minutes ?? 0);
        if ($cooldown <= 0) {
            return false;
        }

        return NotificationRuleFiring::query()
            ->where('rule_id', $rule->id)
            ->where('model_id', $this->modelId)
            ->where('outcome', 'matched')
            ->where('fired_at', '>=', Carbon::now()->subMinutes($cooldown))
            ->exists();
    }

    private function useRules(): bool
    {
        return (bool) config('notifications.use_rules', false);
    }

    /**
     * Does this rule shadow a still-live hardcoded emitter? Only such rules
     * honour the NOTIFICATION_USE_RULES parallel-shadow gate.
     */
    private function shadowsHardcodedEmitter(NotificationRule $rule): bool
    {
        return in_array(
            $rule->trigger_model_type.':'.$rule->trigger_event,
            self::SHADOWED_EMITTERS,
            true,
        );
    }

    /**
     * Resolve the rule's `action.audience_rule` into a recipient Collection.
     * Returns an empty collection when the rule carries no audience_rule (e.g.
     * legacy M7 shadow rules that only stored an `audience_name` placeholder).
     *
     * @return Collection<int, Model>
     */
    private function resolveRecipients(NotificationRule $rule, Model $model, AudienceRuleResolver $audience): Collection
    {
        $action = (array) ($rule->action ?? []);
        $audienceRule = $action['audience_rule'] ?? null;

        if (! is_array($audienceRule) || $audienceRule === []) {
            return collect();
        }

        return $audience->resolve($audienceRule, $model);
    }

    /**
     * @param  Collection<int, Model>  $recipients
     */
    private function dispatchFromAction(NotificationRule $rule, Model $model, Collection $recipients, NotificationService $notifications): ?string
    {
        $action = (array) ($rule->action ?? []);
        $templateKey = (string) ($action['template_key'] ?? '');
        if ($templateKey === '') {
            return null;
        }

        $params = $this->buildParams($action['param_template'] ?? [], $model, $this->context);

        $notification = $notifications->dispatch([
            'type' => $templateKey,
            'template_key' => $templateKey,
            'params' => $params,
            'recipients' => $recipients->all(),
            'organization_id' => $rule->organization_id,
            'priority' => $action['priority'] ?? 'normal',
            'channels' => $action['channels'] ?? null,
            'subject' => $model,
            'idempotency_key' => sprintf('rule:%s:%s:%s', $rule->id, $this->modelId, time()),
        ]);

        return $notification?->id;
    }

    /**
     * Resolve a `{key: "$model.path.to.field"}` or `{key: "$context.key"}` template
     * against the firing model and custom event context. Literal values pass through
     * unchanged so admins can mix static + dynamic params.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildParams(array $template, Model $model, array $context = []): array
    {
        $out = [];
        foreach ($template as $key => $value) {
            if (is_string($value) && str_starts_with($value, '$model.')) {
                $path = substr($value, strlen('$model.'));
                $out[$key] = data_get($model, $path);
            } elseif (is_string($value) && str_starts_with($value, '$context.')) {
                $path = substr($value, strlen('$context.'));
                $out[$key] = data_get($context, $path);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Resolve a `{key: "$model.path.to.field"}` template against the
     * firing model. Literal values pass through unchanged so admins
     * can mix static + dynamic params.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function logFiring(NotificationRule $rule, string $outcome, ?array $trace, ?string $notificationId, ?string $errorMessage = null): void
    {
        try {
            NotificationRuleFiring::query()->create([
                'id' => (string) Str::uuid(),
                'rule_id' => $rule->id,
                'notification_id' => $notificationId,
                'model_type' => $this->modelClass,
                'model_id' => $this->modelId,
                'fired_at' => Carbon::now(),
                'outcome' => $outcome,
                'evaluation_trace' => $trace,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable $e) {
            Log::warning('rule-firing.log-failed', [
                'rule_id' => $rule->id,
                'outcome' => $outcome,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
