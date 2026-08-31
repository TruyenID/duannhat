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
 * Seeds a complete Vietnamese menu for Hanoi branch.
 *
 * Creates "Vietnamese Specialties Menu" with all traditional dishes:
 * - Phở (Beef/Chicken noodle soup)
 * - Bún (Vermicelli dishes)
 * - Cơm (Rice dishes)
 * - Bánh (Cakes and breads)
 * - Gỏi/Chả (Appetizers)
 * - Drinks (Coffee, tea, beer, smoothies)
 * - Desserts (Chè, flan)
 *
 * Pricing in VND (Vietnamese Dong) format.
 *
 * Usage:
 *   php artisan db:seed --class=HanoiFullMenuSeeder
 */
class HanoiFullMenuSeeder extends Seeder
{
    private const MENU_SECTIONS = [
        'pho' => '🍜 Phở',
        'bun' => '🥢 Bún',
        'com' => '🍚 Cơm',
        'banh' => '🥖 Bánh',
        'appetizer' => '🥗 Khai vị',
        'drink' => '☕ Đồ uống',
        'dessert' => '🍰 Tráng miệng',
    ];

    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->error('No organization found.');

            return;
        }

        // Find Hanoi branch
        $hanoi = Branch::where('slug', 'hanoi')
            ->where('console_organization_id', $org->console_organization_id)
            ->first();

        if (! $hanoi) {
            $this->command->error('Hanoi branch not found. Run GlobalMultiTimezoneSeeder first.');

            return;
        }

        // Get brand
        $brand = Brand::where('console_brand_id', $hanoi->console_brand_id)->first();

        if (! $brand) {
            $this->command->error('Brand not found for Hanoi branch.');

            return;
        }

        $this->command->info('Creating Vietnamese Specialties Menu for Hanoi...');

        // Create Vietnamese menu
        $vietnameseMenu = Menu::updateOrCreate(
            [
                'organization_id' => $org->id,
                'brand_id' => $brand->id,
                'branch_id' => $hanoi->id,
                'name' => 'Vietnamese Specialties — Hanoi',
            ],
            [
                'description' => 'Authentic Vietnamese cuisine menu featuring traditional dishes from North Vietnam',
                'status' => MenuStatusEnum::Active->value,
                'priority' => 150, // Between default (100) and evening (200)
                'is_master' => false,
                'last_synced_at' => now(),
            ]
        );

        // Create menu sections
        $sections = $this->createMenuSections($vietnameseMenu);

        // Get all products for this brand
        $products = Product::where('brand_id', $brand->id)
            ->where('status', 'active')
            ->with(['skus', 'productType'])
            ->get();

        // Add products to menu
        $productCount = $this->addProductsToMenu($vietnameseMenu, $products, $sections);

        $this->command->info('✓ Vietnamese Specialties Menu created');
        $this->command->info("  {$productCount} products across ".count($sections).' sections');
        $this->command->info('  Pricing in VND (Vietnamese Dong)');
    }

    private function createMenuSections(Menu $menu): Collection
    {
        $sections = collect();

        foreach (self::MENU_SECTIONS as $key => $name) {
            $section = MenuSection::firstOrCreate(
                ['name' => $name]
            );

            // Attach section to menu if not already attached
            if (! $menu->menuSections()->where('menu_sections.id', $section->id)->exists()) {
                $menu->menuSections()->attach($section->id, [
                    'display_order' => $sections->count() + 1,
                ]);
            }

            $sections->put($key, $section);
        }

        return $sections;
    }

    private function addProductsToMenu(Menu $menu, Collection $products, Collection $sections): int
    {
        $productCount = 0;
        $displayOrder = 1;

        // Product mapping to sections
        $productMapping = [
            // Phở section
            'pho' => ['pho-bo', 'pho-ga'],
            // Bún section
            'bun' => ['bun-cha', 'bun-bo-hue'],
            // Cơm section
            'com' => ['com-tam'],
            // Bánh section
            'banh' => ['banh-mi', 'banh-xeo', 'banh-cuon'],
            // Appetizers
            'appetizer' => ['goi-cuon', 'cha-gio', 'goi-du-du'],
            // Drinks
            'drink' => [
                'ca-phe-sua', 'ca-phe-trung', 'ca-phe-dua',
                'tra-xa', 'tra-hoa-nhai', 'tra-sen',
                'bia-saigon', 'bia-333', 'sinh-to', 'nuoc-mia',
            ],
            // Desserts
            'dessert' => ['banh-flan', 'che', 'xoi-hat-sen'],
        ];

        foreach ($productMapping as $sectionKey => $productSlugs) {
            $section = $sections->get($sectionKey);

            if (! $section) {
                continue;
            }

            foreach ($productSlugs as $slug) {
                $product = $products->firstWhere('slug', $slug);

                if (! $product || $product->skus->isEmpty()) {
                    continue;
                }

                // Create menu product
                $menuProduct = MenuProduct::updateOrCreate(
                    [
                        'menu_id' => $menu->id,
                        'product_id' => $product->id,
                        'menu_section_id' => $section->id,
                    ],
                    [
                        'display_order' => $displayOrder++,
                        'is_active' => true,
                    ]
                );

                // Add SKUs with VND pricing
                foreach ($product->skus as $sku) {
                    // Convert JPY pricing to VND (1 JPY ≈ 175 VND). issue #875 —
                    // the JPY base price now lives in selling_price (cost_price
                    // is 0, auto-derived from recipe later).
                    $priceInJpy = (float) $sku->selling_price;
                    $priceInVnd = $this->convertToVnd($priceInJpy);

                    MenuProductSku::updateOrCreate(
                        [
                            'menu_product_id' => $menuProduct->id,
                            'product_sku_id' => $sku->id,
                        ],
                        [
                            'selling_price' => $priceInVnd,
                            'is_price_overridden' => true,
                            'is_active' => true,
                        ]
                    );
                }

                $productCount++;
            }
        }

        return $productCount;
    }

    /**
     * Convert JPY price to VND.
     * 1 JPY ≈ 175 VND, rounded to nearest 1000 VND.
     */
    private function convertToVnd(float $jpyPrice): int
    {
        $vndPrice = $jpyPrice * 175;

        // Round to nearest 1000 VND
        return (int) (round($vndPrice / 1000) * 1000);
    }
}
