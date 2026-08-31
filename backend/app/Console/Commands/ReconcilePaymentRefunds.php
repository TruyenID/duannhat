<?php

namespace App\Console\Commands;

use App\Models\PaymentRefund;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\ProviderEvent\ProviderRetrievalRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Plan-047 T2.17 — scheduled recovery sweep for payment refunds stuck in
 * reconciliation_required or pending provider states. Dry-run by default.
 */
#[Signature('payments:reconcile-refunds {--execute : Apply reconciliation updates instead of dry-run preview}')]
#[Description('Sweep payment refunds due for provider reconciliation (plan-047)')]
final class ReconcilePaymentRefunds extends Command
{
    /** @var list<PaymentRefundStateEnum> */
    private const RECONCILE_STATES = [
        PaymentRefundStateEnum::ReconciliationRequired,
        PaymentRefundStateEnum::Pending,
        PaymentRefundStateEnum::Submitted,
    ];

    public function handle(ProviderRetrievalRecoveryService $recovery): int
    {
        $lock = Cache::lock('payments:reconcile-refunds', 300);

        if (! $lock->get()) {
            $this->warn('Another payments:reconcile-refunds run is already in progress.');

            return self::SUCCESS;
        }

        try {
            $execute = (bool) $this->option('execute');
            if (! $execute) {
                $this->warn('Running in dry-run mode. Pass --execute to retrieve provider truth and reconcile.');
            }

            $candidates = PaymentRefund::query()
                ->whereIn('state', array_map(static fn (PaymentRefundStateEnum $state): string => $state->value, self::RECONCILE_STATES))
                ->where(function ($query): void {
                    $query->whereNull('next_reconciliation_at')
                        ->orWhere('next_reconciliation_at', '<=', now());
                })
                ->orderBy('next_reconciliation_at')
                ->limit(100)
                ->get();

            if ($candidates->isEmpty()) {
                $this->info('No payment refunds require reconciliation.');

                return self::SUCCESS;
            }

            $recovered = 0;
            foreach ($candidates as $refund) {
                if ($execute) {
                    if ($recovery->recoverRefund($refund)) {
                        $recovered++;
                    }

                    continue;
                }

                $this->line(sprintf(
                    '[dry-run] refund=%s attempt=%s retries=%d next=%s',
                    $refund->id,
                    $refund->payment_attempt_id,
                    $refund->retry_count,
                    $refund->next_reconciliation_at?->toIso8601String() ?? 'null',
                ));
            }

            Log::channel('payment_orchestration')->info('reconcile_refunds_tick', [
                'execute' => $execute,
                'candidate_count' => $candidates->count(),
                'recovered_count' => $recovered,
            ]);

            $this->info($execute
                ? "Recovered {$recovered} of {$candidates->count()} refund(s)."
                : "Found {$candidates->count()} refund(s) due for reconciliation.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
