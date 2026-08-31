<?php

namespace Database\Seeders;

use App\Models\Branch;
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
 * Seeds combo products into the featured (おすすめ) section of ALL sjk (新宿店) menus.
 *
 * Also cleans up orphaned menu_products pointing to deleted products.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=SjkComboFeaturedSeeder
 */
class SjkComboFeaturedSeeder extends Seeder
{
    public function run(): void
    {
        // 0. Clean up orphaned menu_products (product deleted but menu_product remains)
        $orphanCount = MenuProduct::whereDoesntHave('product')->count();
        if ($orphanCount > 0) {
            MenuProduct::whereDoesntHave('product')->delete();
            $this->command->info("Cleaned up {$orphanCount} orphaned menu_products.");
        }

        // 1. Find sjk branch
        $branch = Branch::where('slug', 'sjk')->first();
        if (! $branch) {
            $this->command->error('Branch "sjk" not found.');

            return;
        }
        $this->command->info("Branch: {$branch->name} (slug=sjk)");

        // 2. Get all active menus for sjk
        $menus = Menu::where('branch_id', $branch->id)
            ->where('status', 'Active')
            ->with('menuSections')
            ->get();

        if ($menus->isEmpty()) {
            $this->command->error('No active menus found for sjk.');

            return;
        }
        $this->command->info("Found {$menus->count()} active menus for sjk.");

        $org = Organization::first();
        $orgId = $org->id;

        // 3. Combo definitions — Vietnamese names for /vi/ locale
        $comboDefs = [
            [
                'name' => 'Combo Phở Đặc Biệt',
                'description' => 'Phở nóng hổi + món phụ + đồ uống tự chọn',
                'price' => 1580,
                'groups' => [
                    [
                        'name' => 'Chọn phở (1 món)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Phở Bò', 'price' => 0],
                            ['name' => 'Phở Gà', 'price' => 0],
                            ['name' => 'Phở Hải Sản', 'price' => 200],
                        ],
                    ],
                    [
                        'name' => 'Chọn món phụ (1 món)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Gỏi cuốn (2 cuốn)', 'price' => 0],
                            ['name' => 'Chả giò (3 cái)', 'price' => 0],
                            ['name' => 'Bánh mì (nửa ổ)', 'price' => 100],
                        ],
                    ],
                    [
                        'name' => 'Chọn đồ uống (1 món)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Cà phê sữa đá', 'price' => 0],
                            ['name' => 'Trà nhài', 'price' => 0],
                            ['name' => 'Sinh tố dừa', 'price' => 150],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Combo Bún Bò Huế',
                'description' => 'Bún bò Huế cay nồng + gỏi cuốn + nước uống',
                'price' => 1680,
                'groups' => [
                    [
                        'name' => 'Chọn độ cay',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Ít cay', 'price' => 0],
                            ['name' => 'Vừa', 'price' => 0],
                            ['name' => 'Siêu cay', 'price' => 0],
                        ],
                    ],
                    [
                        'name' => 'Chọn món phụ (1 món)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Gỏi cuốn (2 cuốn)', 'price' => 0],
                            ['name' => 'Bánh xèo (nửa cái)', 'price' => 100],
                        ],
                    ],
                    [
                        'name' => 'Chọn đồ uống (1 món)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Cà phê sữa đá', 'price' => 0],
                            ['name' => 'Trà sả', 'price' => 0],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Combo Bánh Mì Sáng',
                'description' => 'Bánh mì thịt nướng + cà phê Việt Nam',
                'price' => 880,
                'groups' => [
                    [
                        'name' => 'Chọn loại bánh mì',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Bánh mì thịt nướng', 'price' => 0],
                            ['name' => 'Bánh mì gà', 'price' => 0],
                            ['name' => 'Bánh mì chả lụa', 'price' => 0],
                        ],
                    ],
                    [
                        'name' => 'Chọn đồ uống (1 món)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'Cà phê đen đá', 'price' => 0],
                            ['name' => 'Cà phê sữa đá', 'price' => 0],
                            ['name' => 'Trà đá', 'price' => 0],
                        ],
                    ],
                ],
            ],
        ];

        // 4. Seed combos per brand-menu
        foreach ($menus as $menu) {
            $brandId = $menu->brand_id;
            $brand = Brand::find($brandId);
            if (! $brand) {
                $this->command->warn("Skipping menu {$menu->name}: brand not found.");

                continue;
            }

            $this->command->info("\n--- {$menu->name} (brand: {$brand->name}) ---");

            // Ensure combo product type for this brand
            $comboType = ProductType::firstOrCreate(
                ['brand_id' => $brandId, 'code' => 'combo'],
                [
                    'organization_id' => $orgId,
                    'name' => 'Combo',
                    'product_form' => 'physical',
                    'has_recipe' => false,
                    'is_inventory_tracked' => false,
                    'is_active' => true,
                ],
            );

            // Ensure topping product type for this brand
            $toppingType = ProductType::firstOrCreate(
                ['brand_id' => $brandId, 'code' => 'topping'],
                [
                    'organization_id' => $orgId,
                    'name' => 'Topping',
                    'product_form' => 'physical',
                ],
            );

            // Get or create featured section
            $featuredSection = $menu->menuSections()
                ->where('name', 'like', '%おすすめ%')
                ->first();

            if (! $featuredSection) {
                $featuredSection = MenuSection::create(['name' => 'おすすめ', 'is_featured' => true]);
                $menu->menuSections()->attach($featuredSection->id, ['display_order' => 0]);
            }

            // Check existing combos in this menu to avoid duplicates
            $existingCombos = MenuProduct::where('menu_id', $menu->id)
                ->whereHas('product', fn ($q) => $q->whereHas('productType', fn ($q2) => $q2->where('code', 'combo')))
                ->pluck('product_id')
                ->toArray();

            if (count($existingCombos) >= 3) {
                $this->command->info('  Already has '.count($existingCombos).' combos, skipping.');

                continue;
            }

            foreach ($comboDefs as $comboDef) {
                // Check if a combo with same name already exists for this brand
                $existingProduct = Product::where('brand_id', $brandId)
                    ->where('product_type_id', $comboType->id)
                    ->where('name', $comboDef['name'])
                    ->first();

                if ($existingProduct) {
                    // Just ensure it's in the menu
                    $inMenu = MenuProduct::where('menu_id', $menu->id)
                        ->where('product_id', $existingProduct->id)
                        ->exists();

                    if (! $inMenu) {
                        $this->addComboToMenu($menu, $existingProduct, $featuredSection);
                        $this->command->info("  Linked existing {$comboDef['name']} to menu.");
                    } else {
                        $this->command->info("  {$comboDef['name']} already in menu, skipped.");
                    }

                    continue;
                }

                // Create combo product
                $comboProduct = Product::create([
                    'brand_id' => $brandId,
                    'organization_id' => $orgId,
                    'product_type_id' => $comboType->id,
                    'name' => $comboDef['name'],
                    'description' => $comboDef['description'],
                    'slug' => Str::slug($comboDef['name']).'-'.Str::random(4),
                    'status' => 'active',
                ]);

                $comboSku = ProductSku::create([
                    'product_id' => $comboProduct->id,
                    'sku' => 'COMBO-'.strtoupper(Str::random(6)),
                    'name' => $comboDef['name'],
                    'selling_price' => $comboDef['price'],
                    'option_signature' => '||',
                ]);

                // Create topping groups
                foreach ($comboDef['groups'] as $sortOrder => $groupDef) {
                    $group = ToppingGroup::create([
                        'organization_id' => $orgId,
                        'brand_id' => $brandId,
                        'name' => $groupDef['name'],
                        'modifier_type' => 'add',
                        'selection_type' => $groupDef['selection_type'],
                        'min_select' => $groupDef['min_select'],
                        'max_select' => $groupDef['max_select'],
                        'is_active' => true,
                    ]);

                    ProductToppingGroup::create([
                        'product_id' => $comboProduct->id,
                        'topping_group_id' => $group->id,
                        'sort_order' => $sortOrder + 1,
                    ]);

                    foreach ($groupDef['items'] as $itemOrder => $itemDef) {
                        // Create topping product
                        $toppingProduct = Product::create([
                            'brand_id' => $brandId,
                            'organization_id' => $orgId,
                            'product_type_id' => $toppingType->id,
                            'name' => $itemDef['name'],
                            'slug' => Str::slug($itemDef['name']).'-'.Str::random(4),
                            'status' => 'active',
                        ]);

                        ProductSku::create([
                            'product_id' => $toppingProduct->id,
                            'sku' => strtoupper(substr(preg_replace('/[^a-z]/i', '', Str::ascii($itemDef['name'])), 0, 6)).'-'.Str::random(4),
                            'name' => $itemDef['name'],
                            'option_signature' => '||',
                        ]);

                        $toppingItem = ToppingGroupItem::create([
                            'topping_group_id' => $group->id,
                            'product_id' => $toppingProduct->id,
                            'sort_order' => $itemOrder + 1,
                            'is_default' => $itemOrder === 0,
                        ]);

                        ToppingGroupItemSku::create([
                            'topping_group_item_id' => $toppingItem->id,
                            'extra_price' => $itemDef['price'],
                        ]);
                    }
                }

                // Add combo to menu
                $this->addComboToMenu($menu, $comboProduct, $featuredSection);
                $this->command->info("  Created {$comboDef['name']} (¥{$comboDef['price']})");
            }
        }

        $this->command->info("\nDone! Refresh http://localhost:5450/vi/takeaway/sjk to see combo featured items.");
    }

    private function addComboToMenu(Menu $menu, Product $combo, MenuSection $section): void
    {
        $comboSku = ProductSku::where('product_id', $combo->id)->first();

        $maxOrder = MenuProduct::where('menu_id', $menu->id)->max('display_order') ?? 0;

        $menuProduct = MenuProduct::create([
            'menu_id' => $menu->id,
            'product_id' => $combo->id,
            'menu_section_id' => $section->id,
            'is_active' => true,
            'display_order' => $maxOrder + 1,
        ]);

        if ($comboSku) {
            MenuProductSku::create([
                'menu_product_id' => $menuProduct->id,
                'product_sku_id' => $comboSku->id,
                'selling_price' => $comboSku->selling_price,
                'is_active' => true,
            ]);
        }
    }
}
