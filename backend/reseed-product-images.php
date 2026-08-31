<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\File;
use App\Models\Product;
use App\Models\ProductSku;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$storage = Storage::disk('s3');

// Vietnamese food search queries for Unsplash
// Format: slug => [search query, fallback URL]
$imageMap = [
    'pho-bo' => ['vietnamese pho beef noodle soup', 'photo-1591814468924-caf88d1232e1'],
    'pho-ga' => ['vietnamese pho chicken noodle soup', 'photo-1626082927389-6cd097cdc6ec'],
    'bun-bo-hue' => ['bun bo hue vietnamese spicy beef noodle', 'photo-1585032226651-759b368d7246'],
    'banh-mi' => ['banh mi vietnamese sandwich', 'photo-1598511726623-d2e9996892f0'],
    'com-tam' => ['com tam broken rice vietnamese', 'photo-1626074353765-517a681e40be'],
    'banh-xeo' => ['banh xeo vietnamese crepe pancake', 'photo-1567098260939-5d9cee52d3d7'],
    'banh-cuon' => ['banh cuon vietnamese rice rolls steamed', 'photo-1565299543923-37dd37887442'],
    'mi-quang' => ['mi quang vietnamese turmeric noodles', 'photo-1612929633738-8fe44f7ec841'],
    'cao-lau' => ['cao lau vietnamese noodles', 'photo-1569718212165-3a8278d5f624'],
    'goi-cuon' => ['goi cuon vietnamese fresh spring rolls', 'photo-1559314809-0d155014e29e'],
    'cha-gio' => ['cha gio vietnamese fried spring rolls', 'photo-1534422298391-e4f8c172dddb'],
    'goi-du-du' => ['green papaya salad vietnamese', 'photo-1540189549336-e6e99c3679fe'],
    'banh-flan' => ['vietnamese flan creme caramel dessert', 'photo-1488477181946-6428a0291777'],
    'che' => ['che vietnamese sweet dessert soup', 'photo-1563805042-7684c019e1cb'],
    'xoi' => ['xoi vietnamese sticky rice', 'photo-1586190848861-99aa4a171e90'],
    'ca-phe-sua' => ['vietnamese iced coffee condensed milk', 'photo-1559056199-641a0ac8b55e'],
    'ca-phe-trung' => ['vietnamese egg coffee hanoi', 'photo-1517487881594-2787fef5ebf7'],
    'ca-phe-dua' => ['vietnamese coconut coffee', 'photo-1461023058943-07fcbe16d735'],
    'tra-' => ['vietnamese tea hot drink', 'photo-1564890369478-c89ca6d9cde9'],
    'bia-' => ['vietnamese beer glass', 'photo-1535958636474-b021ee887b13'],
    'sinh-to' => ['vietnamese smoothie fruit shake', 'photo-1505252585461-04db1eb84625'],
    'nuoc-mia' => ['sugarcane juice fresh drink', 'photo-1622597467836-f3285f2131b7'],
    'matcha' => ['matcha latte green tea', 'photo-1536013293715-e02046c1d2f4'],
    'coffee' => ['iced coffee cold brew', 'photo-1461023058943-07fcbe16d735'],
    'croissant' => ['croissant pastry bakery', 'photo-1555507036-ab1f4038808a'],
    'bun-cha' => ['bun cha hanoi grilled pork noodles', 'photo-1617093727343-374698b1b08d'],
    'default' => ['vietnamese food dish cuisine', 'photo-1504674900247-0877df9cc836'],
];

echo "Step 1: Cleaning up old placeholder gallery images...\n";

// Delete old gallery files (only from gallery-fixtures, not uploaded ones)
$oldFiles = File::where('collection', 'gallery')
    ->where('path', 'like', 'gallery-fixtures/%')
    ->get();

foreach ($oldFiles as $file) {
    $file->delete();
}

echo "✓ Deleted {$oldFiles->count()} old placeholder files\n\n";

echo "Step 2: Fetching products without gallery images...\n";

$products = Product::query()
    ->select('id', 'slug', 'name', 'organization_id')
    ->whereDoesntHave('files', fn ($q) => $q->where('collection', 'gallery'))
    ->get();

if ($products->isEmpty()) {
    echo "All products already have gallery images!\n";
    exit(0);
}

echo "Found {$products->count()} products without images.\n";
echo "Downloading and uploading images to MinIO...\n\n";

$created = 0;
$failed = 0;

foreach ($products as $product) {
    // Find matching image config based on slug (partial match)
    $imageConfig = $imageMap['default'];
    foreach ($imageMap as $key => $config) {
        if (str_contains($product->slug, $key)) {
            $imageConfig = $config;
            break;
        }
    }

    [$searchQuery, $fallbackPhotoId] = $imageConfig;

    echo "Processing: {$product->name} ({$product->slug})... ";

    try {
        // Use direct Unsplash photo URL (most reliable)
        $imageUrl = "https://images.unsplash.com/{$fallbackPhotoId}?w=800&h=600&fit=crop";

        // Download image
        $response = Http::timeout(30)->get($imageUrl);

        if ($response->failed()) {
            echo "❌ Failed\n";
            $failed++;

            continue;
        }

        $imageData = $response->body();
        $filename = "products/{$product->slug}-".uniqid().'.jpg';

        // Upload to MinIO
        $storage->put($filename, $imageData);

        // Create File record
        File::create([
            'organization_id' => $product->organization_id,
            'fileable_type' => $product->getMorphClass(),
            'fileable_id' => $product->id,
            'collection' => 'gallery',
            'disk' => 's3',
            'path' => $filename,
            'original_name' => $product->slug.'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => strlen($imageData),
            'status' => FileStatusEnum::Permanent,
            'expires_at' => null,
            'sort_order' => 0,
        ]);

        echo "✓\n";
        $created++;

        // Rate limit
        usleep(800000); // 0.8 second

    } catch (Exception $e) {
        echo '❌ Error: '.$e->getMessage()."\n";
        $failed++;
    }
}

echo "\n";
echo "========================================\n";
echo "✓ Product images created: {$created}\n";
echo "❌ Failed: {$failed}\n";
echo "========================================\n\n";

// Step 3: Seed SKU images (copy from parent product)
echo "Step 3: Seeding SKU gallery images (copying from parent products)...\n";

$skus = ProductSku::query()
    ->select('id', 'product_id', 'sku')
    ->with('product:id,slug,organization_id')
    ->whereDoesntHave('files', fn ($q) => $q->where('collection', 'gallery'))
    ->get();

if ($skus->isEmpty()) {
    echo "All SKUs already have gallery images!\n";
} else {
    echo "Found {$skus->count()} SKUs without images.\n";

    $skuCreated = 0;
    $skuFailed = 0;

    foreach ($skus as $sku) {
        // Get parent product's first gallery image
        $parentImage = File::where('fileable_type', Product::class)
            ->where('fileable_id', $sku->product_id)
            ->where('collection', 'gallery')
            ->first();

        if (! $parentImage) {
            $skuFailed++;

            continue;
        }

        // Copy file on S3
        $newPath = "skus/{$sku->product->slug}-{$sku->sku}-".uniqid().'.jpg';

        try {
            $storage->copy($parentImage->path, $newPath);

            // Create File record for SKU
            File::create([
                'organization_id' => $sku->product->organization_id,
                'fileable_type' => $sku->getMorphClass(),
                'fileable_id' => $sku->id,
                'collection' => 'gallery',
                'disk' => 's3',
                'path' => $newPath,
                'original_name' => $sku->sku.'.jpg',
                'mime_type' => 'image/jpeg',
                'size' => $parentImage->size,
                'status' => FileStatusEnum::Permanent,
                'expires_at' => null,
                'sort_order' => 0,
            ]);

            $skuCreated++;
        } catch (Exception $e) {
            $skuFailed++;
        }
    }

    echo "✓ SKU images created: {$skuCreated}\n";
    echo "❌ SKU failed: {$skuFailed}\n";
}

echo "\n========================================\n";
echo "SUMMARY\n";
echo "========================================\n";
echo "✓ Product images: {$created}\n";
echo '✓ SKU images: '.($skuCreated ?? 0)."\n";
echo '❌ Total failed: '.($failed + ($skuFailed ?? 0))."\n";
echo "========================================\n";
echo "\nDone! Refresh your product pages to see the images.\n";
