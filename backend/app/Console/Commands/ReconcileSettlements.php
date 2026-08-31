<?php

namespace App\Console\Commands;

use App\Services\Payment\Settlement\SettlementAgingReportService;
use App\Services\Payment\Settlement\SettlementAlertService;
use App\Services\Payment\Settlement\SettlementReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Plan-050 M4 (T4.1/T4.2, #1155) — daily two-direction gateway settlement
 * reconciliation + pending-payout aging.
 *
 * Direction A: succeeded gateway payments past the provider window with no
 * settlement row ("gateway never reported this money" — also detects a
 * missing webhook subscription, G6). Direction B: orphan settlement rows and
 * mismatch payouts (S-12). Orphans are auto re-matched first (S-05/S-19).
 *
 * Idempotent: invoking twice back-to-back changes no state (S-23). The only
 * mutation is orphan re-matching, which --dry-run suppresses.
 */
#[Signature('settlements:reconcile {--connection= : Limit to one payment gateway connection id} {--dry-run : Report only — skip orphan re-match writes}')]
#[Description('Two-direction gateway settlement reconciliation + aging report (plan-050)')]
final class ReconcileSettlements extends Command
{
    public function handle(
        SettlementReconciliationService $reconciliation,
        SettlementAgingReportService $aging,
        SettlementAlertService $alerts,
    ): int {
        $lock = Cache::lock('settlements:reconcile', 300);

        if (! $lock->get()) {
            $this->warn('Another settlements:reconcile run is already in progress.');

            return self::SUCCESS;
        }

        try {
            $connectionId = $this->option('connection') !== null && $this->option('connection') !== ''
                ? (string) $this->option('connection')
                : null;
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->warn('Dry-run: orphan re-matching is previewed, nothing is written.');
            }

            $report = $reconciliation->reconcile($connectionId, $dryRun);

            $this->renderSection('Direction A — succeeded payments with NO settlement row (gateway silent past window)',
                ['Attempt', 'Order', 'Provider', 'Amount (minor)', 'Currency', 'Finalized', 'Age (d)'],
                array_map(static fn (array $row): array => [
                    $row['attempt_id'],
                    $row['order_id'],
                    $row['provider'],
                    $row['amount_minor'],
                    $row['currency'],
                    $row['finalized_at'] ?? '-',
                    $row['age_days'] ?? '-',
                ], $report['unsettled_payments']));

            $this->renderSection('Orphans re-matched this run (S-05/S-19)',
                ['Settlement', 'External ref', 'Attempt', 'Net (minor)', 'Currency', 'Dry-run'],
                array_map(static fn (array $row): array => [
                    $row['settlement_id'],
                    $row['external_ref'],
                    $row['attempt_id'],
                    $row['net_minor'],
                    $row['currency'],
                    $row['dry_run'] ? 'yes' : 'no',
                ], $report['rematched']));

            $this->renderSection('Direction B — orphan settlement rows (gateway has money we cannot match)',
                ['Settlement', 'Provider', 'Kind', 'External ref', 'Net (minor)', 'Currency', 'Settled at'],
                array_map(static fn (array $row): array => [
                    $row['settlement_id'],
                    $row['provider'],
                    $row['kind'],
                    $row['external_ref'],
                    $row['net_minor'],
                    $row['currency'],
                    $row['provider_settled_at'] ?? '-',
                ], $report['orphans']));

            $this->renderSection('Direction B — mismatch payouts (Σ settlements ≠ payout net, S-12)',
                ['Payout', 'Provider', 'External payout', 'Net (minor)', 'Currency', 'Expected', 'Attached'],
                array_map(static fn (array $row): array => [
                    $row['payout_id'],
                    $row['provider'],
                    $row['external_payout_id'],
                    $row['net_minor'],
                    $row['currency'],
                    $row['mismatch']['expected_net_minor'] ?? '-',
                    $row['mismatch']['attached_net_minor'] ?? '-',
                ], $report['mismatch_payouts']));

            $this->renderSection('Direction B — mismatch settlement rows (currency / integrity flags)',
                ['Settlement', 'Provider', 'Kind', 'External ref', 'Net (minor)', 'Currency'],
                array_map(static fn (array $row): array => [
                    $row['settlement_id'],
                    $row['provider'],
                    $row['kind'],
                    $row['external_ref'],
                    $row['net_minor'],
                    $row['currency'],
                ], $report['mismatch_rows']));

            $agingReport = $aging->pendingPayoutAging($connectionId);
            $this->newLine();
            $this->info('Pending-payout aging per connection (money still at the gateway)');
            if ($agingReport === []) {
                $this->line('  (nothing pending)');
            } else {
                foreach ($agingReport as $entry) {
                    $this->line(sprintf(
                        '  connection=%s provider=%s currency=%s total=%d rows=%d oldest=%sd%s',
                        $entry['connection_id'],
                        $entry['provider'],
                        $entry['currency'],
                        $entry['total_net_minor'],
                        $entry['row_count'],
                        $entry['oldest_age_days'] ?? '-',
                        $entry['over_threshold'] ? '  ⚠ OVER THRESHOLD' : '',
                    ));
                    foreach ($entry['buckets'] as $label => $bucket) {
                        if ($bucket['row_count'] > 0) {
                            $this->line(sprintf('    %-8s net=%d rows=%d', $label, $bucket['net_minor'], $bucket['row_count']));
                        }
                    }
                }
            }

            Log::channel('payment_orchestration')->info('settlements_reconcile_tick', [
                'connection_id' => $connectionId,
                'dry_run' => $dryRun,
                'unsettled_payment_count' => count($report['unsettled_payments']),
                'rematched_count' => count($report['rematched']),
                'orphan_count' => count($report['orphans']),
                'mismatch_payout_count' => count($report['mismatch_payouts']),
                'mismatch_row_count' => count($report['mismatch_rows']),
            ]);

            $findings = count($report['unsettled_payments'])
                + count($report['orphans'])
                + count($report['mismatch_payouts'])
                + count($report['mismatch_rows']);

            $this->newLine();
            $this->info($findings === 0
                ? 'Settlement reconciliation clean — no discrepancies.'
                : "Settlement reconciliation found {$findings} open discrepancy(ies).");

            // T4.3 — cảnh báo chạy SAU khi mọi thứ đã in ra, và cố ý là bước
            // cuối: đối soát là thứ ghi dữ liệu, cảnh báo chỉ nói về nó. Hỏng
            // ở đây không được làm hỏng lượt đối soát.
            //
            // KHÔNG gửi trong `--dry-run`. Dry-run tồn tại để xem trước mà
            // không gây tác dụng phụ, mà gửi thông báo cho một tổ chức thật thì
            // đúng là tác dụng phụ — và là loại không rút lại được.
            if (! $dryRun && (bool) config('payments.settlement.alerts_enabled', true)) {
                $raised = $alerts->raise($report, $agingReport);

                $this->renderSection('Alerts raised (T4.3)',
                    ['Type', 'Connection', 'Rows', 'Sent', 'Reason'],
                    array_map(static fn (array $row): array => [
                        $row['type'],
                        $row['connection_id'],
                        (string) $row['count'],
                        $row['sent'] ? 'yes' : 'no',
                        $row['reason'] ?? '-',
                    ], $raised));
            } elseif ($dryRun) {
                $this->line('Alerts skipped (dry-run).');
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    private function renderSection(string $title, array $headers, array $rows): void
    {
        $this->newLine();
        $this->info($title);

        if ($rows === []) {
            $this->line('  (none)');

            return;
        }

        $this->table($headers, $rows);
    }
}
