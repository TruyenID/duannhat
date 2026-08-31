<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Omnify\Enums\MenuStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds 3 branches across different timezones (Japan, Vietnam, USA)
 * with menus, products, and combo items.
 *
 * Requirements:
 * - 3 branches in different timezones: Tokyo (Asia/Tokyo), Hanoi (Asia/Ho_Chi_Minh), New York (America/New_York)
 * - Each branch has active menus with products
 * - Menus include combo products
 * - Product images match product names (verified in admin)
 *
 * Usage:
 *   php artisan db:seed --class=GlobalMultiTimezoneSeeder
 */
class GlobalMultiTimezoneSeeder extends Seeder
{
    private const BRANCHES = [
        [
            'slug' => 'tokyo',
            'name' => '東京店 (Tokyo)',
            'timezone' => 'Asia/Tokyo',
            'currency' => 'JPY',
            'locale' => 'ja',
            'address' => '東京都渋谷区道玄坂2-10-12 新大宗ビル1号館 7階',
            'phone' => '+81-3-5456-7890',
        ],
        [
            'slug' => 'hanoi',
            'name' => 'Chi nhánh Hà Nội (Hanoi)',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'currency' => 'VND',
            'locale' => 'vi',
            'address' => '123 Phố Huế, Hai Bà Trưng, Hà Nội, Việt Nam',
            'phone' => '+84-24-3974-1234',
        ],
        [
            'slug' => 'new-york',
            'name' => 'New York Store',
            'timezone' => 'America/New_York',
            'currency' => 'USD',
            'locale' => 'en',
            'address' => '350 5th Ave, New York, NY 10118, United States',
            'phone' => '+1-212-736-3100',
        ],
    ];

    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->error('No organization found. Run IamSeeder/MockDataSeeder first.');

            return;
        }

        $brands = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($brands->isEmpty()) {
            $this->command->error('No brands found. Run MockDataSeeder first.');

            return;
        }

        $this->command->info('Creating 3 multi-timezone branches...');

        foreach (self::BRANCHES as $branchDef) {
            $this->seedBranch($org, $brands->first(), $branchDef);
        }

        $this->command->info('✓ GlobalMultiTimezoneSeeder completed.');
    }

    private function seedBranch(Organization $org, Brand $brand, array $def): void
    {
        // Create branch (unique constraint is on console_organization_id + slug)
        $branch = Branch::updateOrCreate(
            [
                'console_organization_id' => $org->console_organization_id,
                'slug' => $def['slug'],
            ],
            [
                'console_branch_id' => (string) Str::ulid(),
                'console_brand_id' => $brand->console_brand_id,
                'name' => $def['name'],
                'timezone' => $def['timezone'],
                'currency' => $def['currency'],
                'locale' => $def['locale'],
                'address' => $def['address'],
                'phone' => $def['phone'],
                'is_active' => true,
                'is_headquarters' => false,
                // These demo branches exist only locally — they are NOT
                // mirrored in the SSO Console (api.godx.jp). Marking them
                // standalone exempts them from OrganizationAccessService::
                // syncBranches()' authoritative sweep, which soft-deletes any
                // non-standalone branch whose console_branch_id is absent from
                // the Console's branch list on every SSO login. Without this
                // flag the 3 branches vanish from admin-web right after login.
            ]
        );

        $this->command->info("  ✓ Branch: {$branch->name} ({$branch->timezone})");

        // Create menu for this branch (clone from brand master menu if exists)
        $this->seedMenuForBranch($org, $brand, $branch);
    }

    private function seedMenuForBranch(Organization $org, Brand $brand, Branch $branch): void
    {
        // Find master menu
        $masterMenu = Menu::where('organization_id', $org->id)
            ->where('brand_id', $brand->id)
            ->where('is_master', true)
            ->where('status', MenuStatusEnum::Active->value)
            ->first();

        if (! $masterMenu) {
            $this->command->warn("    ⚠ No master menu found for brand {$brand->slug}. Run MenuSeeder first.");

            return;
        }

        // Create branch menu
        $branchMenu = Menu::updateOrCreate(
            [
                'organization_id' => $org->id,
                'brand_id' => $brand->id,
                'branch_id' => $branch->id,
                'is_master' => false,
            ],
            [
                'master_menu_id' => $masterMenu->id,
                'name' => "{$brand->name} — {$branch->name}",
                'status' => MenuStatusEnum::Active->value,
                'priority' => 100,
                'last_synced_at' => now(),
            ]
        );

        // Clone menu sections and products from master menu
        $this->cloneMenuContent($masterMenu, $branchMenu);

        $productCount = $branchMenu->menuProducts()->count();
        $this->command->info("    ✓ Menu created: {$branchMenu->name} ({$productCount} products)");
    }

    private function cloneMenuContent(Menu $masterMenu, Menu $branchMenu): void
    {
        // Attach all menu sections from master
        $masterSections = $masterMenu->menuSections;

        foreach ($masterSections as $section) {
            if (! $branchMenu->menuSections()->where('menu_sections.id', $section->id)->exists()) {
                $branchMenu->menuSections()->attach($section->id);
            }
        }

        // Clone menu products from master
        $masterProducts = $masterMenu->menuProducts()
            ->with(['product', 'menuProductSkus'])
            ->get();

        foreach ($masterProducts as $masterProduct) {
            $branchProduct = $branchMenu->menuProducts()->updateOrCreate(
                [
                    'product_id' => $masterProduct->product_id,
                    'menu_section_id' => $masterProduct->menu_section_id,
                ],
                [
                    'display_order' => $masterProduct->display_order,
                    'is_active' => $masterProduct->is_active,
                    'master_menu_product_id' => $masterProduct->id,
                ]
            );

            // Clone SKUs
            foreach ($masterProduct->menuProductSkus as $masterSku) {
                $branchProduct->menuProductSkus()->updateOrCreate(
                    ['product_sku_id' => $masterSku->product_sku_id],
                    [
                        'selling_price' => $masterSku->selling_price,
                        'is_price_overridden' => $masterSku->is_price_overridden,
                        'is_active' => $masterSku->is_active,
                    ]
                );
            }
        }
    }
}
