<?php

namespace App\Jobs\Payment;

use App\Services\Payment\ProviderEvent\ProviderEventInboxService;
use App\Services\Payment\ProviderEvent\ProviderEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessPaymentProviderEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $providerEventRecordId) {}

    public function handle(
        ProviderEventInboxService $inbox,
        ProviderEventProcessor $processor,
    ): void {
        $event = $inbox->claim($this->providerEventRecordId);

        if ($event === null) {
            return;
        }

        try {
            $outcome = $processor->process($event);
            $inbox->markSucceeded($this->providerEventRecordId, $outcome);
        } catch (Throwable $e) {
            Log::channel('payment_orchestration')->warning('provider_event_processing_failed', [
                'provider_event_record_id' => $this->providerEventRecordId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $inbox->markDeadLetter($this->providerEventRecordId, 'PROCESSING_EXHAUSTED');

                throw $e;
            }

            $inbox->markRetryable(
                $this->providerEventRecordId,
                'PROCESSING_TRANSIENT',
                $this->attempts(),
            );

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ProviderEventInboxService::class)->markDeadLetter(
            $this->providerEventRecordId,
            'PROCESSING_EXHAUSTED',
        );
    }
}
