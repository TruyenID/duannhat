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
 * Seeds a second menu — "Hanoi Morning Set" — for the Hanoi branch.
 *
 * Sibling of {@see HanoiExtraMenuSeeder} (which makes an Evening Special).
 * Same clone-from-master pattern, but scoped to breakfast products
 * (phở, xôi, cà phê, trà) and priced flat at master pricing — no
 * happy-hour discount, just an alternate menu for testing multi-menu
 * branches without affecting the main one.
 *
 * Idempotent. Safe to re-run — uses updateOrCreate on every row.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=HanoiBreakfastMenuSeeder
 */
class HanoiBreakfastMenuSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->error('No organization found.');

            return;
        }

        $hanoi = Branch::where('slug', 'hanoi')
            ->where('console_organization_id', $org->console_organization_id)
            ->first();

        if (! $hanoi) {
            $this->command->error('Hanoi branch not found. Run GlobalMultiTimezoneSeeder first.');

            return;
        }

        $brand = Brand::where('console_brand_id', $hanoi->console_brand_id)->first();

        if (! $brand) {
            $this->command->error('Brand not found for Hanoi branch.');

            return;
        }

        $masterMenu = Menu::where('organization_id', $org->id)
            ->where('brand_id', $brand->id)
            ->where('is_master', true)
            ->where('status', MenuStatusEnum::Active->value)
            ->first();

        if (! $masterMenu) {
            $this->command->error("No master menu found for brand {$brand->slug}.");

            return;
        }

        $this->command->info('Creating morning menu for Hanoi branch...');

        $morningMenu = Menu::updateOrCreate(
            [
                'organization_id' => $org->id,
                'brand_id' => $brand->id,
                'branch_id' => $hanoi->id,
                'name' => "{$brand->name} — Hanoi Morning Set",
            ],
            [
                'master_menu_id' => $masterMenu->id,
                'description' => 'Breakfast & morning menu — phở, xôi, cà phê & trà (06:00 - 11:00)',
                'status' => MenuStatusEnum::Active->value,
                'priority' => 150,
                'is_master' => false,
                'last_synced_at' => now(),
            ]
        );

        $this->cloneMenuSections($masterMenu, $morningMenu);
        $productCount = $this->cloneMorningProducts($masterMenu, $morningMenu);

        $this->command->info("✓ Morning menu created: {$morningMenu->name}");
        $this->command->info("  {$productCount} products at master price");
    }

    private function cloneMenuSections(Menu $masterMenu, Menu $morningMenu): void
    {
        foreach ($masterMenu->menuSections as $section) {
            if (! $morningMenu->menuSections()->where('menu_sections.id', $section->id)->exists()) {
                $morningMenu->menuSections()->attach($section->id);
            }
        }
    }

    private function cloneMorningProducts(Menu $masterMenu, Menu $morningMenu): int
    {
        $morningSlugs = [
            'morning-set',
            'pho-bo', 'pho-ga',
            'xoi-hat-sen', 'com-tam',
            'cafe-sweets-set',
            'ca-phe-sua', 'ca-phe-trung',
            'tra-xa', 'tra-hoa-nhai', 'tra-sen',
            'sinh-to', 'nuoc-mia',
        ];

        $products = Product::where('brand_id', $masterMenu->brand_id)
            ->whereIn('slug', $morningSlugs)
            ->where('status', 'active')
            ->with(['skus'])
            ->get();

        $count = 0;

        foreach ($products as $product) {
            if ($product->skus->isEmpty()) {
                continue;
            }

            $masterMenuProduct = MenuProduct::where('menu_id', $masterMenu->id)
                ->where('product_id', $product->id)
                ->first();

            if (! $masterMenuProduct) {
                continue;
            }

            $menuProduct = MenuProduct::updateOrCreate(
                [
                    'menu_id' => $morningMenu->id,
                    'product_id' => $product->id,
                    'menu_section_id' => $masterMenuProduct->menu_section_id,
                ],
                [
                    'display_order' => $masterMenuProduct->display_order,
                    'is_active' => true,
                    'master_menu_product_id' => $masterMenuProduct->id,
                ]
            );

            foreach ($product->skus as $sku) {
                $masterSku = MenuProductSku::where('menu_product_id', $masterMenuProduct->id)
                    ->where('product_sku_id', $sku->id)
                    ->first();

                if (! $masterSku) {
                    continue;
                }

                MenuProductSku::updateOrCreate(
                    [
                        'menu_product_id' => $menuProduct->id,
                        'product_sku_id' => $sku->id,
                    ],
                    [
                        'selling_price' => $masterSku->selling_price,
                        'is_price_overridden' => false,
                        'is_active' => true,
                    ]
                );
            }

            $count++;
        }

        return $count;
    }
}
