<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * ONE-TIME REPAIR. Do NOT put this back in the deploy path.
 *
 * It was called from `BetoyaSeeder`, which the production deploy runs on every
 * push to `main`. On 2026-08-11 06:08:01 — inside that deploy's window — it
 * disabled the four time-slot menu schedules and re-enabled the full menu, and
 * 本郷店 served roughly fifty minutes on the wrong menu before staff noticed and
 * set it back by hand at 07:01.
 *
 * Nothing here is wrong as a repair; it is wrong as a RECURRING one. Every
 * write below is a decision that belongs to the shop from the moment the repair
 * lands: which menus are live, which settings apply, which tables exist. A
 * deploy re-asserting them means the shop can change something in admin-web and
 * silently lose it the next time anyone ships unrelated code.
 *
 * Run it deliberately when a restore needs it:
 *
 *     php artisan db:seed --class=HongoShopConfigSeeder --force
 *
 * `BetoyaSeederLeavesShopOwnedStateAloneTest` fails if it is wired back in.
 *
 * Pins 本郷店 (Hongo) shop_order_settings, floor cleanup, and customer-menu schedule.
 *
 * Real floor (match menu.betoya.jp/hongo): A-1…A-8, B-1…B-8, C-1…C-8 only.
 * Never creates tables or regenerates qr_token — only soft-deletes junk codes
 * that CatalogSnapshot may reintroduce (HALL-01…, COUNTER-01…, TERRACE-*, Test…).
 *
 * Customer QR was resolving to thin HQ-synced lunch/dinner menus (2–5 products,
 * only 期間限定 visible) because 本郷店 メニュー had is_active=0. This seeder
 * re-enables the full menu and disables those incomplete time-slot menus.
 *
 * Run alone:
 *   php artisan db:seed --class=HongoShopConfigSeeder --force
 */
class HongoShopConfigSeeder extends Seeder
{
    /** Stable catalog UUID — 本郷店 メニュー (full dine-in catalog). */
    private const FULL_MENU_ID = '019f6efa-2f8f-7279-8e05-1da70a2a725c';

    /** @var list<string> HQ-synced time-slot menus with incomplete product lines. */
    private const THIN_MENU_IDS = [
        '019fd5da-2a1a-73e4-84d0-29595dc1b9e7', // Bữa trưa
        '019fd5da-400e-7096-9309-5c2bddacf293', // ランチ(15:00~16:00)
        '019fd5da-5b6b-738d-9533-c66198fb6b68', // ディナー transition window
        '019fd5da-7963-7145-bcc4-1271a876e0e2', // Bữa tối & cuối tuần/ngày lễ
    ];

    /** @var list<string> */
    private const JUNK_TABLE_CODES = [
        'HALL-01', 'HALL-02', 'HALL-03', 'HALL-04',
        'HALL-05', 'HALL-06', 'HALL-07', 'HALL-08',
        'COUNTER-01', 'COUNTER-02', 'COUNTER-03', 'COUNTER-04',
        'TERRACE-01', 'TERRACE-02', 'TERRACE-03', 'TERRACE-04',
        'Test',
        'T00-255',
    ];

    /** @var list<string> */
    private const JUNK_ZONE_CODES = [
        'TERRACE',
        'TRUYEN',
    ];

    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'betoya')->first()
            ?? Organization::query()->first();
        if ($organization === null) {
            $this->command?->warn('No organization found — run the base seeders first. Skipping.');

            return;
        }

        $branch = Branch::query()->where('slug', 'hongo')->first();
        if ($branch === null) {
            $this->command?->warn('Branch [hongo] not found — Platform/Betoya catalog must seed it first. Skipping.');

            return;
        }

        $this->pinShopOrderSettings($branch->id, $organization->id);
        $this->softDeleteJunkTables($branch->id);
        $this->softDeleteJunkZones($branch->id);
        $this->pinCustomerMenus($branch->id);

        $this->command?->info('Hongo shop config pinned (SOS + floor + full menu schedule).');
    }

    private function pinShopOrderSettings(string $branchId, string $organizationId): void
    {
        $attrs = [
            'organization_id' => $organizationId,
            'default_order_item_status' => 'served',
            'enable_quick_order' => false,
            'service_charge_rate' => '0.00',
            'prices_include_tax' => true,
            'service_charge_tax_rate' => '0.00',
            'tax_rounding_mode' => 'floor',
            'tax_rounding_decimals' => 0,
            'currency_code' => 'JPY',
            'prep_before_payment' => false,
            'customer_email_required' => false,
            'print_label_locale' => null,
            'print_shift_open_report' => true,
            'print_table_paid' => true,
            'close_report_payment_methods' => true,
            'close_report_service_charge' => false,
            'close_report_denominations' => true,
            'close_report_drawer_check' => true,
            'close_report_tax_breakdown' => true,
            'split_bill_rounding_mode' => 'auto',
            'allow_item_edit_any_status' => false,
            'item_voidable_statuses' => ['pending'],
            'stock_deduction_timing' => 'on_close',
            'show_seller_registration_on_receipt' => true,
            'prep_minutes_per_item' => null,
            'handy_allow_direct_payment' => false,
            'manual_discount_max_percent' => '20.00',
            'confirmation_timeout_minutes' => null,
            'table_status_after_payment' => null,
        ];

        $existing = ShopOrderSetting::query()->where('branch_id', $branchId)->first();
        if ($existing !== null) {
            $existing->fill($attrs);
            $existing->save();

            return;
        }

        ShopOrderSetting::query()->create([
            ...$attrs,
            'branch_id' => $branchId,
        ]);
    }

    private function pinCustomerMenus(string $branchId): void
    {
        $full = Menu::query()
            ->where('branch_id', $branchId)
            ->where(function ($q): void {
                $q->where('id', self::FULL_MENU_ID)
                    ->orWhere('name', '本郷店 メニュー');
            })
            ->first();

        if ($full !== null) {
            $full->priority = 300;
            $full->save();

            MenuSchedule::query()
                ->where('menu_id', $full->id)
                ->update([
                    'is_active' => true,
                    'priority' => 300,
                ]);
        } else {
            $this->command?->warn('Hongo full menu [本郷店 メニュー] not found — skipped menu pin.');
        }

        $thinIds = Menu::query()
            ->where('branch_id', $branchId)
            ->whereIn('id', self::THIN_MENU_IDS)
            ->pluck('id');

        if ($thinIds->isNotEmpty()) {
            MenuSchedule::query()
                ->whereIn('menu_id', $thinIds)
                ->update(['is_active' => false]);
        }
    }

    private function softDeleteJunkTables(string $branchId): void
    {
        $tables = Table::query()
            ->where('branch_id', $branchId)
            ->whereIn('code', self::JUNK_TABLE_CODES)
            ->get();

        foreach ($tables as $table) {
            $status = $table->status instanceof \BackedEnum
                ? $table->status->value
                : (string) $table->status;
            $occupied = $status !== 'free' || filled($table->current_order_id);
            if ($occupied) {
                $this->command?->warn("Hongo junk table [{$table->code}] is occupied — skipped soft-delete.");

                continue;
            }
            $table->delete();
        }
    }

    private function softDeleteJunkZones(string $branchId): void
    {
        foreach (self::JUNK_ZONE_CODES as $code) {
            $zone = Zone::query()
                ->where('branch_id', $branchId)
                ->where('code', $code)
                ->first();
            if ($zone === null) {
                continue;
            }

            $live = Table::query()
                ->where('zone_id', $zone->id)
                ->whereNull('deleted_at')
                ->count();
            if ($live > 0) {
                $this->command?->warn("Hongo zone [{$code}] still has live tables — skipped soft-delete.");

                continue;
            }

            $zone->delete();
        }
    }
}
