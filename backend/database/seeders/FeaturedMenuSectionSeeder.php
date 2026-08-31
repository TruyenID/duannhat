<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use Illuminate\Database\Seeder;

class FeaturedMenuSectionSeeder extends Seeder
{
    /**
     * Add "Featured / Nổi bật" section to all existing menus with popular products.
     */
    public function run(): void
    {
        // Create MenuSection "Featured"
        $featuredSection = MenuSection::create([
            'name' => '⭐ Nổi bật',
        ]);

        $this->command->info("Created MenuSection: {$featuredSection->name}");

        // Get all active menus
        $menus = Menu::where('status', 'Active')->get();

        foreach ($menus as $menu) {
            // Attach section to menu with display_order = 0 (first position)
            $menu->menuSections()->attach($featuredSection->id, [
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Increment display_order of other sections
            $menu->menuSections()
                ->wherePivot('menu_section_id', '!=', $featuredSection->id)
                ->increment('display_order');

            // Get popular products (5 items: Vietnamese Coffee, Banh Mi, Beef Pho, Fresh Spring Rolls, Fried Spring Rolls)
            $popularProductSlugs = ['ca-phe-sua', 'banh-mi', 'pho-bo', 'goi-cuon', 'cha-gio'];
            $popularProducts = Product::whereIn('slug', $popularProductSlugs)
                ->where('organization_id', $menu->organization_id)
                ->get();

            $displayOrder = 0;
            foreach ($popularProducts as $product) {
                // Create MenuProduct
                $menuProduct = MenuProduct::create([
                    'menu_id' => $menu->id,
                    'product_id' => $product->id,
                    'menu_section_id' => $featuredSection->id,
                    'is_active' => true,
                    'display_order' => $displayOrder++,
                ]);

                // Add all active SKUs for this product
                $skus = $product->skus()->where('is_active', true)->get();
                foreach ($skus as $sku) {
                    MenuProductSku::create([
                        'menu_product_id' => $menuProduct->id,
                        'product_sku_id' => $sku->id,
                        'selling_price' => $sku->selling_price,
                        'is_price_overridden' => false,
                        'is_active' => true,
                    ]);
                }
            }

            $this->command->info("Added Featured section to menu: {$menu->name} ({$popularProducts->count()} products)");
        }

        $this->command->info('✅ Featured section seeder completed!');
    }
}
