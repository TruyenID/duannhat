<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Plan-032 T5.5b — Freshness alert for the `tills:expire-stale-shifts`
 * scheduler heartbeat.
 *
 * Reads cache key `pos.tills.last_run_at` (set by ExpireStaleShifts on
 * successful run, TTL 24h). When the value is older than 6h — OR missing
 * entirely (scheduler never ran since cache restart, key TTL expired) —
 * emits an ERROR log line tagged `[pos.till] scheduler-stale`.
 *
 * Sentry is NOT wired in this project (verified 2026-06-10 — see
 * plan-032 NOTES (đã xoá #2188 — git history)). DevOps alerting routes ERROR-level entries
 * tagged `[pos.till]` from LOG_CHANNEL=stack to whatever alerting backend
 * is configured (Loki / CloudWatch / etc.). If Sentry lands in a later
 * plan, swap to `Sentry::captureMessage` here alongside the Log::error
 * call — the tag contract stays identical so alerting rules don't migrate.
 *
 * Scheduled at hourlyAt(15) past the hour so a slow ExpireStaleShifts run
 * still has 45min of headroom before this fires.
 */
#[Signature('tills:check-scheduler-freshness')]
#[Description('Emit a tagged ERROR log when the tills:expire-stale-shifts heartbeat is stale (>6h) — plan-032 T5.5b')]
class CheckTillsSchedulerFreshness extends Command
{
    /** Heartbeat staleness threshold. */
    private const STALE_AFTER_HOURS = 6;

    public function handle(): int
    {
        $lastRunRaw = Cache::get('pos.tills.last_run_at');

        if ($lastRunRaw === null) {
            Log::error('[pos.till] scheduler-stale', [
                'last_run_at' => null,
                'stale_for_hours' => null,
                'staleness_threshold_hours' => self::STALE_AFTER_HOURS,
                'reason' => 'heartbeat_missing',
            ]);
            $this->warn('Scheduler heartbeat missing — emitted scheduler-stale alert.');

            return self::SUCCESS;
        }

        $lastRun = Carbon::parse($lastRunRaw);
        $staleHours = $lastRun->diffInHours(now());

        if ($staleHours >= self::STALE_AFTER_HOURS) {
            Log::error('[pos.till] scheduler-stale', [
                'last_run_at' => $lastRun->toIso8601String(),
                'stale_for_hours' => $staleHours,
                'staleness_threshold_hours' => self::STALE_AFTER_HOURS,
                'reason' => 'heartbeat_too_old',
            ]);
            $this->warn(sprintf(
                'Scheduler heartbeat is %dh old (threshold %dh) — emitted scheduler-stale alert.',
                $staleHours,
                self::STALE_AFTER_HOURS,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Scheduler heartbeat is fresh (last run %dh ago).',
            $staleHours,
        ));

        return self::SUCCESS;
    }
}
