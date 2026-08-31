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
use Illuminate\Support\Facades\DB;

/**
 * Seeds combo products for every brand so the POS, customer-web, and
 * workstation menus all have combo data to render from a fresh
 * `migrate:fresh --seed`.
 *
 * A combo is a Product with `product_type.code = 'combo'` plus topping
 * groups whose items reference existing brand products (the "main",
 * "drink", "side" choices the customer picks at order time). Pricing
 * is a fixed base price on the parent SKU; per-item upgrades go on
 * `topping_group_item_skus.extra_price`.
 *
 * Per-brand profile:
 *   - betoya       → ハッピーアワーセット (¥1580), ディナーフィースト (¥3980)
 *   - beto-kitchen → ランチセット (¥1380),         ファミリーセット (¥3580)
 *   - beto-coffee  → モーニングセット (¥780),      カフェスイーツセット (¥980)
 *
 * Combos are attached to every active branch menu in the brand under the
 * `💰 ランチセット` section (already created by MenuSeeder). Coffee combos
 * fall back to creating the section themselves since the lunch section is
 * not part of the coffee menu's "best/main/side/drink/lunch" canon for
 * every product layout — but MenuSeeder seeds it anyway, so this branch
 * is defensive only.
 *
 * Idempotency:
 *   - Combo product keyed on `(brand_id, slug)` via firstOrCreate (slug is
 *     scoped per brand by the products_brand_id_slug_unique constraint).
 *   - SKU keyed on `(product_id, option_signature='||')`.
 *   - Topping groups + items: re-runs detect existing
 *     `product_topping_groups` rows on the combo and skip the entire
 *     group block. (Modifying group counts on a re-run is intentionally
 *     unsupported — drop the existing combo first if you need to reshape.)
 *   - Menu attachment: `MenuProduct::firstOrCreate` keyed on
 *     `(menu_id, product_id, menu_section_id)`.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=BrandComboSeeder
 */
class BrandComboSeeder extends Seeder
{
    private const SECTION_NAME = 'ランチセット';

    /**
     * @var array<string, array<int, array{
     *     slug: string,
     *     name: string,
     *     description: string,
     *     price: int,
     *     groups: array<int, array{
     *         name: string,
     *         type: 'single'|'multiple',
     *         min: int,
     *         max: int,
     *         items: array<int, array{slug: string, extra: int}>,
     *     }>,
     * }>>
     */
    private const COMBO_PROFILES = [
        'beto-coffee' => [
            [
                'slug' => 'morning-set',
                'name' => 'モーニングセット',
                'description' => 'お好みのドリンク + ベトナムスイーツ — 朝食にぴったり',
                'price' => 780,
                'groups' => [
                    [
                        'name' => 'ドリンク (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'ca-phe-sua', 'extra' => 0],
                            ['slug' => 'ca-phe-trung', 'extra' => 100],
                            ['slug' => 'tra-hoa-nhai', 'extra' => 0],
                        ],
                    ],
                    [
                        'name' => 'スイーツ (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'banh-flan', 'extra' => 0],
                            ['slug' => 'che', 'extra' => 0],
                            ['slug' => 'xoi-hat-sen', 'extra' => 100],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'cafe-sweets-set',
                'name' => 'カフェスイーツセット',
                'description' => 'ホット or アイスドリンク + ダブルスイーツ',
                'price' => 980,
                'groups' => [
                    [
                        'name' => 'ドリンク (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'ca-phe-sua', 'extra' => 0],
                            ['slug' => 'ca-phe-trung', 'extra' => 100],
                            ['slug' => 'ca-phe-dua', 'extra' => 100],
                        ],
                    ],
                    [
                        'name' => 'スイーツ (2つ選択)',
                        'type' => 'multiple', 'min' => 2, 'max' => 2,
                        'items' => [
                            ['slug' => 'banh-flan', 'extra' => 0],
                            ['slug' => 'che', 'extra' => 0],
                            ['slug' => 'xoi-hat-sen', 'extra' => 100],
                        ],
                    ],
                ],
            ],
        ],
        'beto-kitchen' => [
            [
                'slug' => 'lunch-set',
                'name' => 'ランチセット',
                'description' => 'お好みのメイン + ドリンク付き — 平日ランチに最適',
                'price' => 1380,
                'groups' => [
                    [
                        'name' => 'メイン (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'pho-bo', 'extra' => 0],
                            ['slug' => 'pho-ga', 'extra' => 0],
                            ['slug' => 'banh-mi', 'extra' => -200],
                            ['slug' => 'com-tam', 'extra' => 100],
                        ],
                    ],
                    [
                        'name' => 'ドリンク (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'ca-phe-sua', 'extra' => 0],
                            ['slug' => 'tra-hoa-nhai', 'extra' => 0],
                            ['slug' => 'sinh-to', 'extra' => 100],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'family-set',
                'name' => 'ファミリーセット',
                'description' => 'メイン2品 + サイド + ドリンク2杯 — 4人前',
                'price' => 3580,
                'groups' => [
                    [
                        'name' => 'メイン1品目 (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'pho-bo', 'extra' => 0],
                            ['slug' => 'pho-ga', 'extra' => 0],
                            ['slug' => 'bun-cha', 'extra' => 100],
                            ['slug' => 'com-tam', 'extra' => 100],
                        ],
                    ],
                    [
                        'name' => 'メイン2品目 (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'pho-bo', 'extra' => 0],
                            ['slug' => 'pho-ga', 'extra' => 0],
                            ['slug' => 'banh-mi', 'extra' => -100],
                            ['slug' => 'banh-xeo', 'extra' => 100],
                        ],
                    ],
                    [
                        'name' => 'サイド (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'goi-cuon', 'extra' => 0],
                            ['slug' => 'cha-gio', 'extra' => 0],
                            ['slug' => 'goi-du-du', 'extra' => 100],
                        ],
                    ],
                    [
                        'name' => 'ドリンク (2つ選択)',
                        'type' => 'multiple', 'min' => 2, 'max' => 2,
                        'items' => [
                            ['slug' => 'ca-phe-sua', 'extra' => 0],
                            ['slug' => 'tra-hoa-nhai', 'extra' => 0],
                            ['slug' => 'sinh-to', 'extra' => 100],
                        ],
                    ],
                ],
            ],
        ],
        'betoya' => [
            [
                'slug' => 'happy-hour-set',
                'name' => 'ハッピーアワーセット',
                'description' => '人気メイン + おすすめドリンク — 17時〜19時のお得セット',
                'price' => 1580,
                'groups' => [
                    [
                        'name' => 'メイン (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'pho-bo', 'extra' => 0],
                            ['slug' => 'bun-bo-hue', 'extra' => 100],
                            ['slug' => 'banh-xeo', 'extra' => 0],
                        ],
                    ],
                    [
                        'name' => 'ドリンク (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'bia-saigon', 'extra' => 0],
                            ['slug' => 'bia-333', 'extra' => 0],
                            ['slug' => 'tra-hoa-nhai', 'extra' => -100],
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'dinner-feast-set',
                'name' => 'ディナーフィースト',
                'description' => 'メイン2 + 揚げ春巻き + ドリンク2 — 仲間とシェア',
                'price' => 3980,
                'groups' => [
                    [
                        'name' => 'メイン1品目 (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'pho-bo', 'extra' => 0],
                            ['slug' => 'bun-bo-hue', 'extra' => 100],
                            ['slug' => 'banh-xeo', 'extra' => 0],
                            ['slug' => 'com-tam', 'extra' => 0],
                        ],
                    ],
                    [
                        'name' => 'メイン2品目 (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'pho-ga', 'extra' => 0],
                            ['slug' => 'bun-cha', 'extra' => 0],
                            ['slug' => 'banh-cuon', 'extra' => 100],
                        ],
                    ],
                    [
                        'name' => 'サイド (1つ選択)',
                        'type' => 'single', 'min' => 1, 'max' => 1,
                        'items' => [
                            ['slug' => 'cha-gio', 'extra' => 0],
                            ['slug' => 'goi-cuon', 'extra' => 0],
                        ],
                    ],
                    [
                        'name' => 'ドリンク (2つ選択)',
                        'type' => 'multiple', 'min' => 2, 'max' => 2,
                        'items' => [
                            ['slug' => 'bia-saigon', 'extra' => 0],
                            ['slug' => 'ca-phe-sua', 'extra' => 0],
                            ['slug' => 'tra-hoa-nhai', 'extra' => 0],
                            ['slug' => 'sinh-to', 'extra' => 100],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * ja → {en, vi} for every topping-group name used across COMBO_PROFILES.
     * The profile arrays key the group by its Japanese `name`; this maps that
     * base name to the other two locales so seedToppingGroups() can populate
     * topping_group_translations for all three (without it a vi/en viewer sees
     * the raw Japanese group name — the bug this seeder previously shipped).
     *
     * @var array<string, array{en: string, vi: string}>
     */
    private const GROUP_NAME_TRANSLATIONS = [
        'メイン (1つ選択)' => ['en' => 'Main (pick 1)', 'vi' => 'Món chính (chọn 1)'],
        'メイン1品目 (1つ選択)' => ['en' => 'Main dish 1 (pick 1)', 'vi' => 'Món chính 1 (chọn 1)'],
        'メイン2品目 (1つ選択)' => ['en' => 'Main dish 2 (pick 1)', 'vi' => 'Món chính 2 (chọn 1)'],
        'サイド (1つ選択)' => ['en' => 'Side (pick 1)', 'vi' => 'Món phụ (chọn 1)'],
        'ドリンク (1つ選択)' => ['en' => 'Drink (pick 1)', 'vi' => 'Đồ uống (chọn 1)'],
        'ドリンク (2つ選択)' => ['en' => 'Drinks (pick 2)', 'vi' => 'Đồ uống (chọn 2)'],
        'スイーツ (1つ選択)' => ['en' => 'Sweets (pick 1)', 'vi' => 'Đồ ngọt (chọn 1)'],
        'スイーツ (2つ選択)' => ['en' => 'Sweets (pick 2)', 'vi' => 'Đồ ngọt (chọn 2)'],
    ];

    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->warn('No organization found.');

            return;
        }

        $brands = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($brands->isEmpty()) {
            $this->command->warn('No active brands — run MockDataSeeder first.');

            return;
        }

        $totalCombos = 0;
        $totalAttachments = 0;

        foreach ($brands as $brand) {
            $profiles = self::COMBO_PROFILES[$brand->slug] ?? null;

            if (! $profiles) {
                $this->command->warn("  skip brand {$brand->slug} — no combo profile defined");

                continue;
            }

            $comboType = ProductType::where('brand_id', $brand->id)
                ->where('code', 'combo')
                ->first();

            if (! $comboType) {
                $this->command->warn("  skip brand {$brand->slug} — no 'combo' ProductType (run BrandCoreCatalogSeeder first)");

                continue;
            }

            foreach ($profiles as $profile) {
                [$createdCombo, $attachedMenus] = $this->seedCombo($org, $brand, $comboType, $profile);
                if ($createdCombo) {
                    $totalCombos++;
                }
                $totalAttachments += $attachedMenus;
            }
        }

        $this->command->info(
            "BrandComboSeeder: {$totalCombos} combo product(s) created, {$totalAttachments} branch-menu attachment(s)."
        );
    }

    /**
     * @param  array{slug: string, name: string, description: string, price: int, groups: array}  $profile
     * @return array{0: bool, 1: int} [created?, attachedMenusCount]
     */
    private function seedCombo(Organization $org, Brand $brand, ProductType $comboType, array $profile): array
    {
        $combo = Product::firstOrCreate(
            ['brand_id' => $brand->id, 'slug' => $profile['slug']],
            [
                'organization_id' => $org->id,
                'product_type_id' => $comboType->id,
                'name' => $profile['name'],
                'description' => $profile['description'],
                'status' => 'active',
                'is_hidden' => false,
            ],
        );

        // ProductSkuObserver::saving recomputes `option_signature` from
        // option_value{1,2,3}_id; for a no-option combo SKU all three are
        // null → signature is ''. Match that here so re-runs find the
        // existing row instead of colliding on the (product_id,
        // option_signature) unique index.
        $sku = ProductSku::firstOrCreate(
            ['product_id' => $combo->id, 'option_signature' => ''],
            [
                'sku' => $this->buildSkuCode($brand->slug, $profile['slug']),
                'name' => $profile['name'],
                // issue #875 — selling_price is the menu price; cost_price stays 0
                // (auto-derived from recipe later).
                'selling_price' => $profile['price'],
                'cost_price' => 0,
                'is_active' => true,
            ],
        );

        // Skip group/item creation when the combo is already wired up. A
        // re-seed only re-checks menu attachment so the combo continues
        // to surface in branches that may have been added since the last
        // run (e.g. a new shop with cloned branch menus).
        $hasGroups = ProductToppingGroup::where('product_id', $combo->id)->exists();

        if (! $hasGroups) {
            $this->seedToppingGroups($org, $brand, $combo, $profile['groups']);
        }

        $attached = $this->attachToBranchMenus($brand, $combo, $sku);

        if ($combo->wasRecentlyCreated) {
            $this->command->info("  + combo \"{$profile['name']}\" (¥{$profile['price']}) for brand {$brand->slug} → {$attached} menus");
        }

        return [$combo->wasRecentlyCreated, $attached];
    }

    /**
     * @param  array<int, array{name: string, type: string, min: int, max: int, items: array<int, array{slug: string, extra: int}>}>  $groups
     */
    private function seedToppingGroups(Organization $org, Brand $brand, Product $combo, array $groups): void
    {
        foreach ($groups as $sortOrder => $groupData) {
            $group = ToppingGroup::create([
                'organization_id' => $org->id,
                'brand_id' => $brand->id,
                'name' => $groupData['name'],
                'modifier_type' => 'add',
                'price_strategy' => 'flat',
                'selection_type' => $groupData['type'],
                'min_select' => $groupData['min'],
                'max_select' => $groupData['max'],
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);

            $this->syncGroupTranslations($group, $groupData['name']);

            ProductToppingGroup::create([
                'product_id' => $combo->id,
                'topping_group_id' => $group->id,
                'sort_order' => $sortOrder,
            ]);

            foreach ($groupData['items'] as $idx => $itemData) {
                $itemProduct = Product::where('brand_id', $brand->id)
                    ->where('slug', $itemData['slug'])
                    ->first();

                if (! $itemProduct) {
                    $this->command->warn(
                        "    missing item product slug={$itemData['slug']} in brand {$brand->slug} — skipping"
                    );

                    continue;
                }

                $item = ToppingGroupItem::create([
                    'topping_group_id' => $group->id,
                    'product_id' => $itemProduct->id,
                    'sort_order' => $idx,
                    'is_default' => $idx === 0,
                ]);

                $activeSkus = ProductSku::where('product_id', $itemProduct->id)
                    ->where('is_active', true)
                    ->get();

                // Per-SKU pricing when the item product has multiple
                // active SKUs (e.g. Phở Bò Regular vs. Large) so the
                // picker can charge a variant-specific upcharge. Falls
                // back to a single product_sku_id=NULL row for simple
                // toppings (single-SKU drinks/sides).
                if ($activeSkus->count() > 1) {
                    foreach ($activeSkus as $itemSku) {
                        ToppingGroupItemSku::create([
                            'topping_group_item_id' => $item->id,
                            'product_sku_id' => $itemSku->id,
                            'extra_price' => $itemData['extra'],
                        ]);
                    }
                } else {
                    ToppingGroupItemSku::create([
                        'topping_group_item_id' => $item->id,
                        'product_sku_id' => $activeSkus->first()?->id,
                        'extra_price' => $itemData['extra'],
                    ]);
                }
            }
        }
    }

    /**
     * Populate topping_group_translations for all three locales. The Japanese
     * `name` is already stored on the base row; this adds the ja row too (so
     * the translation table is complete) plus en/vi from the lookup map. Groups
     * with no mapping entry get only the ja row — still better than a locale
     * with no translation at all.
     */
    private function syncGroupTranslations(ToppingGroup $group, string $jaName): void
    {
        $names = [
            'ja' => $jaName,
            'en' => self::GROUP_NAME_TRANSLATIONS[$jaName]['en'] ?? null,
            'vi' => self::GROUP_NAME_TRANSLATIONS[$jaName]['vi'] ?? null,
        ];

        foreach ($names as $locale => $name) {
            if ($name === null) {
                continue;
            }

            DB::table('topping_group_translations')->updateOrInsert(
                ['topping_group_id' => $group->id, 'locale' => $locale],
                ['name' => $name],
            );
        }
    }

    private function attachToBranchMenus(Brand $brand, Product $combo, ProductSku $sku): int
    {
        $menus = Menu::where('brand_id', $brand->id)
            ->whereNotNull('branch_id')
            ->whereNotNull('master_menu_id')
            ->where('status', 'Active')
            ->get();

        $attached = 0;

        foreach ($menus as $menu) {
            $section = $menu->menuSections()
                ->where('menu_sections.name', self::SECTION_NAME)
                ->first();

            if (! $section) {
                $section = MenuSection::firstOrCreate(['name' => self::SECTION_NAME]);
                $menu->menuSections()->syncWithoutDetaching([
                    $section->id => ['display_order' => 99],
                ]);
            }

            $maxOrder = MenuProduct::where('menu_id', $menu->id)->max('display_order') ?? 0;

            $menuProduct = MenuProduct::firstOrCreate(
                [
                    'menu_id' => $menu->id,
                    'product_id' => $combo->id,
                    'menu_section_id' => $section->id,
                ],
                [
                    'is_active' => true,
                    'display_order' => $maxOrder + 1,
                ],
            );

            MenuProductSku::firstOrCreate(
                [
                    'menu_product_id' => $menuProduct->id,
                    'product_sku_id' => $sku->id,
                ],
                [
                    'selling_price' => $sku->selling_price,
                    'is_active' => true,
                    'is_price_overridden' => false,
                ],
            );

            if ($menuProduct->wasRecentlyCreated) {
                $attached++;
            }
        }

        return $attached;
    }

    private function buildSkuCode(string $brandSlug, string $comboSlug): string
    {
        $brandPart = strtoupper(substr(preg_replace('/[^a-z]/i', '', $brandSlug), 0, 3));
        $comboPart = strtoupper(substr(preg_replace('/[^a-z]/i', '', $comboSlug), 0, 6));

        return "COMBO-{$brandPart}-{$comboPart}";
    }
}
