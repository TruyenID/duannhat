<?php

namespace App\Console\Commands;

use App\Models\Menu;
use App\Services\Product\MenuService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Detach sections branch menus carry that their master no longer has.
 *
 * `syncSectionLayoutFromMaster` used to attach-and-update only — documented as
 * "leave shop-only sections attached" — so a section HQ dropped stayed on every
 * branch forever. A cloned menu has no shop-only sections to protect (shops
 * cannot add sections to a clone), so those extras are always debris from an
 * older master layout. The shop rendered a section HQ had removed.
 *
 * Fixed forward in MenuService, but that does not reach menus already holding
 * the debris: nothing syncs on a schedule — it waits for a human in the shop to
 * press a button, which for most shops means never. Same reason the sibling
 * RepairBranchMenuCloneDrift exists, and deliberately a separate command:
 * that one's docblock scopes it to three columns and says outright that
 * reordering sections is a layout change its callers did not ask for.
 *
 *   php artisan menus:repair-section-drift              # dry run, writes nothing
 *   php artisan menus:repair-section-drift --apply
 *   php artisan menus:repair-section-drift --apply --branch=<uuid>
 *   php artisan menus:repair-section-drift --apply --force
 *
 * Without --force, a section still holding branch products is REPORTED and left
 * alone: detaching it would strand those rows pointing at a section the menu no
 * longer has. Those are a real layout question for a human, not drift to sweep.
 *
 * The writing lives in MenuService::repairSectionDriftFromMaster, not here.
 * `menus` and `menu_menu_sections` belong to the `menu` aggregate and that
 * service is one of its registered boundaries; a command writing those models
 * directly is a new ad-hoc write site, which is what the domain-mutation guard
 * exists to stop. This class is argument parsing and reporting only.
 */
#[Signature('menus:repair-section-drift {--apply : Write the changes (default is a dry run)} {--branch= : Limit to one branch id} {--force : Also detach sections that still hold products (soft-deletes those rows)}')]
#[Description('Detach sections branch menus kept after HQ removed them from the master.')]
class RepairBranchMenuSectionDrift extends Command
{
    public function handle(MenuService $menus): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $branchId = $this->option('branch');

        $branchMenus = Menu::query()
            ->whereNotNull('master_menu_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['menuSections'])
            ->orderBy('id')
            ->get();

        if ($branchMenus->isEmpty()) {
            $this->info('No cloned branch menus found — nothing to repair.');

            return self::SUCCESS;
        }

        $detached = 0;
        $skipped = 0;
        $touched = 0;

        foreach ($branchMenus as $branchMenu) {
            if ($branchMenu->master_menu_id !== null && $branchMenu->masterMenu === null) {
                // A dangling master_menu_id. Reported rather than skipped in
                // silence: it means this branch menu can never be repaired or
                // synced again, which is worth knowing on its own.
                $this->warn("  menu {$branchMenu->id}: master {$branchMenu->master_menu_id} is gone — cannot mirror");

                continue;
            }

            $result = $menus->repairSectionDriftFromMaster($branchMenu, $apply, $force);

            if ($result['sections'] === []) {
                continue;
            }

            $touched++;
            $detached += $result['detached'];
            $skipped += $result['skipped_with_products'];

            $this->line(sprintf(
                '  menu %s (branch %s) "%s"',
                $branchMenu->id,
                $branchMenu->branch_id,
                $branchMenu->name,
            ));

            foreach ($result['sections'] as $section) {
                $this->line(sprintf(
                    '      %s "%s" (%d products)%s',
                    $section['detached'] ? 'detach' : 'KEEP  ',
                    $section['name'],
                    $section['products'],
                    $section['detached'] ? '' : ' — holds products, re-run with --force to detach',
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'branch_menus=%d touched=%d detached=%d kept_with_products=%d (%s)',
            $branchMenus->count(),
            $touched,
            $detached,
            $skipped,
            $apply ? 'applied' : 'dry run',
        ));

        if (! $apply && $touched > 0) {
            $this->comment('Dry run — nothing written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
