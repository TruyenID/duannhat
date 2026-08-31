<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Omnify\Enums\MenuStatusEnum;
use Illuminate\Database\Seeder;

/**
 * Seeds time-based MenuSchedule rows onto every active branch menu so the
 * shop-side "menus by day" endpoint
 * (`GET /api/v1/shops/{shopSlug}/menus/by-day/{dayOfWeek}`) and
 * `MenuService::getCurrentMenu()` have data to surface from a fresh
 * `migrate:fresh --seed`.
 *
 * Without these rows every branch menu is "always-on" (zero schedules) and
 * `listActiveBranchMenusForShopByDay` returns an empty list — that's the
 * "không thấy menu theo schedule" symptom this seeder fixes.
 *
 * Brand-aware schedule profiles (matches SjkMenuScheduleSeeder):
 *   - beto-coffee  → coffee shop hours (morning rush + all-day fallback)
 *   - beto-kitchen → family restaurant (lunch + dinner + fallback)
 *   - betoya       → izakaya (happy hour + dinner + late night + fallback)
 *   - unknown brand falls back to the beto-kitchen profile.
 *
 * `days_of_week` is a 7-bit bitmask matching `MenuService::getCurrentMenu()`:
 *   bit0=Sun … bit6=Sat (Carbon::dayOfWeek convention).
 *
 * Idempotent: every row uses firstOrCreate, safe to re-run.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=MenuScheduleSeeder
 */
class MenuScheduleSeeder extends Seeder
{
    /** Day-of-week bitmasks for readability. */
    private const DOW_ALL = 127;             // Sun-Sat

    private const DOW_WEEKDAYS = 0b0111110;  // Mon-Fri = 62

    private const DOW_FRI_SAT = 0b1100000;   // Fri + Sat = 96

    /**
     * @var array<string, array<int, array{name:string, start:string, end:string, dow:int, priority:int}>>
     */
    private const BRAND_SCHEDULES = [
        'beto-coffee' => [
            ['name' => 'Morning rush',     'start' => '07:00:00', 'end' => '10:30:00', 'dow' => self::DOW_ALL,      'priority' => 30],
            ['name' => 'All-day fallback', 'start' => '06:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 100],
        ],
        'beto-kitchen' => [
            ['name' => 'Weekday lunch',    'start' => '11:00:00', 'end' => '14:30:00', 'dow' => self::DOW_WEEKDAYS, 'priority' => 30],
            ['name' => 'Dinner service',   'start' => '17:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 50],
            ['name' => 'All-day fallback', 'start' => '11:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 100],
        ],
        'betoya' => [
            ['name' => 'Happy hour',       'start' => '17:00:00', 'end' => '19:00:00', 'dow' => self::DOW_WEEKDAYS, 'priority' => 30],
            ['name' => 'Dinner service',   'start' => '17:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 50],
            ['name' => 'Late night',       'start' => '22:00:00', 'end' => '23:30:00', 'dow' => self::DOW_FRI_SAT,  'priority' => 40],
            ['name' => 'All-day fallback', 'start' => '17:00:00', 'end' => '23:30:00', 'dow' => self::DOW_ALL,      'priority' => 100],
        ],
    ];

    public function run(): void
    {
        $branchMenus = Menu::query()
            ->where('is_master', false)
            ->whereNotNull('branch_id')
            ->whereNotNull('master_menu_id')
            ->where('status', MenuStatusEnum::Active->value)
            ->with(['brand:id,slug,name', 'branch:id,slug,name'])
            ->get();

        if ($branchMenus->isEmpty()) {
            $this->command->warn('No active branch menus found — run MenuSeeder first.');

            return;
        }

        $created = 0;
        foreach ($branchMenus as $menu) {
            $brandSlug = $menu->brand?->slug ?? 'unknown';
            $profile = self::BRAND_SCHEDULES[$brandSlug] ?? self::BRAND_SCHEDULES['beto-kitchen'];

            foreach ($profile as $entry) {
                $schedule = MenuSchedule::firstOrCreate(
                    [
                        'menu_id' => $menu->id,
                        'start_time' => $entry['start'],
                        'end_time' => $entry['end'],
                        'days_of_week' => $entry['dow'],
                    ],
                    [
                        'is_active' => true,
                        'priority' => $entry['priority'],
                    ],
                );

                if ($schedule->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $this->command->info(
            "MenuScheduleSeeder: created {$created} menu_schedules across {$branchMenus->count()} branch menus."
        );
    }
}
