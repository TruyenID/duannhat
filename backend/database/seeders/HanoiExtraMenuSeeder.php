<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Omnify\Enums\MenuStatusEnum;
use Illuminate\Database\Seeder;

/**
 * Seeds an additional evening menu for Hanoi branch.
 *
 * Creates a second menu for the Hanoi branch with:
 * - Evening Special theme (6PM - 11PM)
 * - Subset of products from master menu
 * - Different pricing (20% discount for happy hour)
 *
 * Usage:
 *   php artisan db:seed --class=HanoiExtraMenuSeeder
 */
class HanoiExtraMenuSeeder extends Seeder
{
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

        // Get the brand (べとコーヒー - beto-coffee)
        $brand = Brand::where('console_brand_id', $hanoi->console_brand_id)->first();

        if (! $brand) {
            $this->command->error('Brand not found for Hanoi branch.');

            return;
        }

        // Find master menu
        $masterMenu = Menu::where('organization_id', $org->id)
            ->where('brand_id', $brand->id)
            ->where('is_master', true)
            ->where('status', MenuStatusEnum::Active->value)
            ->first();

        if (! $masterMenu) {
            $this->command->error("No master menu found for brand {$brand->slug}.");

            return;
        }

        $this->command->info('Creating evening menu for Hanoi branch...');

        // Create evening menu
        $eveningMenu = Menu::updateOrCreate(
            [
                'organization_id' => $org->id,
                'brand_id' => $brand->id,
                'branch_id' => $hanoi->id,
                'name' => "{$brand->name} — Hanoi Evening Special",
            ],
            [
                'master_menu_id' => $masterMenu->id,
                'description' => 'Evening happy hour menu with special pricing (6PM - 11PM)',
                'status' => MenuStatusEnum::Active->value,
                'priority' => 200, // Higher priority than default menu
                'is_master' => false,
                'last_synced_at' => now(),
            ]
        );

        // Clone sections from master
        $this->cloneMenuSections($masterMenu, $eveningMenu);

        // Clone products (only drinks and side dishes for evening)
        $productCount = $this->cloneEveningProducts($masterMenu, $eveningMenu);

        $this->command->info("✓ Evening menu created: {$eveningMenu->name}");
        $this->command->info("  {$productCount} products with happy hour pricing");
    }

    private function cloneMenuSections(Menu $masterMenu, Menu $eveningMenu): void
    {
        $sections = $masterMenu->menuSections;

        foreach ($sections as $section) {
            if (! $eveningMenu->menuSections()->where('menu_sections.id', $section->id)->exists()) {
                $eveningMenu->menuSections()->attach($section->id);
            }
        }
    }

    private function cloneEveningProducts(Menu $masterMenu, Menu $eveningMenu): int
    {
        // Get only drinks and side dishes (evening menu concept)
        $eveningProductSlugs = [
            'ca-phe-sua', 'ca-phe-trung', 'ca-phe-dua',
            'tra-xa', 'tra-hoa-nhai', 'tra-sen',
            'bia-saigon', 'bia-333', 'sinh-to', 'nuoc-mia',
            'goi-cuon', 'cha-gio', 'goi-du-du',
        ];

        $products = Product::where('brand_id', $masterMenu->brand_id)
            ->whereIn('slug', $eveningProductSlugs)
            ->where('status', 'active')
            ->with(['skus'])
            ->get();

        $productCount = 0;

        foreach ($products as $product) {
            if ($product->skus->isEmpty()) {
                continue;
            }

            // Find master menu product to get section
            $masterMenuProduct = MenuProduct::where('menu_id', $masterMenu->id)
                ->where('product_id', $product->id)
                ->first();

            if (! $masterMenuProduct) {
                continue;
            }

            // Create menu product
            $menuProduct = MenuProduct::updateOrCreate(
                [
                    'menu_id' => $eveningMenu->id,
                    'product_id' => $product->id,
                    'menu_section_id' => $masterMenuProduct->menu_section_id,
                ],
                [
                    'display_order' => $masterMenuProduct->display_order,
                    'is_active' => true,
                    'master_menu_product_id' => $masterMenuProduct->id,
                ]
            );

            // Clone SKUs with 20% discount (happy hour pricing)
            foreach ($product->skus as $sku) {
                $masterSku = MenuProductSku::where('menu_product_id', $masterMenuProduct->id)
                    ->where('product_sku_id', $sku->id)
                    ->first();

                if (! $masterSku) {
                    continue;
                }

                // Apply 20% discount for evening happy hour
                $discountedPrice = round($masterSku->selling_price * 0.8 / 50) * 50; // Round to nearest 50

                MenuProductSku::updateOrCreate(
                    [
                        'menu_product_id' => $menuProduct->id,
                        'product_sku_id' => $sku->id,
                    ],
                    [
                        'selling_price' => $discountedPrice,
                        'is_price_overridden' => true,
                        'is_active' => true,
                    ]
                );
            }

            $productCount++;
        }

        return $productCount;
    }
}
