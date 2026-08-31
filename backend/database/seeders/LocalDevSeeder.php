<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Table;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local Development Seeder
 *
 * Seeds sample products, categories, and warehouses using brands/branches
 * that were synced from console via SSO login.
 *
 * IMPORTANT: Run this AFTER logging in via SSO at least once so that
 * organizations, brands, and branches are synced from console.
 *
 * Usage:
 *   1. Login via SSO (http://localhost:3000/login)
 *   2. php artisan db:seed --class=LocalDevSeeder
 */
class LocalDevSeeder extends Seeder
{
    use Concerns\WritesTranslations;
    use RefusesToRunInProduction;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $this->seedRoles();

        $brands = Brand::where('is_active', true)->orderBy('slug')->get();

        if ($brands->isEmpty()) {
            $this->command->warn('No brands found. Login via SSO first to sync brands from console.');
            $this->command->info('Then re-run: php artisan db:seed --class=LocalDevSeeder');

            return;
        }

        $org = Organization::where('console_organization_id', $brands->first()->console_organization_id)->first();

        if (! $org) {
            $this->command->warn('No organization found matching brand console_organization_id.');

            return;
        }

        $orgId = $org->id;

        foreach ($brands as $brand) {
            // A brand that already carries a real catalog (CatalogSnapshotSeeder)
            // owns its own product types and categories. Layering the sample
            // ones on top would put placeholder rows in a live menu tree.
            if (Product::where('brand_id', $brand->id)->exists()) {
                $this->command->info("Skipping sample catalog for {$brand->slug} — brand already has products.");

                continue;
            }

            $this->command->info("Seeding brand: {$brand->name} (slug: {$brand->slug}, org: {$orgId})");
            $this->seedProductTypes($orgId, $brand);
            $this->seedCategories($orgId, $brand);
        }

        // Products are seeded by ProductSeeder (with i18n support)
        $this->seedWarehouses($orgId);
        $this->seedZonesAndTables($orgId);
        $this->seedDevices($orgId);

        $this->command->info('Sample data seeded successfully.');
    }

    // =========================================================================
    //  Roles
    // =========================================================================

    private function seedRoles(): void
    {
        $roles = [
            ['slug' => 'org-admin', 'name' => 'Organization Admin', 'level' => 100],
            ['slug' => 'org-manager', 'name' => 'Organization Manager', 'level' => 50],
            ['slug' => 'staff', 'name' => 'Staff', 'level' => 10],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['slug' => $role['slug'], 'console_organization_id' => null],
                ['name' => $role['name'], 'level' => $role['level']]
            );
        }
    }

    // =========================================================================
    //  Product Types
    // =========================================================================

    private function seedProductTypes(string $orgId, Brand $brand): void
    {
        $types = [
            [
                'code' => 'FOOD',
                'form' => 'physical',
                'has_recipe' => true,
                'translations' => [
                    'ja' => ['name' => 'フード'],
                    'en' => ['name' => 'Food'],
                    'vi' => ['name' => 'Đồ ăn'],
                ],
            ],
            [
                'code' => 'DRINK',
                'form' => 'physical',
                'has_recipe' => true,
                'translations' => [
                    'ja' => ['name' => 'ドリンク'],
                    'en' => ['name' => 'Drink'],
                    'vi' => ['name' => 'Đồ uống'],
                ],
            ],
            [
                'code' => 'topping',
                'form' => 'physical',
                'has_recipe' => false,
                'translations' => [
                    'ja' => ['name' => 'トッピング'],
                    'en' => ['name' => 'Topping'],
                    'vi' => ['name' => 'Topping'],
                ],
            ],
        ];

        foreach ($types as $type) {
            $productType = ProductType::firstOrCreate(
                ['brand_id' => $brand->id, 'code' => $type['code']],
                ['organization_id' => $orgId, 'product_form' => $type['form'], 'has_recipe' => $type['has_recipe'], 'is_inventory_tracked' => true, 'is_active' => true]
            );

            // Đồng bộ vô điều kiện — chạy cả lúc tạo lẫn lúc seed lại.
            $this->writeTranslations($productType, $type['translations']);
        }
    }

    // =========================================================================
    //  Categories
    // =========================================================================

    private function seedCategories(string $orgId, Brand $brand): void
    {
        $cats = [
            [
                'sku' => 'CAT-MAIN',
                'slug' => 'main-dishes',
                'translations' => [
                    'ja' => ['name' => 'メイン'],
                    'en' => ['name' => 'Main Dishes'],
                    'vi' => ['name' => 'Món chính'],
                ],
            ],
            [
                'sku' => 'CAT-SIDE',
                'slug' => 'side-dishes',
                'translations' => [
                    'ja' => ['name' => 'サイド'],
                    'en' => ['name' => 'Side Dishes'],
                    'vi' => ['name' => 'Món phụ'],
                ],
            ],
            [
                'sku' => 'CAT-DRINK',
                'slug' => 'drinks',
                'translations' => [
                    'ja' => ['name' => 'ドリンク'],
                    'en' => ['name' => 'Drinks'],
                    'vi' => ['name' => 'Đồ uống'],
                ],
            ],
        ];

        foreach ($cats as $cat) {
            $category = Category::firstOrCreate(
                ['organization_id' => $orgId, 'sku' => $cat['sku']],
                ['brand_id' => $brand->id, 'slug' => $cat['slug'], 'is_active' => true]
            );

            // Trước đây đặt trường rồi `$category->save()` — tức giao việc bền
            // hoá cho hook `saved` của Astrotomic, mà `DatabaseSeeder` chạy
            // trong `withoutEvents` nên hook không bắn và bản dịch bốc hơi.
            // Dòng `// Ensure FK is set` cũ chính là triệu chứng của cùng gốc:
            // đi qua quan hệ thì khoá ngoại không phải đặt tay.
            $this->writeTranslations($category, $cat['translations']);
        }
    }

    // =========================================================================
    //  Products
    // =========================================================================

    private function seedProducts(string $orgId, Brand $brand): void
    {
        $drinkType = ProductType::where('organization_id', $orgId)->where('code', 'DRINK')->first();
        $foodType = ProductType::where('organization_id', $orgId)->where('code', 'FOOD')->first();

        if (! $foodType || ! $drinkType) {
            return;
        }

        $products = [
            ['name' => 'フォー', 'slug' => 'pho', 'type' => $foodType, 'status' => 'active', 'variants' => [['name' => 'レギュラー', 'sku' => 'PHO-001-R', 'cost' => 800], ['name' => 'ラージ', 'sku' => 'PHO-001-L', 'cost' => 1000]]],
            ['name' => 'バインミー', 'slug' => 'banh-mi', 'type' => $foodType, 'status' => 'active', 'variants' => [['name' => 'クラシック', 'sku' => 'BM-001-C', 'cost' => 500], ['name' => 'スペシャル', 'sku' => 'BM-001-S', 'cost' => 700]]],
            ['name' => '生春巻き', 'slug' => 'goi-cuon', 'type' => $foodType, 'status' => 'active', 'variants' => [['name' => '2本', 'sku' => 'GC-001-2', 'cost' => 400], ['name' => '4本', 'sku' => 'GC-001-4', 'cost' => 700]]],
            ['name' => 'ベトナムコーヒー', 'slug' => 'viet-coffee', 'type' => $drinkType, 'status' => 'active', 'variants' => [['name' => 'ホット', 'sku' => 'VC-001-H', 'cost' => 400], ['name' => 'アイス', 'sku' => 'VC-001-I', 'cost' => 450]]],
            ['name' => 'レモングラスティー', 'slug' => 'lemongrass-tea', 'type' => $drinkType, 'status' => 'active', 'variants' => [['name' => 'M', 'sku' => 'LT-001-M', 'cost' => 350]]],
            ['name' => 'チェー', 'slug' => 'che', 'type' => $foodType, 'status' => 'draft', 'variants' => [['name' => 'レギュラー', 'sku' => 'CHE-001-R', 'cost' => 500]]],
            ['name' => 'ブンチャー', 'slug' => 'bun-cha', 'type' => $foodType, 'status' => 'pending', 'variants' => [['name' => 'レギュラー', 'sku' => 'BC-001-R', 'cost' => 900]]],
        ];

        foreach ($products as $p) {
            $product = Product::firstOrCreate(
                ['brand_id' => $brand->id, 'name' => $p['name']],
                ['organization_id' => $orgId, 'product_type_id' => $p['type']->id, 'status' => $p['status'], 'slug' => $p['slug'], 'is_hidden' => false]
            );

            foreach ($p['variants'] as $v) {
                ProductSku::firstOrCreate(
                    ['product_id' => $product->id, 'sku' => $v['sku']],
                    // issue #875 — `cost` is the menu price → selling_price (source
                    // of truth); cost_price stays 0 (auto-derived from recipe later).
                    ['name' => $v['name'], 'option_signature' => $v['sku'], 'selling_price' => $v['cost'], 'cost_price' => 0, 'cost_price_auto' => 0, 'is_cost_override' => false, 'is_active' => true]
                );
            }
        }

        $this->command->info("Seeded {$brand->name}: ".Product::where('brand_id', $brand->id)->count().' products');
    }

    // =========================================================================
    //  Warehouses
    // =========================================================================

    private function seedWarehouses(string $orgId): void
    {
        $org = Organization::find($orgId);
        $consoleOrgId = $org?->console_organization_id;

        $branches = Branch::where('console_organization_id', $consoleOrgId)
            ->where('is_headquarters', false)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get();

        if ($branches->isEmpty()) {
            $branches = Branch::where('console_organization_id', $consoleOrgId)
                ->where('is_active', true)
                ->get();
        }

        if ($branches->isEmpty()) {
            $this->command->warn('No branches found — skipping warehouse seeding.');

            return;
        }

        // Each shop branch needs its own main warehouse so production batches
        // and inventory adjustments at that shop have somewhere to land. Code
        // is keyed off the branch slug (uppercased, fallback to a 1-based
        // index) so the (organization_id, code) unique pair never collides
        // when multiple branches share the same org.
        foreach ($branches as $index => $branch) {
            $slugPart = $branch->slug
                ? strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $branch->slug))
                : str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $code = "WH-{$slugPart}-01";

            Warehouse::firstOrCreate(
                ['organization_id' => $orgId, 'code' => $code],
                [
                    'branch_id' => $branch->id,
                    'name' => "{$branch->name} メイン倉庫",
                    'type' => 'main',
                    'is_active' => true,
                    'auto_approve_stock_in' => true,
                    // Plan-024 / Decision 8 UX fix — dev seed flips this on so
                    // the canonical sale-by-order demo (close order → SKU
                    // StockLevel decrements immediately) works without a
                    // manual approval detour. Production warehouses can opt
                    // out via the Warehouse Edit dialog.
                    'auto_approve_stock_out' => true,
                ]
            );

            $this->command->info("Warehouse {$code} created at: {$branch->name}");
        }
    }

    // =========================================================================
    //  Zones & Tables
    // =========================================================================

    private function seedZonesAndTables(string $orgId): void
    {
        $org = Organization::find($orgId);
        $branches = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($branches->isEmpty()) {
            $this->command->warn('No branches — skipping zones & tables.');

            return;
        }

        $zoneTemplates = [
            ['code' => 'INDOOR', 'name' => '店内', 'order' => 1],
            ['code' => 'TERRACE', 'name' => 'テラス', 'order' => 2],
            ['code' => 'VIP', 'name' => 'VIP', 'order' => 3],
        ];

        // Table templates per zone: [prefix, count, seats]
        $tableTemplates = [
            'INDOOR' => [
                ['prefix' => 'A', 'count' => 6, 'seats' => 4],
                ['prefix' => 'B', 'count' => 4, 'seats' => 2],
                ['prefix' => 'C', 'count' => 2, 'seats' => 6],
            ],
            'TERRACE' => [
                ['prefix' => 'T', 'count' => 4, 'seats' => 4],
            ],
            'VIP' => [
                ['prefix' => 'V', 'count' => 2, 'seats' => 8],
            ],
        ];

        $statuses = ['free', 'free', 'free', 'occupied', 'reserved', 'cleaning'];
        $totalTables = 0;

        foreach ($branches as $branch) {
            // Branches whose real floor plan arrived with the catalog snapshot
            // keep it. The remainder — back office, central kitchen, factory,
            // pop-up events — genuinely have no tables in production, so the
            // sample layout is what makes them usable locally.
            if (Zone::where('branch_id', $branch->id)->exists()) {
                $this->command->info("Zones & tables already present for: {$branch->name} — skipping.");

                continue;
            }

            foreach ($zoneTemplates as $zt) {
                $zone = Zone::firstOrCreate(
                    ['branch_id' => $branch->id, 'code' => $zt['code']],
                    [
                        'organization_id' => $orgId,
                        'name' => $zt['name'],
                        'display_order' => $zt['order'],
                        'is_active' => true,
                    ]
                );

                foreach ($tableTemplates[$zt['code']] ?? [] as $tt) {
                    for ($i = 1; $i <= $tt['count']; $i++) {
                        $code = sprintf('%s-%02d', $tt['prefix'], $i);
                        Table::firstOrCreate(
                            ['branch_id' => $branch->id, 'code' => $code],
                            [
                                'organization_id' => $orgId,
                                'zone_id' => $zone->id,
                                'name' => null,
                                'seat_count' => $tt['seats'],
                                'status' => $statuses[array_rand($statuses)],
                                'qr_token' => Str::random(32),
                                'is_active' => true,
                            ]
                        );
                        $totalTables++;
                    }
                }
            }

            $this->command->info("Zones & tables seeded for: {$branch->name}");
        }

        $this->command->info("Total tables created: {$totalTables}");
    }

    // =========================================================================
    //  Devices (TMS terminals)
    // =========================================================================

    private function seedDevices(string $orgId): void
    {
        $org = Organization::find($orgId);
        $branches = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($branches->isEmpty()) {
            return;
        }

        $deviceTemplates = [
            ['name' => 'TMS-受付', 'type' => 'tms'],
            ['name' => 'TMS-フロア', 'type' => 'tms'],
            ['name' => 'Kiosk', 'type' => 'kiosk'],
            ['name' => 'Workstation-POS', 'type' => 'workstation'],
            ['name' => 'KDS-キッチン', 'type' => 'kds'],
            ['name' => 'Handy-1', 'type' => 'handy'],
            ['name' => 'Handy-2', 'type' => 'handy'],
        ];

        foreach ($branches as $branch) {
            foreach ($deviceTemplates as $dt) {
                Device::firstOrCreate(
                    ['branch_id' => $branch->id, 'name' => $dt['name']],
                    [
                        'organization_id' => $orgId,
                        'type' => $dt['type'],
                        'status' => DeviceStatusEnum::PendingActivation->value,
                        'pairing_code' => strtoupper(Str::random(6)),
                        'pairing_expires_at' => now()->addHours(24),
                    ]
                );
            }

            $this->command->info("Devices seeded for: {$branch->name} (2 TMS + 1 Kiosk + 1 Workstation + 1 KDS + 2 Handy)");
        }
    }
}
