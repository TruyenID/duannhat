<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Omnify\Enums\MenuStatusEnum;
use Illuminate\Database\Seeder;

/**
 * Makes the New York Store (USD) branch testable at ANY hour.
 *
 * GlobalMultiTimezoneSeeder already clones 3 menus onto the New York branch,
 * but their schedules only cover local business hours (07:00–22:00
 * America/New_York). Outside that window the customer menu endpoint
 * (CustomerMenuService::getMenuForBranch → time-of-day filter) returns "no
 * active menu", so the takeaway flow shows an empty menu whenever it isn't NY
 * daytime — which is why QA "has no menu to test".
 *
 * This seeder adds a 24/7 always-available schedule to each active New York
 * menu so the menu resolves regardless of wall-clock time. It's a low-priority
 * fallback: it never overrides a real business-hours window, it only guarantees
 * the menu is never empty.
 *
 * Idempotent: firstOrCreate, safe to re-run.
 */
class NewYorkStoreMenuSeeder extends Seeder
{
    /** 7-bit day-of-week bitmask, bits 0..6 → Sun..Sat (all days). */
    private const DOW_ALL = 127;

    public function run(): void
    {
        $branch = Branch::where('slug', 'new-york')->first();

        if (! $branch) {
            $this->command?->warn('NewYorkStoreMenuSeeder: branch "new-york" not found — run GlobalMultiTimezoneSeeder first.');

            return;
        }

        $menus = Menu::query()
            ->where('branch_id', $branch->id)
            ->where('status', MenuStatusEnum::Active->value)
            ->get();

        if ($menus->isEmpty()) {
            $this->command?->warn('NewYorkStoreMenuSeeder: no active menus for New York — run GlobalMultiTimezoneSeeder first.');

            return;
        }

        $created = 0;
        foreach ($menus as $menu) {
            $schedule = MenuSchedule::firstOrCreate(
                [
                    'menu_id' => $menu->id,
                    'start_time' => '00:00:00',
                    'end_time' => '23:59:59',
                    'days_of_week' => self::DOW_ALL,
                ],
                [
                    'is_active' => true,
                    'priority' => 200, // low fallback — availability only, never overrides a real window
                ],
            );

            if ($schedule->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(
            "NewYorkStoreMenuSeeder: added {$created} always-available (24/7) schedule(s) across {$menus->count()} New York menu(s)."
        );
    }
}
