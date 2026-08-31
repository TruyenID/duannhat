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
 * Seeds combo products for the customer-web featured section.
 *
 * Combo = product_type.code='combo', with topping groups that represent
 * the items included in the combo (main dish choices, drink choices, etc).
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=ComboProductSeeder
 */
class ComboProductSeeder extends Seeder
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

        // 1. Ensure combo product type exists
        $comboType = ProductType::firstOrCreate(
            ['brand_id' => $brand->id, 'code' => 'combo'],
            [
                'organization_id' => $orgId,
                'name' => 'Combo',
                'product_form' => 'physical',
                'has_recipe' => false,
                'is_inventory_tracked' => false,
                'is_active' => true,
            ],
        );
        $this->command->info("Combo ProductType: {$comboType->id}");

        // 2. Get topping product type (for items inside combos)
        $toppingType = ProductType::where('brand_id', $brand->id)
            ->where('code', 'topping')
            ->first();

        if (! $toppingType) {
            $toppingType = ProductType::firstOrCreate(
                ['brand_id' => $brand->id, 'code' => 'topping'],
                [
                    'organization_id' => $orgId,
                    'name' => 'Topping',
                    'product_form' => 'physical',
                ],
            );
        }

        // 3. Get active branch menu
        $menu = Menu::where('brand_id', $brand->id)
            ->whereNotNull('branch_id')
            ->where('status', 'active')
            ->first();

        if (! $menu) {
            $this->command->error('No active branch menu found.');

            return;
        }

        // 4. Get or create the featured section (M-N via menu_menu_sections)
        $featuredSection = $menu->menuSections()
            ->where('name', 'like', '%おすすめ%')
            ->first();

        if (! $featuredSection) {
            $featuredSection = MenuSection::create([
                'name' => 'おすすめ',
                'is_featured' => true,
            ]);
            $menu->menuSections()->attach($featuredSection->id, ['display_order' => 0]);
        }

        // 5. Helper to create a topping product
        $makeTopping = function (string $name) use ($brand, $orgId, $toppingType): Product {
            $product = Product::create([
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'product_type_id' => $toppingType->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(4),
                'status' => 'active',
            ]);

            ProductSku::create([
                'product_id' => $product->id,
                'sku' => strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 4)).'-'.Str::random(4),
                'name' => $name,
                'option_signature' => '||',
            ]);

            return $product;
        };

        // 6. Create combo products
        $combos = [
            [
                'name' => 'フォーランチセット',
                'description' => 'お好みのフォー + サイド + ドリンク付き',
                'price' => 1580,
                'groups' => [
                    [
                        'name' => 'メイン (1つ選択)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'ビーフフォー', 'price' => 0],
                            ['name' => 'チキンフォー', 'price' => 0],
                            ['name' => 'シーフードフォー', 'price' => 200],
                        ],
                    ],
                    [
                        'name' => 'サイド (1つ選択)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => '生春巻き (2本)', 'price' => 0],
                            ['name' => '揚げ春巻き (3個)', 'price' => 0],
                            ['name' => 'バインミー (ハーフ)', 'price' => 100],
                        ],
                    ],
                    [
                        'name' => 'ドリンク (1つ選択)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'ベトナムコーヒー', 'price' => 0],
                            ['name' => 'ジャスミン茶', 'price' => 0],
                            ['name' => 'ココナッツスムージー', 'price' => 150],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ブンボーフエセット',
                'description' => 'ピリ辛ブンボーフエ + 春巻き + ドリンク',
                'price' => 1680,
                'groups' => [
                    [
                        'name' => '辛さレベル',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'マイルド', 'price' => 0],
                            ['name' => '普通', 'price' => 0],
                            ['name' => '激辛', 'price' => 0],
                        ],
                    ],
                    [
                        'name' => 'サイド (1つ選択)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => '生春巻き (2本)', 'price' => 0],
                            ['name' => 'バインセオ (ハーフ)', 'price' => 100],
                        ],
                    ],
                    [
                        'name' => 'ドリンク (1つ選択)',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'ベトナムコーヒー', 'price' => 0],
                            ['name' => 'レモングラスティー', 'price' => 0],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ファミリーセット (4人前)',
                'description' => 'メイン2品 + サイド盛り合わせ + ドリンク4杯',
                'price' => 4980,
                'groups' => [
                    [
                        'name' => 'メイン1品目',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'ビーフフォー (大)', 'price' => 0],
                            ['name' => 'ブンチャー', 'price' => 0],
                            ['name' => 'ブンボーフエ (大)', 'price' => 0],
                        ],
                    ],
                    [
                        'name' => 'メイン2品目',
                        'selection_type' => 'single',
                        'min_select' => 1,
                        'max_select' => 1,
                        'items' => [
                            ['name' => 'チキンフォー (大)', 'price' => 0],
                            ['name' => 'コムタム', 'price' => 0],
                            ['name' => 'バインミー (4本)', 'price' => 0],
                        ],
                    ],
                    [
                        'name' => 'サイド盛り合わせ',
                        'selection_type' => 'multiple',
                        'min_select' => 2,
                        'max_select' => 3,
                        'items' => [
                            ['name' => '生春巻き (6本)', 'price' => 0],
                            ['name' => '揚げ春巻き (8個)', 'price' => 0],
                            ['name' => 'パパイヤサラダ', 'price' => 0],
                            ['name' => 'フライドワンタン', 'price' => 200],
                        ],
                    ],
                    [
                        'name' => 'ドリンク4杯',
                        'selection_type' => 'multiple',
                        'min_select' => 4,
                        'max_select' => 4,
                        'items' => [
                            ['name' => 'ベトナムコーヒー', 'price' => 0],
                            ['name' => 'ジャスミン茶', 'price' => 0],
                            ['name' => 'ココナッツスムージー', 'price' => 0],
                            ['name' => 'レモングラスティー', 'price' => 0],
                            ['name' => 'マンゴーシェイク', 'price' => 100],
                        ],
                    ],
                ],
            ],
        ];

        $created = 0;
        foreach ($combos as $comboData) {
            // Create the combo product
            $comboProduct = Product::create([
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'product_type_id' => $comboType->id,
                'name' => $comboData['name'],
                'description' => $comboData['description'],
                'slug' => Str::slug($comboData['name']).'-'.Str::random(4),
                'status' => 'active',
            ]);

            // Create default SKU
            $sku = ProductSku::create([
                'product_id' => $comboProduct->id,
                'sku' => 'COMBO-'.Str::random(6),
                'name' => $comboData['name'],
                'selling_price' => $comboData['price'],
                'option_signature' => '||',
            ]);

            // Create topping groups for this combo
            foreach ($comboData['groups'] as $sortOrder => $groupData) {
                $group = ToppingGroup::create([
                    'brand_id' => $brand->id,
                    'organization_id' => $orgId,
                    'name' => $groupData['name'],
                    'modifier_type' => 'add',
                    'selection_type' => $groupData['selection_type'],
                    'min_select' => $groupData['min_select'],
                    'max_select' => $groupData['max_select'],
                    'is_active' => true,
                ]);

                // Attach group to combo product
                ProductToppingGroup::create([
                    'product_id' => $comboProduct->id,
                    'topping_group_id' => $group->id,
                    'sort_order' => $sortOrder,
                ]);

                // Create topping items
                foreach ($groupData['items'] as $itemOrder => $itemData) {
                    $toppingProduct = $makeTopping($itemData['name']);

                    $toppingItem = ToppingGroupItem::create([
                        'topping_group_id' => $group->id,
                        'product_id' => $toppingProduct->id,
                        'sort_order' => $itemOrder,
                    ]);

                    ToppingGroupItemSku::create([
                        'topping_group_item_id' => $toppingItem->id,
                        'extra_price' => $itemData['price'],
                    ]);
                }
            }

            // Add combo to menu (featured section)
            $maxOrder = MenuProduct::where('menu_id', $menu->id)
                ->where('menu_section_id', $featuredSection->id)
                ->max('display_order') ?? 0;

            $menuProduct = MenuProduct::create([
                'id' => (string) Str::ulid(),
                'menu_id' => $menu->id,
                'product_id' => $comboProduct->id,
                'menu_section_id' => $featuredSection->id,
                'is_active' => true,
                'display_order' => $maxOrder + 1,
            ]);

            // Create MenuProductSku so the price shows up
            MenuProductSku::create([
                'menu_product_id' => $menuProduct->id,
                'product_sku_id' => $sku->id,
                'selling_price' => $comboData['price'],
                'is_active' => true,
            ]);

            $this->command->info("Created combo: {$comboData['name']} (¥{$comboData['price']})");
            $created++;
        }

        $this->command->info("Done! Created {$created} combo products in '{$featuredSection->name}' section.");
    }
}
