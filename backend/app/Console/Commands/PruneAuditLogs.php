<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * #2555 — the retention sweep `audit_logs` never had.
 *
 * `AuditsActivity::logAudit()` is the chokepoint every audited write passes
 * through and nothing ever deleted a row, so the table grew for the lifetime of
 * the deployment. That is a PCI finding on one side (Req 10.5.1 asks for a
 * retention POLICY, and "keep everything forever" is not one) and a privacy
 * problem on the other — #2554 removed the caller `ip` precisely because there
 * was no horizon to bound it. This command is what unblocks both.
 *
 * Window and bounds live in `config/audit.php`; see that file for why 400 days.
 * The command refuses to run below the PCI floor, so the tunable is one-way.
 *
 * WHY CHUNKED. A single `DELETE FROM audit_logs WHERE created_at < ?` on a
 * table that has only ever grown holds locks across an unbounded range. This
 * fetches primary keys in `chunk_size` batches and deletes by key, pausing
 * between batches, and stops at whichever of `max_rows` / `max_seconds` comes
 * first. Stopping early is a normal outcome, not a failure: the cutoff is
 * recomputed from the clock on the next run, so the backlog drains over
 * several nights instead of in one lock-heavy transaction. That is also the
 * answer to this repo's standing question — "is it safe at 14:30 on a busy
 * Saturday?" — yes, because no single run can exceed a few hundred keyed
 * deletes per second for a bounded number of seconds.
 *
 * `--dry-run` counts and prints without deleting. `--measure` adds a COUNT(*)
 * of the whole table before and after (the "measure the table before/after"
 * the issue asks for); it is opt-in because that count is a full scan on a
 * table this size.
 *
 * The cutoff compares `created_at` in UTC. Deliberate, and NOT a #1091
 * violation: a storage horizon is elapsed time, not a business date — no
 * branch's timezone changes how long a log row must be kept.
 *
 * Scheduled nightly in `routes/console.php` (`audit.prune`).
 */
#[Signature('audit:prune
    {--dry-run : Count what would be deleted and print it, without deleting anything}
    {--measure : COUNT(*) the whole table before and after (extra full scan)}
    {--days= : Override the retention window in days (still refused below the PCI floor)}
    {--chunk= : Rows deleted per batch}
    {--max-rows= : Hard ceiling of rows deleted in this run}
    {--max-seconds= : Wall-clock budget for this run}')]
#[Description('Delete audit_logs rows older than the configured retention window, in bounded chunks (#2555)')]
class PruneAuditLogs extends Command
{
    public function handle(): int
    {
        $days = $this->intOption('days') ?? (int) config('audit.retention_days');
        $floor = (int) config('audit.pci_floor_days');

        if ($days < $floor) {
            $this->error("Refusing to prune: retention window is {$days} day(s), below the PCI DSS v4.0 Req 10.5.1 floor of {$floor} days (12 months).");
            $this->line('Raise AUDIT_LOG_RETENTION_DAYS (or --days) instead of lowering the floor. Nothing was deleted.');

            return self::FAILURE;
        }

        $chunkSize = max(1, $this->intOption('chunk') ?? (int) config('audit.prune.chunk_size'));
        $maxRows = max(0, $this->intOption('max-rows') ?? (int) config('audit.prune.max_rows'));
        $maxSeconds = max(1, $this->intOption('max-seconds') ?? (int) config('audit.prune.max_seconds'));
        $pauseMs = max(0, (int) config('audit.prune.pause_ms'));

        $cutoff = Carbon::now('UTC')->subDays($days);
        $dryRun = (bool) $this->option('dry-run');
        $measure = (bool) $this->option('measure');

        $this->line("audit:prune — retention {$days}d, cutoff {$cutoff->toIso8601String()} (UTC, created_at)");

        $rowsBefore = $measure ? AuditLog::query()->count() : null;

        if ($dryRun) {
            $eligible = $this->eligible($cutoff)->count();

            $this->line("dry-run: {$eligible} row(s) older than the cutoff would be deleted".
                ($maxRows > 0 && $eligible > $maxRows ? " (this run would stop at max-rows={$maxRows})" : '').'.');

            if ($rowsBefore !== null) {
                $this->line("table rows: {$rowsBefore} (unchanged — dry run)");
            }

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $deleted = 0;
        $batches = 0;
        $stoppedBy = 'drained';

        while (true) {
            if ($maxRows > 0 && $deleted >= $maxRows) {
                $stoppedBy = 'max-rows';
                break;
            }

            if (microtime(true) - $startedAt >= $maxSeconds) {
                $stoppedBy = 'max-seconds';
                break;
            }

            $take = $maxRows > 0 ? min($chunkSize, $maxRows - $deleted) : $chunkSize;

            $ids = $this->eligible($cutoff)
                ->orderBy('created_at')
                ->limit($take)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            // Delete by primary key: the SELECT above decided the set, so the
            // write touches nothing the scan did not already agree to.
            $deleted += AuditLog::query()->whereIn('id', $ids)->delete();
            $batches++;

            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $duration = round(microtime(true) - $startedAt, 2);
        $remaining = $this->eligible($cutoff)->count();

        $this->line("deleted {$deleted} row(s) in {$batches} batch(es) in {$duration}s; stopped: {$stoppedBy}");
        $this->line("still older than cutoff: {$remaining} row(s)");

        if ($rowsBefore !== null) {
            $rowsAfter = AuditLog::query()->count();
            $this->line("table rows: {$rowsBefore} before, {$rowsAfter} after");
        }

        Log::info('[audit.prune] run', [
            'retention_days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'deleted' => $deleted,
            'batches' => $batches,
            'stopped_by' => $stoppedBy,
            'remaining_eligible' => $remaining,
            'duration_seconds' => $duration,
        ]);

        if ($stoppedBy !== 'drained') {
            $this->warn("Bound reached ({$stoppedBy}) with {$remaining} eligible row(s) left. The next run continues from a freshly computed cutoff.");
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<AuditLog>
     */
    private function eligible(Carbon $cutoff): Builder
    {
        return AuditLog::query()->where('created_at', '<', $cutoff);
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return ($value === null || $value === '') ? null : (int) $value;
    }
}
