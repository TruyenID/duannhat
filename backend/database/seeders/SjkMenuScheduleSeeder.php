<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSchedule;
use App\Omnify\Enums\MenuStatusEnum;
use Illuminate\Database\Seeder;

/**
 * Seeds extra menus + time-based schedules for shop `sjk` (新宿店).
 *
 * Builds on top of MenuSeeder which already creates 1 branch menu per
 * brand at sjk. Per brand we additionally:
 *
 *   1. Create a "Lunch Set (新宿店ランチ)" menu cloned from the brand's
 *      sjk branch menu — same sections + products, but only attached
 *      to schedules in the lunch window. Higher menu priority so
 *      `MenuService::getCurrentMenu()` picks it during lunch hours
 *      and falls back to the always-on branch menu the rest of the day.
 *
 *   2. Attach 2–4 MenuSchedule rows per branch menu using a
 *      brand-appropriate profile (coffee shop, restaurant, izakaya).
 *
 * Schedule semantics — see MenuService::getCurrentMenu():
 *   - `days_of_week` is a 7-bit bitmask (bit0=Sun … bit6=Sat).
 *   - When a menu has zero schedules, it's "always-on" (always matches).
 *   - When it has schedules, at least one must match today + current
 *     time for the menu to be considered live.
 *   - Multiple matching menus → highest `Menu.priority` wins.
 *
 * Idempotent: every row uses firstOrCreate, safe to re-run.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=SjkMenuScheduleSeeder
 */
class SjkMenuScheduleSeeder extends Seeder
{
    private const SHOP_SLUG = 'sjk';

    /**
     * Suffix used on cloned lunch-set menus. Re-runs of this seeder must
     * exclude these from "branch menu" iteration so we don't apply brand
     * schedule profiles to a menu that is itself a lunch sibling.
     */
    private const LUNCH_MENU_SUFFIX = '— 新宿店ランチセット';

    /** Day-of-week bitmasks for readability. */
    private const DOW_ALL = 127;             // Sun-Sat

    private const DOW_WEEKDAYS = 0b0111110;  // Mon-Fri = 62

    private const DOW_WEEKEND = 0b1000001;   // Sun + Sat = 65

    private const DOW_FRI_SAT = 0b1100000;   // Fri + Sat = 96

    /**
     * Schedule profile per brand slug. Each entry becomes one MenuSchedule
     * row. `priority` is intra-menu (lower = preferred when multiple
     * schedules of the SAME menu match at once — see Menu::activeSchedules).
     *
     * @var array<string, array<int, array{name:string, start:string, end:string, dow:int, priority:int}>>
     */
    private const BRAND_SCHEDULES = [
        // Coffee shop — opens early, closes mid-evening.
        'beto-coffee' => [
            ['name' => 'Morning rush',     'start' => '07:00:00', 'end' => '10:30:00', 'dow' => self::DOW_ALL,      'priority' => 30],
            ['name' => 'All-day fallback', 'start' => '06:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 100],
        ],

        // Family restaurant — split lunch / dinner with an explicit fallback.
        'beto-kitchen' => [
            ['name' => 'Weekday lunch',    'start' => '11:00:00', 'end' => '14:30:00', 'dow' => self::DOW_WEEKDAYS, 'priority' => 30],
            ['name' => 'Dinner service',   'start' => '17:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 50],
            ['name' => 'All-day fallback', 'start' => '11:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 100],
        ],

        // Izakaya — evening + late-night Fri/Sat.
        'betoya' => [
            ['name' => 'Happy hour',       'start' => '17:00:00', 'end' => '19:00:00', 'dow' => self::DOW_WEEKDAYS, 'priority' => 30],
            ['name' => 'Dinner service',   'start' => '17:00:00', 'end' => '22:00:00', 'dow' => self::DOW_ALL,      'priority' => 50],
            ['name' => 'Late night',       'start' => '22:00:00', 'end' => '23:30:00', 'dow' => self::DOW_FRI_SAT,  'priority' => 40],
            ['name' => 'All-day fallback', 'start' => '17:00:00', 'end' => '23:30:00', 'dow' => self::DOW_ALL,      'priority' => 100],
        ],
    ];

    /**
     * Section keys (from MenuSeeder::SECTION_DEFS) that the lunch-set
     * sibling menu should inherit. Anything outside these is dropped from
     * the lunch menu so customers see a smaller, faster-to-prepare list.
     *
     * @var array<int, string> matches MenuSection.name from MenuSeeder
     */
    private const LUNCH_SECTION_NAMES = [
        'おすすめ',
        'ランチセット',
    ];

    public function run(): void
    {
        $shop = Branch::where('slug', self::SHOP_SLUG)->first();

        if (! $shop) {
            $this->command->warn(
                'Shop "'.self::SHOP_SLUG.'" not found — run BranchSeeder/MockDataSeeder first.'
            );

            return;
        }

        $branchMenus = Menu::where('branch_id', $shop->id)
            ->where('status', MenuStatusEnum::Active->value)
            // Exclude lunch siblings created by a previous run of this
            // seeder — they're handled separately and should not receive
            // the brand's full schedule profile.
            ->where('name', 'NOT LIKE', '%'.self::LUNCH_MENU_SUFFIX.'%')
            ->with('brand:id,name,slug')
            ->get();

        if ($branchMenus->isEmpty()) {
            $this->command->warn(
                "Shop {$shop->name}: no active branch menus — run MenuSeeder first."
            );

            return;
        }

        $this->command->info("Seeding menus + schedules for shop {$shop->name} ({$shop->slug}):");

        foreach ($branchMenus as $branchMenu) {
            $brandSlug = $branchMenu->brand?->slug ?? 'unknown';
            $this->command->info("  brand {$brandSlug} → menu \"{$branchMenu->name}\"");

            // 1. Schedules on the existing branch menu (per brand profile).
            $this->seedSchedulesForMenu($branchMenu, $brandSlug);

            // 2. Lunch-set sibling menu — cloned subset of the branch menu.
            //    Skipped for the coffee brand (no lunch concept there).
            if ($brandSlug !== 'beto-coffee') {
                $lunchMenu = $this->seedLunchSetMenu($shop, $branchMenu);
                $this->seedLunchSchedule($lunchMenu);
            }
        }

        $this->command->info('SjkMenuScheduleSeeder done.');
    }

    /**
     * Attach schedules from BRAND_SCHEDULES to the menu. Falls back to the
     * generic restaurant profile when the brand slug isn't recognised.
     */
    private function seedSchedulesForMenu(Menu $menu, string $brandSlug): void
    {
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
                $this->command->info(
                    "    + schedule \"{$entry['name']}\" {$entry['start']}–{$entry['end']} dow={$entry['dow']} pri={$entry['priority']}"
                );
            }
        }
    }

    /**
     * Create or fetch the brand's "Lunch Set" sibling menu at sjk and
     * clone the lunch-relevant slice from the branch menu into it. Uses
     * the SAME MenuSection rows (shared via menu_menu_sections) so the FE
     * sees consistent section names across both menus.
     */
    private function seedLunchSetMenu(Branch $shop, Menu $branchMenu): Menu
    {
        // `menus_branch_id_priority_unique` forbids two menus at the same
        // branch from sharing a priority. Pick the next free slot above the
        // branch fallback so getCurrentMenu picks the lunch menu during
        // its window without colliding with a sibling brand's lunch menu.
        $nextPriority = (int) Menu::where('branch_id', $shop->id)->max('priority') + 1;

        $lunchMenu = Menu::firstOrCreate(
            [
                'branch_id' => $shop->id,
                'brand_id' => $branchMenu->brand_id,
                'organization_id' => $branchMenu->organization_id,
                'name' => "{$branchMenu->brand->name} ".self::LUNCH_MENU_SUFFIX,
            ],
            [
                'is_master' => false,
                'master_menu_id' => $branchMenu->master_menu_id,
                'priority' => $nextPriority,
                'status' => MenuStatusEnum::Active->value,
                'last_synced_at' => now(),
            ],
        );

        if ($lunchMenu->wasRecentlyCreated) {
            $this->command->info("    + new menu \"{$lunchMenu->name}\" (priority 200)");
        }

        $this->cloneLunchSlice($branchMenu, $lunchMenu);

        return $lunchMenu;
    }

    /**
     * Clone the source menu's "lunch + best seller" sections (sections
     * + products + per-SKU pricing) into the target. menu_menu_sections
     * are SHARED rather than cloned so renaming a section ripples to
     * both menus, matching MenuSeeder's convention.
     */
    private function cloneLunchSlice(Menu $source, Menu $target): void
    {
        $lunchSections = $source->menuSections()
            ->whereIn('menu_sections.name', self::LUNCH_SECTION_NAMES)
            ->get();

        if ($lunchSections->isEmpty()) {
            $this->command->warn(
                '      (source menu has no lunch/best-seller sections — skipping clone)'
            );

            return;
        }

        $sectionIds = $lunchSections->pluck('id')->all();

        // Attach sections to target menu (idempotent — pivot has unique on
        // (menu_id, menu_section_id)). Carry forward the source's display
        // order for predictable rendering.
        $sourcePivots = $source->menuSections()
            ->whereIn('menu_sections.id', $sectionIds)
            ->get()
            ->mapWithKeys(fn ($s) => [$s->id => $s->pivot->display_order])
            ->all();

        foreach ($sourcePivots as $sectionId => $displayOrder) {
            if (! $target->menuSections()->where('menu_sections.id', $sectionId)->exists()) {
                $target->menuSections()->attach($sectionId, ['display_order' => $displayOrder]);
            }
        }

        // Clone menu_products + menu_product_skus rows.
        $sourceMenuProducts = MenuProduct::where('menu_id', $source->id)
            ->whereIn('menu_section_id', $sectionIds)
            ->with('menuProductSkus')
            ->get();

        foreach ($sourceMenuProducts as $sourceMp) {
            $targetMp = MenuProduct::firstOrCreate(
                [
                    'menu_id' => $target->id,
                    'product_id' => $sourceMp->product_id,
                    'menu_section_id' => $sourceMp->menu_section_id,
                ],
                [
                    'is_active' => $sourceMp->is_active,
                    'display_order' => $sourceMp->display_order,
                    'master_menu_product_id' => $sourceMp->master_menu_product_id,
                ],
            );

            foreach ($sourceMp->menuProductSkus as $sourceSku) {
                MenuProductSku::firstOrCreate(
                    [
                        'menu_product_id' => $targetMp->id,
                        'product_sku_id' => $sourceSku->product_sku_id,
                    ],
                    [
                        'selling_price' => $sourceSku->selling_price,
                        'is_price_overridden' => $sourceSku->is_price_overridden,
                        'is_active' => $sourceSku->is_active,
                    ],
                );
            }
        }
    }

    /** Attach the canonical lunch window (Mon–Fri 11:00–14:30) to a lunch menu. */
    private function seedLunchSchedule(Menu $lunchMenu): void
    {
        $schedule = MenuSchedule::firstOrCreate(
            [
                'menu_id' => $lunchMenu->id,
                'start_time' => '11:00:00',
                'end_time' => '14:30:00',
                'days_of_week' => self::DOW_WEEKDAYS,
            ],
            [
                'is_active' => true,
                'priority' => 1, // highest within this menu
            ],
        );
        if ($schedule->wasRecentlyCreated) {
            $this->command->info(
                '    + lunch schedule 11:00–14:30 Mon–Fri (dow='.self::DOW_WEEKDAYS.')'
            );
        }
    }
}
