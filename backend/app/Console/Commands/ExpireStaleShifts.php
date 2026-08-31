<?php

namespace App\Console\Commands;

use App\Models\OrderPayment;
use App\Models\TillSession;
use App\Omnify\Enums\ExpireReasonEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Plan-032 — Stale Shift Reaper.
 *
 * Sweeps open/closing TillSessions whose `opened_at` is older than the
 * configured threshold AND have NO recent OrderPayment activity. For each
 * matching session, calls TillSessionService::expire() which re-checks the
 * activity window INSIDE its locked transaction (Decision 10) so a payment
 * landing between this outer SELECT and the inner UPDATE results in a
 * skip-no-op, not an orphaned payment on an expired session.
 *
 * Scheduling lives in routes/console.php (hourly, withoutOverlapping(5),
 * onOneServer). Mutex TTL deliberately tightened from the workflow's
 * default to keep the blast radius small if this command crashes between
 * mutex-acquire and mutex-release.
 *
 * Heartbeat: writes `pos.tills.last_run_at` to the cache at the END of a
 * successful run (Redis-backed in production, TTL 24h). The
 * `tills:check-scheduler-freshness` command reads this and alerts when
 * stale > 6h.
 *
 * Observability (Phase 5.5 / Decision 12, log-based since Sentry isn't
 * wired in this project):
 *   INFO  `[pos.till] expire-run` per run with counters
 *   INFO  `[pos.till] expire-skipped` per session that hit the in-tx race
 *   WARN  `[pos.till] expire-spike` when expired > 20 in one tick
 *   WARN  `[pos.till] expire-slow` when duration > 5 min
 */
#[Signature('tills:expire-stale-shifts {--dry-run : List candidates without flipping any session}')]
#[Description('Flip open/closing till sessions to expired when stale beyond the configured threshold with no recent activity (plan-032)')]
class ExpireStaleShifts extends Command
{
    public function __construct(
        private readonly TillSessionService $sessions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);
        $thresholdHours = (int) config('pos.shift.stale_timeout_hours', 48);
        $activityWindowHours = (int) config('pos.shift.stale_activity_window_hours', 6);
        $dryRun = (bool) $this->option('dry-run');

        $cutoffOpened = now()->subHours($thresholdHours);
        $activityCutoff = now()->subHours($activityWindowHours);

        $candidates = 0;
        $expired = 0;
        $skippedConcurrent = 0;
        $skippedTerminal = 0;

        try {
            TillSession::query()
                ->whereIn('status', [
                    TillSessionStatusEnum::Open->value,
                    TillSessionStatusEnum::Closing->value,
                ])
                ->where('opened_at', '<', $cutoffOpened)
                ->whereNotExists(function ($query) use ($activityCutoff) {
                    $query->select('id')
                        ->from('order_payments')
                        ->whereColumn('order_payments.till_session_id', 'till_sessions.id')
                        ->where('order_payments.created_at', '>=', $activityCutoff);
                })
                ->orderBy('opened_at')
                ->chunkById(100, function ($chunk) use (
                    &$candidates, &$expired, &$skippedConcurrent, &$skippedTerminal,
                    $thresholdHours, $activityWindowHours, $dryRun
                ) {
                    foreach ($chunk as $session) {
                        $candidates++;

                        if ($dryRun) {
                            $this->line(sprintf(
                                '[dry-run] would expire session=%s opened_at=%s threshold=%dh',
                                $session->session_code,
                                $session->opened_at?->toIso8601String(),
                                $thresholdHours,
                            ));

                            continue;
                        }

                        $result = $this->sessions->expire(
                            $session,
                            ExpireReasonEnum::NoActivity->value,
                            $thresholdHours,
                            $activityWindowHours,
                        );

                        if ($result === null) {
                            // The service's in-transaction re-check returned null
                            // for one of two reasons: concurrent payment landed
                            // in the window (race) OR status already terminal
                            // (idempotent re-tick). We don't have a finer signal
                            // back from the service in v1; lump them together as
                            // "skipped". The service logs the per-row reason
                            // separately so audit can disambiguate.
                            $skippedConcurrent++;

                            continue;
                        }
                        $expired++;
                    }
                });

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $this->emitSummary([
                'candidates' => $candidates,
                'expired' => $expired,
                'skipped_concurrent' => $skippedConcurrent,
                'skipped_terminal' => $skippedTerminal,
                'duration_ms' => $durationMs,
                'threshold_hours' => $thresholdHours,
                'activity_window_hours' => $activityWindowHours,
                'dry_run' => $dryRun,
            ]);

            if (! $dryRun) {
                // Heartbeat: only set on SUCCESSFUL completion. A crash before
                // we reach this point leaves the key stale, which is exactly
                // what the freshness-check command needs to surface.
                Cache::put('pos.tills.last_run_at', now()->toIso8601String(), now()->addDay());
            }

            return self::SUCCESS;
        } finally {
            // The withoutOverlapping(5) mutex registered in routes/console.php
            // is the primary release path. This block is here as a defensive
            // marker if a future refactor wraps `handle()` differently — keep
            // the try/finally even when the body has no explicit cleanup so
            // the contract stays obvious.
        }
    }

    /**
     * Emit the per-run structured log lines + spike/slow warnings.
     *
     * @param  array{candidates:int,expired:int,skipped_concurrent:int,skipped_terminal:int,duration_ms:int,threshold_hours:int,activity_window_hours:int,dry_run:bool}  $counters
     */
    private function emitSummary(array $counters): void
    {
        Log::info('[pos.till] expire-run', $counters);

        if ($counters['expired'] > 20) {
            Log::warning('[pos.till] expire-spike', [
                'expired' => $counters['expired'],
                'threshold_hours' => $counters['threshold_hours'],
                'dry_run' => $counters['dry_run'],
            ]);
        }

        if ($counters['duration_ms'] > 300_000) {
            Log::warning('[pos.till] expire-slow', [
                'duration_ms' => $counters['duration_ms'],
                'candidates' => $counters['candidates'],
            ]);
        }

        if ($counters['expired'] > 0 || $counters['candidates'] > 0) {
            $this->info(sprintf(
                'tills:expire-stale-shifts — candidates=%d expired=%d skipped=%d duration=%dms%s',
                $counters['candidates'],
                $counters['expired'],
                $counters['skipped_concurrent'] + $counters['skipped_terminal'],
                $counters['duration_ms'],
                $counters['dry_run'] ? ' (dry-run)' : '',
            ));
        }
    }
}
