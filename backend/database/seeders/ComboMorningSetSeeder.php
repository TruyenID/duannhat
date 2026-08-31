<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds "Combo Phở Sáng" with topping groups that have variant-aware items.
 *
 * Creates:
 * - 1 combo product "Combo Phở Sáng" (¥980 base)
 * - 2 topping groups:
 *   1. "Chọn phở (1 món)" — Beef Pho / Chicken Pho / Seafood Pho
 *      Each pho has 2 SKUs (Regular/Large) with different extra_price
 *   2. "Chọn đồ uống (1 món)" — Trà đá / Nước dừa / Chè thái
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=ComboMorningSetSeeder
 */
class ComboMorningSetSeeder extends Seeder
{
    public function run(): void
    {
        $brand = Brand::first();
        if (! $brand) {
            $this->command->error('No brand found.');

            return;
        }

        $org = Organization::first();
        $orgId = $org->id;

        // 1. Ensure combo product type
        $comboType = ProductType::firstOrCreate(
            ['brand_id' => $brand->id, 'code' => 'combo'],
            [
                'organization_id' => $orgId,
                'name' => 'Combo',
                'product_form' => 'physical',
                'has_recipe' => false,
                'is_inventory_tracked' => false,
                'is_active' => true,
            ]
        );

        // 2. Ensure topping product type
        $toppingType = ProductType::firstOrCreate(
            ['brand_id' => $brand->id, 'code' => 'topping'],
            [
                'organization_id' => $orgId,
                'name' => 'Topping',
                'product_form' => 'physical',
            ]
        );

        // 3. Get active branch menu
        $menu = Menu::where('brand_id', $brand->id)
            ->whereNotNull('branch_id')
            ->where('status', 'active')
            ->first();

        if (! $menu) {
            $this->command->error('No active branch menu found.');

            return;
        }

        // 4. Get or create featured section
        $featuredSection = $menu->menuSections()
            ->where('name', 'like', '%おすすめ%')
            ->orWhere('name', 'like', '%Featured%')
            ->first();

        if (! $featuredSection) {
            $featuredSection = MenuSection::create(['name' => 'おすすめ', 'is_featured' => true]);
            $menu->menuSections()->attach($featuredSection->id, ['display_order' => 0]);
        }

        // 5. Create combo product
        $combo = Product::create([
            'id' => (string) Str::ulid(),
            'brand_id' => $brand->id,
            'organization_id' => $orgId,
            'product_type_id' => $comboType->id,
            'name' => 'Combo Phở Sáng',
            'description' => 'Phở nóng hổi + đồ uống tự chọn, bữa sáng hoàn hảo',
            'slug' => 'combo-pho-sang-'.Str::random(4),
            'status' => 'active',
        ]);

        $comboSku = ProductSku::create([
            'id' => (string) Str::ulid(),
            'product_id' => $combo->id,
            'sku' => 'COMBO-MORNING-'.Str::random(4),
            'name' => 'Combo Phở Sáng',
            'selling_price' => 980,
            'option_signature' => '||',
        ]);

        $this->command->info("Created combo: {$combo->name} (¥980)");

        // 6. Create topping group 1: "Chọn phở (1 món)"
        $phoGroup = ToppingGroup::create([
            'id' => (string) Str::ulid(),
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'name' => 'Chọn phở (1 món)',
            'price_strategy' => 'flat',
            'modifier_type' => 'add',
            'selection_type' => 'single',
            'min_select' => 1,
            'max_select' => 1,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // Attach to combo
        ProductToppingGroup::create([
            'product_id' => $combo->id,
            'topping_group_id' => $phoGroup->id,
            'sort_order' => 1,
        ]);

        // Get existing pho products (find by name + ensure they have Size options)
        $beefPho = Product::where('brand_id', $brand->id)
            ->where('name', 'like', '%Beef%Pho%')
            ->orWhere('slug', 'like', '%pho-bo%')
            ->has('options') // Must have options (e.g. Size)
            ->with('skus')
            ->first();

        $chickenPho = Product::where('brand_id', $brand->id)
            ->where('name', 'like', '%Chicken%Pho%')
            ->orWhere('slug', 'like', '%pho-ga%')
            ->has('options')
            ->with('skus')
            ->first();

        if (! $beefPho || ! $chickenPho) {
            $this->command->error('Beef Pho or Chicken Pho not found (must have Size options). Ensure database was seeded earlier.');

            return;
        }

        // Helper: create topping item + SKU price overrides
        $addPhoItem = function (Product $pho, int $sortOrder, int $regularExtra, int $largeExtra) use ($phoGroup) {
            $item = ToppingGroupItem::create([
                'id' => (string) Str::ulid(),
                'topping_group_id' => $phoGroup->id,
                'product_id' => $pho->id,
                'sort_order' => $sortOrder,
                'is_default' => $sortOrder === 1,
            ]);

            // Get the 2 SKUs (Regular / Large)
            $regularSku = ProductSku::where('product_id', $pho->id)
                ->where('option_signature', 'like', '%-R')
                ->first();
            $largeSku = ProductSku::where('product_id', $pho->id)
                ->where('option_signature', 'like', '%-L')
                ->first();

            if ($regularSku) {
                ToppingGroupItemSku::create([
                    'id' => (string) Str::ulid(),
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => $regularSku->id,
                    'extra_price' => $regularExtra,
                ]);
            }

            if ($largeSku) {
                ToppingGroupItemSku::create([
                    'id' => (string) Str::ulid(),
                    'topping_group_item_id' => $item->id,
                    'product_sku_id' => $largeSku->id,
                    'extra_price' => $largeExtra,
                ]);
            }

            $this->command->info("  Added {$pho->name}: Regular +¥{$regularExtra}, Large +¥{$largeExtra}");
        };

        $addPhoItem($beefPho, 1, 0, 100);        // Phở Bò: Regular +0, Large +100
        $addPhoItem($chickenPho, 2, 0, 100);     // Phở gà: Regular +0, Large +100

        // 7. Create topping group 2: "Chọn đồ uống (1 món)"
        $drinkGroup = ToppingGroup::create([
            'id' => (string) Str::ulid(),
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'name' => 'Chọn đồ uống (1 món)',
            'price_strategy' => 'flat',
            'modifier_type' => 'add',
            'selection_type' => 'single',
            'min_select' => 1,
            'max_select' => 1,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        ProductToppingGroup::create([
            'product_id' => $combo->id,
            'topping_group_id' => $drinkGroup->id,
            'sort_order' => 2,
        ]);

        // Create simple drink topping products (no variants)
        $drinks = [
            ['name' => 'Trà đá', 'extra' => 0],
            ['name' => 'Nước dừa tươi', 'extra' => 0],
            ['name' => 'Chè thái', 'extra' => 100],
        ];

        foreach ($drinks as $idx => $d) {
            $drink = Product::create([
                'id' => (string) Str::ulid(),
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'product_type_id' => $toppingType->id,
                'name' => $d['name'],
                'slug' => Str::slug($d['name']).'-'.Str::random(4),
                'status' => 'active',
            ]);

            $drinkSku = ProductSku::create([
                'id' => (string) Str::ulid(),
                'product_id' => $drink->id,
                'sku' => strtoupper(substr(preg_replace('/[^a-z]/i', '', $d['name']), 0, 4)).'-'.Str::random(4),
                'name' => $d['name'],
                'option_signature' => '||',
            ]);

            $item = ToppingGroupItem::create([
                'id' => (string) Str::ulid(),
                'topping_group_id' => $drinkGroup->id,
                'product_id' => $drink->id,
                'sort_order' => $idx + 1,
                'is_default' => $idx === 0,
            ]);

            // Simple topping — 1 SKU, product_sku_id = NULL
            ToppingGroupItemSku::create([
                'id' => (string) Str::ulid(),
                'topping_group_item_id' => $item->id,
                'product_sku_id' => null,
                'extra_price' => $d['extra'],
            ]);

            $this->command->info("  Added {$d['name']}: +¥{$d['extra']}");
        }

        // 8. Attach combo to ALL branch menus (not just the first one)
        $allMenus = Menu::where('brand_id', $brand->id)
            ->whereNotNull('branch_id')
            ->where('status', 'active')
            ->get();

        $addedCount = 0;
        foreach ($allMenus as $targetMenu) {
            $section = $targetMenu->menuSections()
                ->where('name', 'like', '%おすすめ%')
                ->orWhere('name', 'like', '%Featured%')
                ->first();

            if (! $section) {
                $section = MenuSection::create(['name' => 'おすすめ', 'is_featured' => true]);
                $targetMenu->menuSections()->attach($section->id, ['display_order' => 0]);
            }

            // Skip if combo already exists in this menu
            $exists = MenuProduct::where('menu_id', $targetMenu->id)
                ->where('product_id', $combo->id)
                ->exists();

            if ($exists) {
                $this->command->info("  Skipped {$targetMenu->name} (combo already exists)");

                continue;
            }

            $maxOrder = MenuProduct::where('menu_id', $targetMenu->id)->max('display_order') ?? 0;
            $menuProduct = MenuProduct::create([
                'id' => (string) Str::ulid(),
                'menu_id' => $targetMenu->id,
                'product_id' => $combo->id,
                'menu_section_id' => $section->id,
                'is_active' => true,
                'display_order' => $maxOrder + 1,
            ]);

            MenuProductSku::create([
                'menu_product_id' => $menuProduct->id,
                'product_sku_id' => $comboSku->id,
                'selling_price' => 980,
                'is_active' => true,
            ]);

            $this->command->info("  ✓ Added to {$targetMenu->name}");
            $addedCount++;
        }

        $this->command->info("✅ Combo added to {$addedCount} menus");
        $this->command->info('Done! Run customer-web to see the combo with variant selection.');
    }
}
