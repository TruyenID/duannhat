<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationLogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProviderEventInboxService
{
    /** @return list<PaymentProviderEventStateEnum> */
    public function terminalStates(): array
    {
        return [
            PaymentProviderEventStateEnum::Succeeded,
            PaymentProviderEventStateEnum::OperatorResolved,
        ];
    }

    public function isTerminal(PaymentProviderEvent $event): bool
    {
        $state = $event->state instanceof PaymentProviderEventStateEnum
            ? $event->state
            : PaymentProviderEventStateEnum::from($event->state);

        return in_array($state, $this->terminalStates(), true);
    }

    public function queue(string $providerEventRecordId): void
    {
        PaymentProviderEvent::query()
            ->where('id', $providerEventRecordId)
            ->where('state', PaymentProviderEventStateEnum::ReceivedVerified->value)
            ->update([
                'state' => PaymentProviderEventStateEnum::Queued->value,
            ]);
    }

    public function claim(string $providerEventRecordId): ?PaymentProviderEvent
    {
        return DB::transaction(function () use ($providerEventRecordId): ?PaymentProviderEvent {
            /** @var PaymentProviderEvent|null $event */
            $event = PaymentProviderEvent::query()
                ->where('id', $providerEventRecordId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return null;
            }

            $state = $event->state instanceof PaymentProviderEventStateEnum
                ? $event->state
                : PaymentProviderEventStateEnum::from($event->state);

            if (in_array($state, $this->terminalStates(), true)) {
                return null;
            }

            if (! in_array($state, [
                PaymentProviderEventStateEnum::Queued,
                PaymentProviderEventStateEnum::Retryable,
            ], true)) {
                return null;
            }

            $event->update([
                'state' => PaymentProviderEventStateEnum::Processing->value,
                'processing_attempts' => (int) $event->processing_attempts + 1,
                'lease_token' => Str::random(40),
                'lease_expires_at' => now()->addMinutes(5),
                'next_retry_at' => null,
            ]);

            return $event->fresh();
        });
    }

    /**
     * Reclaim inbox rows stranded in `Processing` after a worker died mid-run
     * (OOM, deploy, job timeout) — their 5-minute lease has lapsed but nothing
     * else moves them. Flip them back to `Retryable` due immediately so the
     * next `payments:process-provider-events` tick re-dispatches them; the
     * processor's own retry/dead-letter escalation then bounds further attempts.
     * Without this a confirmed webhook (e.g. payment_intent.succeeded) could sit
     * unprocessed forever and the paid order never settle.
     *
     * @return int number of rows reclaimed
     */
    public function reclaimExpiredLeases(): int
    {
        return PaymentProviderEvent::query()
            ->where('state', PaymentProviderEventStateEnum::Processing->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->update([
                'state' => PaymentProviderEventStateEnum::Retryable->value,
                'last_error_code' => 'lease_expired',
                'next_retry_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
            ]);
    }

    public function markSucceeded(string $providerEventRecordId, string $outcome): void
    {
        PaymentProviderEvent::query()
            ->where('id', $providerEventRecordId)
            ->update([
                'state' => PaymentProviderEventStateEnum::Succeeded->value,
                'outcome' => $outcome,
                'processed_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
                'next_retry_at' => null,
                'last_error_code' => null,
                'redacted_error' => null,
            ]);

        Log::channel('payment_orchestration')->info('provider_event_succeeded', [
            'provider_event_record_id' => $providerEventRecordId,
            'outcome' => $outcome,
        ]);
    }

    public function markRetryable(string $providerEventRecordId, string $errorCode, int $attempt): void
    {
        $backoff = (array) config('payments.provider_event.retry_backoff_seconds', [10, 30, 60, 120, 300]);
        $seconds = $backoff[max(0, min(count($backoff) - 1, $attempt - 1))] ?? 60;

        PaymentProviderEvent::query()
            ->where('id', $providerEventRecordId)
            ->update([
                'state' => PaymentProviderEventStateEnum::Retryable->value,
                'last_error_code' => $errorCode,
                'next_retry_at' => now()->addSeconds((int) $seconds),
                'lease_token' => null,
                'lease_expires_at' => null,
                'redacted_error' => PaymentOrchestrationLogContext::redact([
                    'message_code' => $errorCode,
                    'attempt' => $attempt,
                ]),
            ]);
    }

    public function markDeadLetter(string $providerEventRecordId, string $errorCode): void
    {
        PaymentProviderEvent::query()
            ->where('id', $providerEventRecordId)
            ->update([
                'state' => PaymentProviderEventStateEnum::DeadLetter->value,
                'last_error_code' => $errorCode,
                'processed_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
                'next_retry_at' => null,
                'redacted_error' => PaymentOrchestrationLogContext::redact([
                    'message_code' => $errorCode,
                ]),
            ]);

        Log::channel('payment_orchestration')->warning('provider_event_dead_letter', [
            'provider_event_record_id' => $providerEventRecordId,
            'error_code' => $errorCode,
        ]);
    }

    public function requeue(string $providerEventRecordId, ?string $operatorResolution = null): bool
    {
        return DB::transaction(function () use ($providerEventRecordId, $operatorResolution): bool {
            /** @var PaymentProviderEvent|null $event */
            $event = PaymentProviderEvent::query()
                ->where('id', $providerEventRecordId)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return false;
            }

            $state = $event->state instanceof PaymentProviderEventStateEnum
                ? $event->state
                : PaymentProviderEventStateEnum::from($event->state);

            if (! in_array($state, [
                PaymentProviderEventStateEnum::Retryable,
                PaymentProviderEventStateEnum::DeadLetter,
            ], true)) {
                return false;
            }

            $event->update([
                'state' => PaymentProviderEventStateEnum::Queued->value,
                'next_retry_at' => null,
                'operator_resolution' => $operatorResolution,
                'operator_resolved_at' => $operatorResolution === null ? null : now(),
            ]);

            return true;
        });
    }
}
