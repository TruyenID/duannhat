<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ComboProductWithVariantsSeeder extends Seeder
{
    private $organization;

    private $brand;

    public function run(): void
    {
        $this->organization = Organization::first();
        if (! $this->organization) {
            $this->command->error('No organization found. Run OrganizationSeeder first.');

            return;
        }

        $this->brand = Brand::first();
        if (! $this->brand) {
            $this->command->error('No brand found. Run BrandSeeder first.');

            return;
        }

        $this->command->info('🚀 Creating Combo Phở Sáng with variants...');

        // Step 1: Create Phở products with Size options
        $phoProducts = $this->createPhoProducts();

        // Step 2: Create drink products
        $drinkProducts = $this->createDrinkProducts();

        // Step 3: Create topping groups
        [$phoGroup, $drinkGroup] = $this->createToppingGroups($phoProducts, $drinkProducts);

        // Step 4: Create combo product
        $comboProduct = $this->createComboProduct($phoGroup, $drinkGroup);

        // Step 5: Add combo to menu
        $this->addComboToMenu($comboProduct);

        $this->command->info('');
        $this->command->info('✅ Done! Created:');
        $this->command->info('   - 3 Phở products with Size options (Lớn/Thường)');
        $this->command->info('   - 3 Drink products');
        $this->command->info('   - 2 Topping groups');
        $this->command->info('   - 1 Combo Phở Sáng');
        $this->command->info('   - Added to menu');
    }

    private function createPhoProducts(): array
    {
        $this->command->info('  Creating Phở products with Size options...');

        $productType = ProductType::firstOrCreate(
            ['code' => 'food', 'organization_id' => $this->organization->id],
            ['name' => 'Food']
        );

        $products = [];
        $phoNames = [
            'Phở Bò' => 'Authentic Vietnamese beef noodle soup',
            'Phở Gà' => 'Chicken noodle soup with fresh herbs',
            'Phở Hải Sản' => 'Seafood noodle soup with shrimp and squid',
        ];

        foreach ($phoNames as $name => $description) {
            // Create product
            $product = Product::create([
                'id' => Str::ulid(),
                'organization_id' => $this->organization->id,
                'brand_id' => $this->brand->id,
                'product_type_id' => $productType->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $description,
                'status' => 'approved',
            ]);

            // Create Size option
            $sizeOption = ProductOption::create([
                'id' => Str::ulid(),
                'product_id' => $product->id,
                'key' => 'size',
                'name' => 'Size',
                'position' => 1,
                'is_active' => true,
            ]);

            // Create option values: Thường (default), Lớn (+200)
            $sizeValues = [
                ['value' => 'regular', 'label' => 'Thường', 'position' => 1],
                ['value' => 'large', 'label' => 'Lớn', 'position' => 2],
            ];

            $optionValueIds = [];
            foreach ($sizeValues as $idx => $sizeData) {
                $optionValue = ProductOptionValue::create([
                    'id' => Str::ulid(),
                    'option_id' => $sizeOption->id,
                    'value' => $sizeData['value'],
                    'label' => $sizeData['label'],
                    'position' => $sizeData['position'],
                    'is_active' => true,
                ]);
                $optionValueIds[$sizeData['value']] = $optionValue->id;
            }

            // Create SKUs for each size
            foreach (['regular', 'large'] as $size) {
                $extraPrice = $size === 'large' ? 200 : 0;
                ProductSku::create([
                    'id' => Str::ulid(),
                    'product_id' => $product->id,
                    'sku' => strtoupper(Str::slug($name)).'-'.strtoupper($size),
                    'name' => $name.' ('.($size === 'large' ? 'Lớn' : 'Thường').')',
                    'option_value1_id' => $optionValueIds[$size],
                    'option_signature' => 'size:'.$size,
                    'selling_price' => $extraPrice,
                    'is_active' => true,
                ]);
            }

            $products[] = $product;
            $this->command->info("    ✓ {$name} (with Size: Thường, Lớn)");
        }

        return $products;
    }
}
