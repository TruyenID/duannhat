<?php

namespace App\Console\Commands;

use App\Models\MaterialLot;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Support\BusinessClock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Plan-022 T6 — daily 08:00 Asia/Tokyo lot expiry sweeper.
 *
 * Flips active / quarantined lots whose expiry_date is in the past to
 * `expired` so FEFO stops picking them and the operator gets a forced
 * dispose decision. Runs 1h after ExpiryAlertService (07:00) so ops sees
 * the warning notification first then the lot transitions automatically.
 *
 * Idempotent — re-running the same day flips zero additional rows.
 */
#[Signature('material-lots:auto-expire')]
#[Description('Plan-022 T6 — flip active/quarantined material lots past expiry to expired status.')]
class AutoExpireMaterialLots extends Command
{
    public function handle(): int
    {
        $defaultTz = config('app.default_branch_timezone', 'Asia/Tokyo');

        // plan-040 TF.6 (M18): the expire decision uses each lot's BRANCH
        // timezone, not the app default. A coarse `<=` default-tz pre-filter
        // (widened a day vs the old `<`) keeps the candidate set small while the
        // per-branch check below makes the actual day-boundary call.
        $expiredIds = [];

        MaterialLot::query()
            ->whereIn('status', [
                MaterialLotStatusEnum::Active->value,
                MaterialLotStatusEnum::Quarantined->value,
            ])
            ->whereNotNull('expiry_date')
            // Coarse SQL net, then an exact per-branch decision below. The net
            // is widened by a day because a branch AHEAD of the default zone
            // reaches "today" first — without the margin its lot would be
            // filtered out before the per-branch check ever saw it (#1091).
            ->whereDate('expiry_date', '<=', today($defaultTz)->addDay()) // #1091-ok: coarse net, exact check per branch below
            ->with('warehouse:id,branch_id')
            ->chunkById(500, function ($lots) use (&$expiredIds): void {
                // #1161 — one `branches` read per CHUNK, not per lot. The old
                // hand-rolled `$lot->warehouse?->branch?->timezone` copy skipped
                // BusinessClock's IANA validation and fallback warning; going
                // through the clock restores both, and warmFor() keeps the query
                // budget flat (CronBranchTimezoneQueryBudgetTest pins it).
                BusinessClock::warmFor($lots->map(
                    fn (MaterialLot $lot): ?string => $lot->warehouse?->branch_id,
                ));

                foreach ($lots as $lot) {
                    $tz = BusinessClock::timezoneForBranch($lot->warehouse?->branch_id);
                    $branchToday = Carbon::today($tz);

                    if (Carbon::parse($lot->expiry_date)->startOfDay()->lt($branchToday)) {
                        $expiredIds[] = $lot->id;
                    }
                }
            });

        $count = $expiredIds === []
            ? 0
            : MaterialLot::whereIn('id', $expiredIds)->update([
                'status' => MaterialLotStatusEnum::Expired->value,
            ]);

        $this->info(sprintf('Auto-expired %d material lots.', $count));

        return self::SUCCESS;
    }
}
