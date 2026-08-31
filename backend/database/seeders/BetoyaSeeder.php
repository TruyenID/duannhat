<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ShopOrderSetting;
use App\Services\Provisioning\BranchBaselineProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Canonical production dataset for the first complete Betoya tenant.
 *
 * PlatformDirectorySeeder must run first: organization, brand and branch roots
 * remain owned by Platform. This seeder restores every Tempo-owned catalog,
 * menu, SKU, price, schedule and media record onto those synced roots.
 */
class BetoyaSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCatalogOnFreshInstallOnly();
        $this->ensureBetoyaPricesIncludeTax();

        // #2318/#2323 — hai bộ dữ liệu này TRƯỚC ĐÂY do data migration seed, nên
        // `artisan migrate --force` trong deploy là đủ. #2318 xoá các migration đó
        // và chuyển nguồn về seeder; nhưng deploy chỉ gọi BetoyaSeeder, KHÔNG gọi
        // DatabaseSeeder — nên nếu không có hai dòng dưới thì trên production
        // `payment_methods` mất `cash`/`card_terminal`/`debt` và catalog gateway
        // internal rỗng, tức POS mất phương thức thanh toán.
        //
        // Cả hai idempotent (firstOrCreate/upsert theo code), nên chạy lại mỗi
        // lần deploy là no-op trên hệ đã có.
        $this->call(PaymentMethodSeeder::class);
        $this->call(PaymentGatewayCatalogSeeder::class);

        // Shop LAN hardware (Glory 釣銭機). Fresh Betoya restores used to lose
        // these rows because peripheral_devices are not in the catalog
        // snapshot — POS then hit `503 no cash changer configured`.
        // Every Betoya Glory unit shares 192.168.251.120 (per-shop LAN).
        $this->call(HongoPeripheralDeviceSeeder::class);
        $this->call(NingyochoPeripheralDeviceSeeder::class);

        // HongoShopConfigSeeder is NOT called here — see the class docblock.
    }

    /**
     * Seed the catalog only when there is no catalog yet.
     *
     * `CatalogSnapshotSeeder` is a RESTORE tool, and the deploy called it on
     * every run. Two things it does are safe on an empty database and
     * destructive on a live one:
     *
     *  - it DELETEs and re-upserts `menu_products`, `products`, `product_skus`,
     *    `topping_group_items` and friends from a fixture, so any catalog edit
     *    a shop made since the fixture was dumped is silently reverted;
     *  - it upserts `tables` with `status = 'free'`, `current_order_id = null`,
     *    `paid_at = null`. On a fresh database that is correct — a restored
     *    floor must start unoccupied. Mid-service it hands every occupied table
     *    back as free and detaches it from the order the guests are sitting
     *    behind. The 2026-08-11 06:08 deploy missed that by luck: it landed at
     *    15:08 JST, between lunch and dinner, with every table already free.
     *
     * A restore is a deliberate operation, not a side effect of shipping code.
     * So: run it when the catalog is empty (the fresh-install path the
     * migrate:fresh + seeder decision relies on), and never on top of a live
     * one. Restoring a non-empty database stays possible — by running
     * `db:seed --class=CatalogSnapshotSeeder` on purpose.
     */
    private function seedCatalogOnFreshInstallOnly(): void
    {
        if (DB::table('products')->exists()) {
            $this->command?->info('BetoyaSeeder: catalog already present — skipping CatalogSnapshotSeeder (run it explicitly to restore).');

            return;
        }

        $this->call(CatalogSnapshotSeeder::class);
    }

    /**
     * Betoya customer menus must show 総額表示 (税込). Production dumps may carry
     * `prices_include_tax = false` from shops toggled before go-live — enforce
     * the JP compliance default after every snapshot restore.
     */
    private function ensureBetoyaPricesIncludeTax(): void
    {
        $brand = Brand::query()->where('slug', 'betoya')->where('is_active', true)->first();
        if ($brand === null) {
            return;
        }

        $branches = Branch::query()
            ->where('console_brand_id', $brand->console_brand_id)
            ->where('is_active', true)
            ->get();

        $provisioner = app(BranchBaselineProvisioner::class);
        $provisioned = [];
        foreach ($branches as $branch) {
            if (! ShopOrderSetting::query()->where('branch_id', $branch->id)->exists()) {
                $provisioner->ensure($branch);
                $provisioned[] = $branch->id;
            }
        }

        // Only branches that had NO settings row until a moment ago. A branch
        // that already has one has an operator behind it, and 総額表示 is a
        // shop-facing toggle in admin-web: re-forcing it on every deploy takes
        // the switch away from the person the switch is for. The provisioner
        // above already writes the compliant default for a new branch, which is
        // where the JP requirement actually needs enforcing.
        $updated = ShopOrderSetting::query()
            ->whereIn('branch_id', $provisioned)
            ->update(['prices_include_tax' => true]);

        $this->command?->info("BetoyaSeeder: set prices_include_tax=true on {$updated} newly provisioned shop_order_settings rows.");
    }
}
