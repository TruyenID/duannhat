<?php

namespace App\Console\Commands;

use App\Models\OrderPayment;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Payment\Orchestration\Internal\OrderPaymentLedgerWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fail pending payments that overran their `expires_at` deadline. Without
 * this sweep, a card/transfer that the customer abandoned (or that was
 * inserted via a network-glitched create) keeps counting toward the
 * overpayment guard in OrderPaymentService::create — staff sees
 * "Payment amount exceeds the outstanding order balance" the next time
 * they try to settle the rest of a split bill, even though there's
 * actually nothing collected.
 *
 * Scheduled every minute in routes/console.php so the orphan window is
 * bounded — the dialog's per-row idempotency_key (set when the row is
 * first touched) still de-dupes immediate retries; this sweep just
 * prevents long-tail buildup if the client never retries at all.
 */
#[Signature('payments:expire-stale')]
#[Description('Fail pending payments that have passed their expires_at deadline')]
class ExpireStalePendingPayments extends Command
{
    public function __construct(
        private readonly OrderPaymentLedgerWriter $ledger,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Iterate the rows so each gets an audit trail entry — bulk
        // UPDATE is faster but loses the per-payment provenance we want
        // when investigating "why did this go from pending → failed".
        $staleIds = OrderPayment::query()
            ->where('status', PaymentStatusEnum::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');

        $count = 0;
        foreach ($staleIds as $id) {
            $flipped = $this->ledger->expireIfStalePending((string) $id);

            if ($flipped !== null) {
                $flipped->logAudit('payment_expired', [
                    'reason' => 'expires_at_passed',
                    'expires_at' => $flipped->expires_at?->toIso8601String(),
                ]);
                $count++;
            }
        }

        if ($count > 0) {
            $this->info("Expired {$count} stale pending payment(s).");
        }

        return self::SUCCESS;
    }
}
