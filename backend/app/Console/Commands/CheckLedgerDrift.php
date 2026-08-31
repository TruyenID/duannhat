<?php

namespace App\Console\Commands;

use App\Services\Payment\Observation\LedgerDriftScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Read-only ledger-drift auditor for Plan 047 Gate 4 (T4.9).
 *
 * The single money invariant that every settlement path — cash, card
 * terminal, and Stripe — must preserve is:
 *
 *     customer_orders.paid_amount === OrderPayment::netCollectedForOrder()
 *
 * i.e. the cached order total equals the canonical ledger projection
 * (succeeded + refunded sale/refund rows, excluding debt-settlement rows
 * that ride a different order). `OrderPaymentService::updateOrderPaymentCache`
 * is the sole writer of that cache today; when the orchestrator replaces it,
 * this command is the drift gate proving no path diverged.
 *
 * This command NEVER writes. It classifies drift and returns a non-zero exit
 * code when any is found so it can gate CI / a pre-cutover shadow run. It is
 * tenant-scopable and chunked so it is safe to run against production volume.
 */
#[Signature('payments:check-ledger-drift {--organization= : Restrict the scan to one organization_id} {--limit= : Stop after inspecting this many orders} {--json : Emit machine-readable JSON instead of a table}')]
#[Description('Audit customer_orders.paid_amount against the canonical order-payment ledger projection; report-only')]
class CheckLedgerDrift extends Command
{
    public function handle(LedgerDriftScanner $scanner): int
    {
        $organizationId = $this->option('organization');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $result = $scanner->scan($organizationId, $limit);

        return $this->report($result);
    }

    /**
     * @param  array{
     *     inspected: int,
     *     drift_count: int,
     *     gap_payments: int,
     *     findings: list<array{order_id: string, kind: string, expected: float, actual: float, status: string}>
     * }  $result
     */
    private function report(array $result): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'inspected' => $result['inspected'],
                'drift_count' => $result['drift_count'],
                'gap_payments' => $result['gap_payments'],
                'findings' => $result['findings'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $result['findings'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Inspected {$result['inspected']} order(s).");

        if ($result['gap_payments'] > 0) {
            $this->comment("{$result['gap_payments']} succeeded sale row(s) have no till attribution (Plan 044 gap payments — informational).");
        }

        if ($result['findings'] === []) {
            $this->info('No ledger drift found. paid_amount ties out to the canonical projection on every order.');

            return self::SUCCESS;
        }

        // The scan is worth nothing if only a terminal sees it. DevOps alerting
        // matches ERROR-level entries by their `[...]` prefix (see the docblock
        // on CheckTillsSchedulerFreshness), so drift now reaches the same path
        // every other money fault uses instead of scrolling past in a cron log.
        //
        // The findings list is capped in the log line: the alert only has to say
        // "drift exists, here is where to start" — the full list is one command
        // away, and an unbounded payload helps nobody at 3am.
        Log::error('[payments.ledger_drift] ledger_drift_detected', [
            'inspected' => $result['inspected'],
            'drift_count' => $result['drift_count'],
            'gap_payments' => $result['gap_payments'],
            'sample' => array_slice($result['findings'], 0, 10),
        ]);

        $this->error(count($result['findings']).' order(s) with ledger drift:');
        $this->table(
            ['Order', 'Kind', 'Expected', 'Actual', 'Status'],
            array_map(fn (array $f) => [
                $f['order_id'],
                $f['kind'],
                number_format($f['expected'], 2),
                number_format($f['actual'], 2),
                $f['status'],
            ], $result['findings']),
        );

        return self::FAILURE;
    }
}
