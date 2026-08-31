<?php

namespace App\Console\Commands;

use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\PrintJobAgingService;
use App\Services\Printing\PrintJobReconciler;
use App\Services\Printing\PrintPipelineAlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * plan-052 M2 / T2.1 — `print-jobs:reconcile`.
 *
 * Reads the print ledger and answers the question no screen answered before
 * M1: *is anything in this shop failing to come out on paper, and since when?*
 *
 * What it will NOT do, on any code path (DESIGN §1b + RISKS PR1):
 *
 *   - schedule, retry or transition a `ws_lan` row. The workstation owns that
 *     queue. Cloud counts and reports; that is the whole contract.
 *   - re-send a money document. There is no dispatch in this command at all.
 *
 * On the Cloud-owned side (cloudprnt today, epos/webprnt in M3) it applies the
 * TTL table from `config/print_jobs.php` and flags the ACK-lost rows, then
 * hands the actionable ones to the alert sweep.
 *
 * NOTE on options: plan-052 T2.1 sketched `--connection=`. In the printing
 * domain "connection" already means `printers.connection_type` (network / usb),
 * which is not the axis this sweep cares about — queue OWNERSHIP is, and that
 * is `transport`. The option is named accordingly.
 */
#[Signature('print-jobs:reconcile
    {--branch= : Limit the sweep to one branch id}
    {--transport= : Limit to one transport (ws_lan|epos_http|webprnt|cloudprnt)}
    {--dry-run : Report only — write nothing, alert nobody}
    {--no-alerts : Reconcile and report without running the alert sweep}')]
#[Description('Reconcile the print_jobs ledger: report ws_lan journal findings, expire Cloud-owned jobs past TTL, alert on silent printers and stuck money documents (plan-052 T2.1/T2.3)')]
class ReconcilePrintJobs extends Command
{
    public function handle(
        PrintJobReconciler $reconciler,
        PrintJobAgingService $aging,
        PrintPipelineAlertService $alerts,
    ): int {
        $branchId = $this->stringOption('branch');
        $transport = $this->stringOption('transport');
        $dryRun = (bool) $this->option('dry-run');

        if ($transport !== null && PrintTransport::tryFrom($transport) === null) {
            $this->error("Unknown transport [{$transport}]. Expected one of: "
                .implode(', ', array_map(static fn (PrintTransport $t): string => $t->value, PrintTransport::cases())));

            return self::INVALID;
        }

        // The scheduler already guards overlap; this caps a manual run racing
        // the cron one, which is the case that actually happens.
        $lock = Cache::lock('print-jobs:reconcile', 300);

        if (! $lock->get()) {
            $this->warn('Another print-jobs:reconcile run is already in progress.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);

        try {
            if ($dryRun) {
                $this->warn('Dry-run: nothing is written and no alert is sent.');
            }

            $report = $reconciler->reconcile([
                'branch_id' => $branchId,
                'transport' => $transport,
                'dry_run' => $dryRun,
            ]);

            $this->renderReport($report, $aging);

            $alertResult = ['emitted' => [], 'suppressed' => [], 'cleared' => []];

            if (! $dryRun && ! (bool) $this->option('no-alerts')) {
                $alertResult = $alerts->sweep($branchId);
                $this->renderAlerts($alertResult);
            }

            if (! $dryRun) {
                // Heartbeat, same shape as `pos.tills.last_run_at` — a sweep
                // that silently stopped running looks exactly like a shop with
                // no problems, which is the worst way for it to fail.
                Cache::put('printing.reconcile.last_run_at', now()->toIso8601String(), now()->addDay());
            }

            Log::info('[printing] reconcile-run', [
                'branch_id' => $branchId,
                'transport' => $transport,
                'dry_run' => $dryRun,
                'scanned' => $report['scanned'],
                'changed' => count($report['changes']),
                'findings' => count($report['findings']),
                'errors' => count($report['errors']),
                'alerts_emitted' => count($alertResult['emitted']),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report, PrintJobAgingService $aging): void
    {
        /** @var list<array<string, mixed>> $branches */
        $branches = $report['branches'];

        $this->renderSection(
            'Per-branch summary',
            ['Branch', 'Scanned', 'ws_lan needs_att', 'ws_lan stuck', 'ws_lan past TTL', 'late sync', 'no-reason reprint', 'cloud expired', 'cloud needs_att'],
            array_map(static fn (array $b): array => [
                $b['branch_name'],
                $b['scanned'],
                $b['journal_needs_attention'],
                $b['journal_stale_delivering'],
                $b['journal_past_ttl_open'],
                $b['journal_late_sync'],
                $b['journal_money_reprint_no_reason'],
                $b['cloud_expired'],
                $b['cloud_needs_attention'],
            ], $branches),
        );

        /** @var list<array<string, mixed>> $changes */
        $changes = $report['changes'];

        $this->renderSection(
            $report['dry_run'] ? 'Cloud-owned transitions (WOULD apply)' : 'Cloud-owned transitions (applied)',
            ['Job', 'Transport', 'Kind', 'From', 'To', 'Reason'],
            array_map(static fn (array $c): array => [
                $c['job_id'], $c['transport'], $c['kind'], $c['from'], $c['to'], $c['reason'],
            ], $changes),
        );

        /** @var list<array<string, mixed>> $findings */
        $findings = $report['findings'];

        $this->renderSection(
            'ws_lan findings — REPORT ONLY (the workstation owns that queue, DESIGN §1b)',
            ['Job', 'Kind', 'Status', 'Finding'],
            array_map(static fn (array $f): array => [
                $f['job_id'], $f['kind'], $f['status'], $f['code'],
            ], $findings),
        );

        foreach ($branches as $branch) {
            $agingReport = $aging->branchAging((string) $branch['branch_id']);

            if ($agingReport['total'] === 0) {
                continue;
            }

            $this->newLine();
            $this->info("Open-job aging — {$branch['branch_name']}");

            foreach ($agingReport['buckets'] as $label => $count) {
                $this->line(sprintf('  %-8s %d', $label, $count));
            }
        }

        /** @var list<array<string, mixed>> $errors */
        $errors = $report['errors'] ?? [];

        if ($errors !== []) {
            $this->renderSection(
                'Branches that failed to reconcile (the sweep continued)',
                ['Branch', 'Exception', 'Message'],
                array_map(static fn (array $e): array => [$e['branch_id'], $e['exception'], $e['message']], $errors),
            );
        }
    }

    /**
     * @param  array{emitted: list<array<string, mixed>>, suppressed: list<array<string, mixed>>, cleared: list<string>}  $alerts
     */
    private function renderAlerts(array $alerts): void
    {
        $this->renderSection(
            'Alerts sent',
            ['Type', 'Detail'],
            array_map(static fn (array $a): array => [
                $a['type'],
                json_encode(array_diff_key($a, ['type' => true]), JSON_UNESCAPED_UNICODE) ?: '',
            ], $alerts['emitted']),
        );

        if ($alerts['suppressed'] !== []) {
            $this->line(sprintf('  (%d alert(s) suppressed by the debounce window)', count($alerts['suppressed'])));
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
