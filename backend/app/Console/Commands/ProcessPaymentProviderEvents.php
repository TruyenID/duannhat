<?php

namespace App\Console\Commands;

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Services\Payment\ProviderEvent\ProviderEventInboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('payments:process-provider-events {--dead-letter : List or requeue dead-letter inbox rows} {--requeue= : Requeue a dead-letter or retryable inbox row by UUID} {--execute : Apply requeue actions instead of dry-run preview}')]
#[Description('Operator retry and dead-letter resolution for payment provider inbox events (plan-047)')]
final class ProcessPaymentProviderEvents extends Command
{
    public function handle(ProviderEventInboxService $inbox): int
    {
        $lock = Cache::lock('payments:process-provider-events', 300);

        if (! $lock->get()) {
            $this->warn('Another payments:process-provider-events run is already in progress.');

            return self::SUCCESS;
        }

        try {
            $requeueId = $this->option('requeue');

            if (is_string($requeueId) && $requeueId !== '') {
                return $this->requeueOne($inbox, $requeueId);
            }

            if ((bool) $this->option('dead-letter')) {
                return $this->listDeadLetter();
            }

            return $this->dispatchDueRetries($inbox);
        } finally {
            $lock->release();
        }
    }

    private function requeueOne(ProviderEventInboxService $inbox, string $providerEventRecordId): int
    {
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('Dry-run: pass --execute to requeue this provider event.');

            return self::SUCCESS;
        }

        if (! $inbox->requeue($providerEventRecordId, 'operator:payments:process-provider-events')) {
            $this->error("Provider event {$providerEventRecordId} is not requeueable.");

            return self::FAILURE;
        }

        ProcessPaymentProviderEventJob::dispatch($providerEventRecordId);
        $this->info("Requeued provider event {$providerEventRecordId}.");

        return self::SUCCESS;
    }

    private function listDeadLetter(): int
    {
        $rows = PaymentProviderEvent::query()
            ->where('state', PaymentProviderEventStateEnum::DeadLetter->value)
            ->orderByDesc('processed_at')
            ->limit(100)
            ->get(['id', 'provider_event_id', 'event_type', 'last_error_code', 'processing_attempts', 'processed_at']);

        if ($rows->isEmpty()) {
            $this->info('No dead-letter provider events.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                'dead_letter id=%s provider_event_id=%s type=%s attempts=%d error=%s processed_at=%s',
                $row->id,
                $row->provider_event_id,
                $row->event_type,
                $row->processing_attempts,
                $row->last_error_code ?? 'null',
                $row->processed_at?->toIso8601String() ?? 'null',
            ));
        }

        $this->info("Listed {$rows->count()} dead-letter provider event(s). Requeue with --requeue=<uuid> --execute.");

        return self::SUCCESS;
    }

    private function dispatchDueRetries(ProviderEventInboxService $inbox): int
    {
        $reclaimed = $inbox->reclaimExpiredLeases();
        if ($reclaimed > 0) {
            $this->warn("Reclaimed {$reclaimed} provider event(s) stranded in processing (expired lease).");
        }

        $due = PaymentProviderEvent::query()
            ->where('state', PaymentProviderEventStateEnum::Retryable->value)
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('next_retry_at')
            ->limit(100)
            ->pluck('id');

        if ($due->isEmpty()) {
            $this->info('No retryable provider events are due.');

            return self::SUCCESS;
        }

        foreach ($due as $id) {
            ProcessPaymentProviderEventJob::dispatch((string) $id);
            $this->line("[queued] provider_event={$id}");
        }

        $this->info("Dispatched {$due->count()} retryable provider event(s).");

        return self::SUCCESS;
    }
}
