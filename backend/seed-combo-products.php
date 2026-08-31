<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Brand;
use App\Models\ComboItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

echo "Creating combo products...\n\n";

$org = Organization::first();
$brands = Brand::all();
$productType = ProductType::first();

// Get some existing products to use in combos
$phoProducts = Product::where('is_combo', false)
    ->where('organization_id', $org->id)
    ->whereHas('skus')
    ->where(function ($q) {
        $q->where('slug', 'like', 'pho-%')
            ->orWhere('slug', 'like', 'bun-%');
    })
    ->with('skus')
    ->take(4)
    ->get();

$drinkProducts = Product::where('is_combo', false)
    ->where('organization_id', $org->id)
    ->whereHas('skus')
    ->where(function ($q) {
        $q->where('slug', 'like', 'ca-phe-%')
            ->orWhere('slug', 'like', 'sinh-to%')
            ->orWhere('slug', 'like', 'tra-%');
    })
    ->with('skus')
    ->take(3)
    ->get();

$appetizerProducts = Product::where('is_combo', false)
    ->where('organization_id', $org->id)
    ->whereHas('skus')
    ->where(function ($q) {
        $q->where('slug', 'like', 'goi-cuon%')
            ->orWhere('slug', 'like', 'cha-gio%');
    })
    ->with('skus')
    ->take(2)
    ->get();

if ($phoProducts->isEmpty()) {
    echo "❌ No main products found. Please run ProductSeeder first.\n";
    exit(1);
}

$combos = [
    [
        'name' => 'Phở Combo Set',
        'slug' => 'pho-combo-set',
        'description' => 'Traditional Vietnamese phở with fresh spring rolls and iced coffee',
        'price' => 2500,
        'items' => [
            ['type' => 'main', 'quantity' => 1],
            ['type' => 'appetizer', 'quantity' => 2],
            ['type' => 'drink', 'quantity' => 1],
        ],
    ],
    [
        'name' => 'Lunch Special',
        'slug' => 'lunch-special',
        'description' => 'Perfect lunch combo with noodles, appetizer and drink',
        'price' => 1800,
        'items' => [
            ['type' => 'main', 'quantity' => 1],
            ['type' => 'appetizer', 'quantity' => 1],
            ['type' => 'drink', 'quantity' => 1],
        ],
    ],
    [
        'name' => 'Family Meal',
        'slug' => 'family-meal',
        'description' => 'Family-sized combo with 2 main dishes, appetizers and drinks',
        'price' => 4500,
        'items' => [
            ['type' => 'main', 'quantity' => 2],
            ['type' => 'appetizer', 'quantity' => 4],
            ['type' => 'drink', 'quantity' => 2],
        ],
    ],
];

$created = 0;

foreach ($brands as $brand) {
    foreach ($combos as $comboData) {
        echo "Creating: {$comboData['name']} for {$brand->name}... ";

        try {
            // Create combo product
            $combo = Product::create([
                'id' => (string) Str::ulid(),
                'organization_id' => $org->id,
                'brand_id' => $brand->id,
                'product_type_id' => $productType->id,
                'name' => $comboData['name'],
                'slug' => $comboData['slug'].'-'.Str::random(6),
                'description' => $comboData['description'],
                'status' => 'active',
                'is_combo' => true,
                'is_hidden' => false,
            ]);

            // Create combo items
            $displayOrder = 0;
            foreach ($comboData['items'] as $itemSpec) {
                $sourceProduct = null;

                if ($itemSpec['type'] === 'main' && $phoProducts->isNotEmpty()) {
                    $sourceProduct = $phoProducts->random();
                } elseif ($itemSpec['type'] === 'drink' && $drinkProducts->isNotEmpty()) {
                    $sourceProduct = $drinkProducts->random();
                } elseif ($itemSpec['type'] === 'appetizer' && $appetizerProducts->isNotEmpty()) {
                    $sourceProduct = $appetizerProducts->random();
                }

                if ($sourceProduct && $sourceProduct->skus->isNotEmpty()) {
                    $sku = $sourceProduct->skus->first();

                    ComboItem::create([
                        'id' => (string) Str::ulid(),
                        'product_id' => $combo->id,
                        'component_sku_id' => $sku->id,
                        'quantity' => $itemSpec['quantity'],
                        'display_order' => $displayOrder++,
                    ]);
                }
            }

            echo "✓\n";
            $created++;

        } catch (Exception $e) {
            echo '❌ Error: '.$e->getMessage()."\n";
        }
    }
}

echo "\n========================================\n";
echo "✓ Created {$created} combo products\n";
echo "========================================\n";
echo "\nDone! You can now see combo products in the admin.\n";
