<?php

namespace App\Console\Commands;

use App\Services\Payment\Observation\PaymentObservationReporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:observation-report {--organization= : Restrict the ledger drift scan to one organization_id} {--ledger-limit= : Stop ledger scan after inspecting this many orders} {--json : Emit machine-readable JSON instead of a table} {--strict : Fail when shadow reconciliation backlog is non-zero in addition to ledger drift}')]
#[Description('Aggregate ledger drift, shadow backlog, refund pending, and open till sessions for Gate 7 observation (plan-047 T7.4)')]
final class PaymentObservationReport extends Command
{
    public function handle(PaymentObservationReporter $reporter): int
    {
        $organizationId = $this->option('organization');
        $ledgerLimit = $this->option('ledger-limit') !== null
            ? max(1, (int) $this->option('ledger-limit'))
            : null;
        $strict = (bool) $this->option('strict');

        $report = $reporter->report($organizationId, $ledgerLimit, $strict);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $report['gate_pass'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Payment orchestration observation window');
        $this->line('  Generated at: '.$report['generated_at']);
        $this->newLine();

        $this->table(
            ['Signal', 'Metric', 'Count'],
            [
                ['Ledger drift', 'Orders inspected', (string) $report['ledger']['inspected']],
                ['Ledger drift', 'Drift findings', (string) $report['ledger']['drift_count']],
                ['Ledger drift', 'Gap payments (info)', (string) $report['ledger']['gap_payments']],
                ['Shadow backlog', 'reconciliation_required attempts', (string) $report['shadow_backlog']['attempt_candidates']],
                ['Shadow backlog', 'reconciliation_required refunds', (string) $report['shadow_backlog']['refund_candidates']],
                ['Refund pending', 'prepared / submitted / pending / recon', sprintf(
                    '%d / %d / %d / %d',
                    $report['refund_pending']['prepared'],
                    $report['refund_pending']['submitted'],
                    $report['refund_pending']['pending'],
                    $report['refund_pending']['reconciliation_required'],
                )],
                ['Refund pending', 'Total in-flight', (string) $report['refund_pending']['total']],
                ['Till sessions', 'open / closing', sprintf(
                    '%d / %d',
                    $report['till_sessions_open']['open'],
                    $report['till_sessions_open']['closing'],
                )],
                ['Till sessions', 'Total active', (string) $report['till_sessions_open']['total']],
                // plan-054 T7.4. `stale` is the one to watch: past the sweep
                // grace, so payments:sweep-paypay-qr should already have
                // resolved it. A stale count that does not fall means the
                // sweep is not running, and an unswept QR is money PayPay may
                // have taken that is not in the ledger.
                ['PayPay QR', 'Live codes outstanding', (string) $report['paypay_qr']['live']],
                ['PayPay QR', sprintf('Stale (> %dm, sweep overdue)', $report['paypay_qr']['grace_minutes']), (string) $report['paypay_qr']['stale']],
            ],
        );

        $this->newLine();
        $this->comment('Orchestrator runtime: '.($report['orchestrator_runtime']['enabled'] ? 'enabled' : 'disabled'));
        $this->comment('Allowlist transports: '.implode(', ', $report['orchestrator_runtime']['transports']) ?: '(none)');

        foreach ($report['orchestrator_runtime']['transport_switches'] as $transport => $enabled) {
            $this->line(sprintf('  transport %s: %s', $transport, $enabled ? 'ON' : 'OFF'));
        }

        if ($report['gate_pass']) {
            $this->info('Observation gate PASS — ledger drift is zero'.($strict ? ' and shadow backlog is clear.' : '.'));

            return self::SUCCESS;
        }

        if ($report['ledger']['drift_count'] > 0) {
            $this->error('Observation gate FAIL — ledger drift detected (zero-tolerance).');
        }

        if ($strict && $report['shadow_backlog']['total'] > 0) {
            $this->error('Observation gate FAIL — shadow reconciliation backlog is non-zero (--strict).');
        }

        return self::FAILURE;
    }
}
