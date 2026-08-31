<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds Hanoi branch with Vietnam timezone and clones a master menu.
 *
 * Creates:
 * - Hanoi branch (timezone: Asia/Ho_Chi_Minh)
 * - Clones the first available master menu from Betoya brand
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=HanoiBranchWithMenuSeeder
 */
class HanoiBranchWithMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating Hanoi branch with Vietnam timezone...');

        // Get Famgia organization
        $org = Organization::where('console_organization_id', '00000000-aaaa-4aaa-aaaa-000000000001')->first();
        if (! $org) {
            $this->command->error('Organization Famgia not found.');

            return;
        }
        $this->command->info("Organization: {$org->name}");

        // Get Betoya brand
        $brand = Brand::where('console_brand_id', '00000001-bbbb-4bbb-bbbb-000000000001')->first();
        if (! $brand) {
            $this->command->error('Brand Betoya not found.');

            return;
        }
        $this->command->info("Brand: {$brand->name}");

        // Find or create Hanoi branch (use DB query to bypass any model scopes)
        $existingBranch = \DB::table('branches')
            ->where('slug', 'hanoi')
            ->where('console_organization_id', $org->console_organization_id)
            ->first();

        if ($existingBranch) {
            // Update via DB query
            \DB::table('branches')
                ->where('id', $existingBranch->id)
                ->update([
                    'console_brand_id' => $brand->console_brand_id,
                    'name' => 'Chi nhánh Hà Nội',
                    'timezone' => 'Asia/Ho_Chi_Minh',
                    'address' => '123 Phố Huế, Hai Bà Trưng, Hà Nội',
                    'phone' => '+84 24 3974 1234',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            $branch = Branch::withTrashed()->find($existingBranch->id);
            if ($branch && $branch->trashed()) {
                $branch->restore();
                $this->command->info('✓ Branch restored and updated: Chi nhánh Hà Nội');
            } else {
                $this->command->info('✓ Branch updated: Chi nhánh Hà Nội');
            }

            goto menu_cloning;
        }

        // If branch doesn't exist, create it
        $branch = Branch::create([
            'console_branch_id' => (string) Str::ulid(),
            'console_organization_id' => $org->console_organization_id,
            'console_brand_id' => $brand->console_brand_id,
            'slug' => 'hanoi',
            'name' => 'Chi nhánh Hà Nội',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'address' => '123 Phố Huế, Hai Bà Trưng, Hà Nội',
            'phone' => '+84 24 3974 1234',
            'is_active' => true,
        ]);
        $this->command->info("✓ Branch created: {$branch->name}");

        menu_cloning:

        // Find first master menu for this brand
        $masterMenu = Menu::where('organization_id', $org->id)
            ->where('brand_id', $brand->id)
            ->where('is_master', true)
            ->where('status', 'Active')
            ->first();

        if (! $masterMenu) {
            $this->command->warn('No master menu found for Betoya brand. Branch created but no menu cloned.');
            $this->command->warn('Please create a master menu at HQ first, then clone it to Hanoi branch.');

            return;
        }

        $this->command->info("Master menu found: {$masterMenu->name}");

        // Check if menu already cloned for this branch
        $existingMenu = Menu::where('branch_id', $branch->id)
            ->where('master_menu_id', $masterMenu->id)
            ->first();

        if ($existingMenu) {
            $this->command->info("✓ Menu already cloned: {$existingMenu->name}");

            return;
        }

        // Clone master menu to Hanoi branch
        $clonedMenu = Menu::create([
            'organization_id' => $org->id,
            'brand_id' => $brand->id,
            'branch_id' => $branch->id,
            'master_menu_id' => $masterMenu->id,
            'name' => "{$masterMenu->name} - Hà Nội",
            'description' => $masterMenu->description,
            'status' => 'Active',
            'priority' => 1,
            'is_master' => false,
        ]);

        // Copy menu products from master
        $masterMenu->load('menuProducts.product', 'menuProducts.menuProductSkus');
        foreach ($masterMenu->menuProducts as $masterMenuProduct) {
            $clonedMenuProduct = $clonedMenu->menuProducts()->create([
                'product_id' => $masterMenuProduct->product_id,
                'display_order' => $masterMenuProduct->display_order,
            ]);

            // Copy SKU associations
            foreach ($masterMenuProduct->menuProductSkus as $sku) {
                $clonedMenuProduct->menuProductSkus()->create([
                    'product_sku_id' => $sku->product_sku_id,
                ]);
            }
        }

        $this->command->info("✓ Menu cloned: {$clonedMenu->name} ({$clonedMenu->menuProducts->count()} products)");
        $this->command->info('');
        $this->command->info('Success! Hanoi branch created with Vietnam timezone (Asia/Ho_Chi_Minh)');
        $this->command->info('View at: http://localhost:5430/shop/hanoi/menus');
    }
}
