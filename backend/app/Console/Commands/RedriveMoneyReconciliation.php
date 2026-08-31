<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MoneyReconciliationTask;
use App\Support\Logging\MoneyOrchestrationLog;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The relay half of the reconciliation outbox (#1204 + #1206).
 *
 * Two steps, because the outbox now carries only the types no relay may settle
 * on its own:
 *
 *   1. stuck claims → pending. A row `claimed` past the timeout is a crashed
 *      run, not work in progress.
 *   2. stale alert. Rows older than the threshold are reported on the alertable
 *      channel, because the whole point is that nobody was hearing about this.
 *
 * WHAT IT WILL AND WILL NOT DO.
 *
 * `stranded_charge` and `overpayment_rejected` it deliberately will NOT settle.
 * Both would mean moving real money back to a customer without a human
 * deciding to. Automated reversal is not the relay's authority to take, and
 * plan-054 D5 already records the same ruling for PayPay ("deliberately
 * unwired… the hand-off is to the operator"). For those types the row IS the
 * deliverable: it makes the debt durable, attributable and countable, and the
 * stale alert makes sure it is seen. A human resolves it and the resolution is
 * recorded.
 *
 * (The `return_invoice` type — 赤伝 re-issue — was retired with #1779: red
 * invoices now only print and never persist, so no such task is ever enqueued
 * and there is nothing left for the relay to finish on its own.)
 *
 * Never recomputes an amount — see MoneyReconciliationQueue for why the payload
 * is authoritative.
 */
#[AsCommand(name: 'payments:redrive-reconciliation')]
final class RedriveMoneyReconciliation extends Command
{
    protected $signature = 'payments:redrive-reconciliation
        {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Recover stuck money-reconciliation claims and alert on stale rows (#1204/#1206)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $claimTimeout = (int) config('payments.reconciliation.claim_timeout_minutes', 15);
        $staleMinutes = (int) config('payments.reconciliation.stale_pending_minutes', 120);

        // 1 — stuck claims back to pending.
        $stuckQuery = MoneyReconciliationTask::query()
            ->where('status', 'claimed')
            ->where('claimed_at', '<', now()->subMinutes($claimTimeout));
        $stuck = $dryRun
            ? $stuckQuery->count()
            : $stuckQuery->update(['status' => 'pending', 'claimed_at' => null]);

        // 2 — stale alert. Counted across ALL types: for the ones this command
        // does not settle, ageing IS the signal.
        $stale = MoneyReconciliationTask::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('created_at', '<', now()->subMinutes($staleMinutes))
            ->get(['id', 'task_type', 'subject_id', 'created_at']);

        if ($stale->isNotEmpty()) {
            MoneyOrchestrationLog::error(
                MoneyOrchestrationLog::TAG_RECONCILE,
                'reconciliation_stale_pending',
                [
                    'count' => $stale->count(),
                    'threshold_minutes' => $staleMinutes,
                    'by_type' => $stale->countBy('task_type')->all(),
                    'oldest' => (string) $stale->min('created_at'),
                    'sample_ids' => $stale->take(20)->pluck('id')->all(),
                ],
            );
        }

        $this->info(sprintf(
            '%sreconciliation: %d stuck claims recovered, %d stale',
            $dryRun ? '[dry-run] ' : '',
            $stuck,
            $stale->count(),
        ));

        return self::SUCCESS;
    }
}
