<?php

namespace App\Console\Commands;

use App\Models\TillSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Plan-032 T5.5d — Fraud-signal alert on per-manager force-abandon rate.
 *
 * Aggregates `till_sessions.force_abandoned_by_id` over the trailing window
 * (default 7d). For any manager whose count exceeds the alert threshold
 * (default 5), emits a WARNING log line tagged
 * `[pos.till] force-abandon-rate` with `kind=fraud-signal`.
 *
 * Plan-032 design Decision 12: a manager force-abandoning 6 times in 7 days
 * may be benign (4 device failures at one branch are also possible) or may
 * be hiding cash variance. The dashboard widget (T5.5c) lets the auditor
 * drill down; this command is the unattended fraud watchdog.
 *
 * Sentry NOT wired in project — same log-tag contract as T5.5b.
 *
 * Scheduled dailyAt(06:00 Asia/Tokyo) so the operator gets the signal
 * BEFORE morning shift starts.
 */
#[Signature('tills:check-force-abandon-rate')]
#[Description('Emit a tagged WARNING log when any manager exceeds the force-abandon rate threshold in the trailing window — plan-032 T5.5d')]
class CheckForceAbandonRate extends Command
{
    public function handle(): int
    {
        $threshold = (int) config('pos.shift.force_abandon_rate_alert_count', 5);
        $windowDays = (int) config('pos.shift.force_abandon_rate_window_days', 7);

        $rows = TillSession::query()
            ->select('force_abandoned_by_id')
            ->selectRaw('COUNT(*) AS abandon_count')
            ->where('force_abandoned', true)
            ->whereNotNull('force_abandoned_by_id')
            ->where('abandoned_at', '>=', now()->subDays($windowDays))
            ->groupBy('force_abandoned_by_id')
            ->havingRaw('COUNT(*) > ?', [$threshold])
            ->get();

        if ($rows->isEmpty()) {
            $this->info(sprintf(
                'No managers exceeded the force-abandon rate threshold (> %d in %dd).',
                $threshold,
                $windowDays,
            ));

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            Log::warning('[pos.till] force-abandon-rate', [
                'manager_id' => $row->force_abandoned_by_id,
                'count' => (int) $row->abandon_count,
                'window_days' => $windowDays,
                'threshold' => $threshold,
                'kind' => 'fraud-signal',
            ]);
        }

        $this->warn(sprintf(
            'Emitted %d fraud-signal alert(s) for managers exceeding %d force-abandons in %dd.',
            $rows->count(),
            $threshold,
            $windowDays,
        ));

        return self::SUCCESS;
    }
}
