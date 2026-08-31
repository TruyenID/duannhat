<?php

namespace App\Console\Commands;

use App\Models\Menu;
use App\Services\Product\MenuService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * #1234 + #1235 — repair the two clone-time drifts on branch menus cloned
 * BEFORE those fixes landed.
 *
 * Both bugs are already fixed forward, but a fix in `cloneToBranch` does not
 * reach a menu that was cloned last month. Nothing repairs it automatically:
 * `syncFromMaster` would, but nothing calls sync on a schedule — it waits for a
 * human to press a button in the shop, which for most shops means never.
 *
 * What is wrong in the data right now:
 *
 *   service_type   the column was never passed on clone so the DB default
 *                  'Both' stood, and 'Both' matches every service-type filter.
 *                  Takeaway-only menus are being shown to dine-in guests — and
 *                  the takeaway menu is where the reduced-rate overrides live,
 *                  so those guests are billed 軽減税率 on a 店内 order.
 *   schedule dates start_date/end_date were dropped on clone, and the resolver
 *                  reads NULL as "no bound", so a campaign menu runs
 *                  permanently instead of only inside its window.
 *
 *   php artisan menus:repair-clone-drift              # dry run, writes nothing
 *   php artisan menus:repair-clone-drift --apply
 *   php artisan menus:repair-clone-drift --apply --branch=<uuid>
 *
 * The writing lives in MenuService::repairCloneDriftFromMaster, not here.
 * `menus` and `menu_schedules` belong to the `menu` aggregate and that service
 * is one of its registered boundaries; a command writing those models directly
 * is a new ad-hoc write site, which is what the domain-mutation guard exists to
 * stop. The sibling command BackfillBranchMenuTax made exactly that mistake and
 * the guard caught it — this class is argument parsing and reporting only.
 */
#[Signature('menus:repair-clone-drift {--apply : Write the changes (default is a dry run)} {--branch= : Limit to one branch id}')]
#[Description('#1234/#1235 — restore service_type and schedule date windows on branch menus cloned before the fixes.')]
class RepairBranchMenuCloneDrift extends Command
{
    public function handle(MenuService $menus): int
    {
        $apply = (bool) $this->option('apply');
        $branchId = $this->option('branch');

        $branchMenus = Menu::query()
            ->whereNotNull('master_menu_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['masterMenu.schedules', 'schedules'])
            ->orderBy('id')
            ->get();

        if ($branchMenus->isEmpty()) {
            $this->info('No cloned branch menus found — nothing to repair.');

            return self::SUCCESS;
        }

        $totals = ['service_type' => 0, 'schedule_dates' => 0];
        $touched = 0;

        foreach ($branchMenus as $branchMenu) {
            if ($branchMenu->masterMenu === null) {
                // A dangling master_menu_id. Reported rather than skipped in
                // silence: it means this branch menu can never be repaired or
                // synced again, which is worth knowing on its own.
                $this->warn("  menu {$branchMenu->id}: master {$branchMenu->master_menu_id} is gone — cannot mirror");

                continue;
            }

            $changed = $menus->repairCloneDriftFromMaster($branchMenu, $apply);

            if (array_sum($changed) === 0) {
                continue;
            }

            $touched++;
            foreach ($totals as $key => $_) {
                $totals[$key] += $changed[$key];
            }

            $this->line(sprintf(
                '  menu %s (branch %s) — service_type:%d schedule_dates:%d',
                $branchMenu->id,
                $branchMenu->branch_id,
                $changed['service_type'],
                $changed['schedule_dates'],
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            'branch_menus=%d touched=%d service_type=%d schedule_dates=%d (%s)',
            $branchMenus->count(),
            $touched,
            $totals['service_type'],
            $totals['schedule_dates'],
            $apply ? 'applied' : 'dry run',
        ));

        if (! $apply && $touched > 0) {
            $this->comment('Dry run — nothing written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
