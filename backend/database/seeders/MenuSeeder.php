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
use App\Omnify\Enums\MenuStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds menus for all brands — 1 master menu per brand + cloned branch menus.
 *
 * Schema (post 2026-04-14 multi-section update):
 *   Menu
 *   ├── menu_menu_sections   (Menu ↔ MenuSection)
 *   ├── MenuProduct
 *   │   ├── menu_product_sections  (MenuProduct ↔ MenuSection — multi-section M2M)
 *   │   └── MenuProductSku         (per-SKU pricing)
 *   └── (legacy) menu_products.menu_section_id is left null
 *
 * Section layout (5 sections per menu):
 *   - 🔥 おすすめ (Best Seller) — curated subset, also appears in their category section
 *   - 🍜 メイン                  — all FOOD with category CAT-MAIN
 *   - 🥗 サイド                  — all FOOD with category CAT-SIDE
 *   - 🥤 ドリンク                — all DRINK
 *   - 💰 ランチセット            — combo curation, products also live in their category section
 *
 * Branch menus reuse the SAME menu_sections rows as their master (shared, not cloned).
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=MenuSeeder
 */
class MenuSeeder extends Seeder
{
    /**
     * @var array<string, string> key => display name
     */
    /**
     * #1187 — names carry no emoji and "featured" is a real flag. The customer
     * carousel used to detect the best-seller section by finding an emoji in
     * this very string, so the decoration was load-bearing; it is not anymore.
     *
     * @var array<string, array{name: string, featured: bool}>
     */
    private const SECTION_DEFS = [
        'best' => ['name' => 'おすすめ', 'featured' => true],
        'main' => ['name' => 'メイン', 'featured' => false],
        'side' => ['name' => 'サイド', 'featured' => false],
        'drink' => ['name' => 'ドリンク', 'featured' => false],
        'lunch' => ['name' => 'ランチセット', 'featured' => false],
    ];

    /**
     * @var array<int, string> product slugs that appear in Best Seller
     */
    private const BEST_SELLER_SLUGS = [
        'pho-bo',
        'banh-mi',
        'bun-cha',
        'goi-cuon',
        'ca-phe-sua',
        'ca-phe-trung',
    ];

    /**
     * @var array<int, string> product slugs that appear in the lunch combo
     */
    private const LUNCH_COMBO_SLUGS = [
        'pho-bo',
        'com-tam',
        'banh-mi',
        'ca-phe-sua',
    ];

    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->warn('No org found.');

            return;
        }

        $brands = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        $branches = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->where('is_headquarters', false)
            ->get();

        foreach ($brands as $brand) {
            $this->seedForBrand($org->id, $brand, $branches);
        }
    }

    /**
     * @param  Collection<int, Branch>  $branches
     */
    private function seedForBrand(string $orgId, Brand $brand, Collection $branches): void
    {
        $products = Product::where('brand_id', $brand->id)
            ->where('status', 'active')
            ->with([
                'skus' => fn ($q) => $q->where('is_active', true),
                'productType',
                'categories',
            ])
            ->get();

        if ($products->isEmpty()) {
            $this->command->warn("  {$brand->name}: no active products — skipping.");

            return;
        }

        // ── Master menu ──────────────────────────────────────
        $master = Menu::firstOrCreate(
            ['organization_id' => $orgId, 'brand_id' => $brand->id, 'is_master' => true],
            [
                'name' => "{$brand->name} マスターメニュー",
                'status' => MenuStatusEnum::Active->value,
                'branch_id' => null,
                'priority' => 0,
            ]
        );

        $masterStats = $this->seedMenuLayout($master, $products, null);
        $this->command->info("  {$brand->name}: master — {$masterStats['rows']} menu_products rows across {$masterStats['sections']} sections, {$masterStats['skus']} SKUs");

        // ── Branch menus ─────────────────────────────────────
        foreach ($branches as $branch) {
            $nextPriority = (int) Menu::where('branch_id', $branch->id)->max('priority') + 1;

            $branchMenu = Menu::firstOrCreate(
                ['organization_id' => $orgId, 'brand_id' => $brand->id, 'branch_id' => $branch->id, 'is_master' => false, 'master_menu_id' => $master->id],
                [
                    'name' => "{$brand->name} — {$branch->name}",
                    'status' => MenuStatusEnum::Active->value,
                    'priority' => $nextPriority,
                    'last_synced_at' => now(),
                ]
            );

            $branchStats = $this->seedMenuLayout($branchMenu, $products, $master);
            $this->command->info("    {$branch->name}: {$branchStats['rows']} menu_products rows across {$branchStats['sections']} sections, {$branchStats['skus']} SKUs");
        }
    }

    /**
     * Build the full layout (sections + products + section pivot + SKUs) for one menu.
     *
     * @param  Collection<int, Product>  $products
     * @return array{sections: int, rows: int, skus: int}
     */
    private function seedMenuLayout(Menu $menu, Collection $products, ?Menu $master): array
    {
        $sections = $this->ensureSections($menu, $master);

        $order = 1;
        $rowCount = 0;
        $skuCount = 0;

        $markups = [
            'FOOD' => [2.5, 3.0],
            'DRINK' => [3.0, 4.0],
        ];

        foreach ($products as $product) {
            if ($product->skus->isEmpty()) {
                continue;
            }

            // For each (product, section) pair the product should belong to,
            // create an own menu_products row. Multi-section is encoded as
            // multiple rows; the composite unique on (menu_id, product_id,
            // menu_section_id) protects against duplicates within the same pair.
            $sectionIds = $this->resolveSectionIdsForProduct($product, $sections);

            foreach ($sectionIds as $sectionId) {
                // Find matching master row so the branch row can link via
                // master_menu_product_id (used by check-sync / sync-from-master).
                $masterMenuProduct = null;
                if ($master) {
                    $masterMenuProduct = MenuProduct::where('menu_id', $master->id)
                        ->where('product_id', $product->id)
                        ->where('menu_section_id', $sectionId)
                        ->first();
                }

                $menuProduct = MenuProduct::firstOrCreate(
                    [
                        'menu_id' => $menu->id,
                        'product_id' => $product->id,
                        'menu_section_id' => $sectionId,
                    ],
                    [
                        'is_active' => true,
                        'display_order' => $order++,
                        'master_menu_product_id' => $masterMenuProduct?->id,
                    ],
                );

                if ($menuProduct->wasRecentlyCreated) {
                    $rowCount++;
                }

                // SKU pricing — each menu_products row gets its own set.
                $typeCode = $product->productType?->code ?? 'FOOD';
                [$minMarkup, $maxMarkup] = $markups[$typeCode] ?? [2.5, 3.0];

                foreach ($product->skus as $sku) {
                    // DETERMINISTIC per-SKU pricing. Was `mt_rand` per (section,
                    // sku): the same SKU sits in >1 section of a menu (e.g. its
                    // category section + おすすめ), so each row rolled its OWN
                    // markup → one SKU carried two prices in one menu, and the POS
                    // tile (reading one row) disagreed with add-to-cart (resolving
                    // the other). Seeding the markup from the SKU id makes every
                    // row for a SKU — across sections AND across master/branch
                    // clones — land on the SAME selling_price.
                    $frac = (crc32($sku->id) % 101) / 100.0; // stable 0.00–1.00
                    // issue #875 — the base price now lives in selling_price
                    // (cost_price is 0, auto-derived from recipe later).
                    $basePrice = (float) $sku->selling_price;
                    $rawPrice = $basePrice * ($minMarkup + $frac * ($maxMarkup - $minMarkup));
                    $sellingPrice = max(100, round($rawPrice / 50) * 50);

                    MenuProductSku::firstOrCreate(
                        ['menu_product_id' => $menuProduct->id, 'product_sku_id' => $sku->id],
                        [
                            'selling_price' => $sellingPrice,
                            'is_price_overridden' => false,
                            // Deterministic too — a SKU must not be active in one
                            // section and hidden in another within the same menu.
                            'is_active' => (crc32($sku->id.':active') % 100) < 90,
                        ],
                    );
                    $skuCount++;
                }
            }
        }

        return [
            'sections' => count($sections),
            'rows' => $rowCount,
            'skus' => $skuCount,
        ];
    }

    /**
     * Find or create the canonical 5 sections for a menu and ensure they are attached
     * via menu_menu_sections in the defined display order. For branch menus, sections
     * are SHARED with master (same row, attached to both pivots).
     *
     * @return array<string, MenuSection> key (best/main/side/drink/lunch) => MenuSection
     */
    private function ensureSections(Menu $menu, ?Menu $master): array
    {
        $result = [];
        $order = 1;

        foreach (self::SECTION_DEFS as $key => $def) {
            $name = $def['name'];
            $section = $menu->menuSections()->where('name', $name)->first();

            if (! $section) {
                if ($master !== null) {
                    $section = $master->menuSections()->where('name', $name)->first();
                }

                if (! $section) {
                    $section = MenuSection::create([
                        'name' => $name,
                        'is_featured' => $def['featured'],
                        'organization_id' => $menu->organization_id,
                        'brand_id' => $menu->brand_id,
                    ]);
                }

                $menu->menuSections()->attach($section->id, ['display_order' => $order]);
            }

            $result[$key] = $section;
            $order++;
        }

        return $result;
    }

    /**
     * Decide which sections a product belongs to. Always assigned to its category
     * section; optionally also Best Seller and/or Lunch combo (showcasing the
     * multi-row pattern where one product appears in multiple sections).
     *
     * @param  array<string, MenuSection>  $sections
     * @return array<int, string> list of section_id this product belongs to
     */
    private function resolveSectionIdsForProduct(Product $product, array $sections): array
    {
        $ids = [];

        // Category section (always exactly one)
        $typeCode = $product->productType?->code ?? 'FOOD';
        if ($typeCode === 'DRINK') {
            $ids[] = $sections['drink']->id;
        } else {
            $catSku = $product->categories->first()?->sku ?? 'CAT-MAIN';
            $key = $catSku === 'CAT-SIDE' ? 'side' : 'main';
            $ids[] = $sections[$key]->id;
        }

        if (in_array($product->slug, self::BEST_SELLER_SLUGS, true)) {
            $ids[] = $sections['best']->id;
        }

        if (in_array($product->slug, self::LUNCH_COMBO_SLUGS, true)) {
            $ids[] = $sections['lunch']->id;
        }

        return $ids;
    }
}
