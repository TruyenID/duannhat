<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Payment\Orchestration\Internal\EloquentPaymentPersistence;
use App\Services\Payment\ProviderEvent\ProviderRetrievalRecoveryService;
use App\Support\Logging\MoneyOrchestrationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Plan-047 T2.17 — scheduled recovery sweep for payment attempts stuck in
 * reconciliation_required. Dry-run by default; operator passes --execute to
 * retrieve provider truth and finalize/reconcile attempts.
 */
#[Signature('payments:reconcile-attempts {--execute : Apply reconciliation updates instead of dry-run preview}')]
#[Description('Sweep payment attempts due for provider reconciliation (plan-047)')]
final class ReconcilePaymentAttempts extends Command
{
    public function handle(ProviderRetrievalRecoveryService $recovery): int
    {
        $lock = Cache::lock('payments:reconcile-attempts', 300);

        if (! $lock->get()) {
            $this->warn('Another payments:reconcile-attempts run is already in progress.');

            return self::SUCCESS;
        }

        try {
            $execute = (bool) $this->option('execute');
            if (! $execute) {
                $this->warn('Running in dry-run mode. Pass --execute to retrieve provider truth and reconcile.');
            }

            $candidates = PaymentAttempt::query()
                ->where('state', PaymentAttemptStateEnum::ReconciliationRequired->value)
                ->where(function ($query): void {
                    $query->whereNull('next_reconciliation_at')
                        ->orWhere('next_reconciliation_at', '<=', now());
                })
                ->orderBy('next_reconciliation_at')
                ->limit(100)
                ->get();

            if ($candidates->isEmpty()) {
                $this->info('No payment attempts require reconciliation.');

                return self::SUCCESS;
            }

            $recovered = 0;
            foreach ($candidates as $attempt) {
                if ($execute) {
                    // #1108 — one poisoned attempt (provider 404, network
                    // fault) must never abort the sweep for the whole batch.
                    try {
                        if ($recovery->recoverAttempt($attempt)) {
                            $recovered++;
                        }
                    } catch (\Throwable $e) {
                        MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_RECONCILE, 'reconcile_attempt_recovery_failed', [
                            'attempt_id' => $attempt->id,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);

                        // Backoff bookkeeping via the sanctioned payment
                        // persistence boundary (domain-mutation guard): a
                        // poisoned attempt must leave the head of the
                        // NULLs-first candidate window or it starves healthy
                        // attempts every run.
                        try {
                            app(EloquentPaymentPersistence::class)
                                ->deferAttemptReconciliation($attempt);
                        } catch (\Throwable $bookkeepingError) {
                            MoneyOrchestrationLog::error(MoneyOrchestrationLog::TAG_RECONCILE, 'reconcile_attempt_backoff_stamp_failed', [
                                'attempt_id' => $attempt->id,
                                'message' => $bookkeepingError->getMessage(),
                            ]);
                        }
                    }

                    continue;
                }

                $this->line(sprintf(
                    '[dry-run] attempt=%s order=%s retries=%d next=%s',
                    $attempt->id,
                    $attempt->customer_order_id,
                    $attempt->retry_count,
                    $attempt->next_reconciliation_at?->toIso8601String() ?? 'null',
                ));
            }

            Log::channel('payment_orchestration')->info('reconcile_attempts_tick', [
                'execute' => $execute,
                'candidate_count' => $candidates->count(),
                'recovered_count' => $recovered,
            ]);

            $this->info($execute
                ? "Recovered {$recovered} of {$candidates->count()} attempt(s)."
                : "Found {$candidates->count()} attempt(s) due for reconciliation.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
