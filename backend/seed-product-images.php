<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\File;
use App\Models\Product;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$storage = Storage::disk('s3');

// Vietnamese food images from Unsplash (free, no attribution required for demo)
$imageMap = [
    'pho-bo' => 'https://images.unsplash.com/photo-1591814468924-caf88d1232e1?w=800&h=600&fit=crop', // Pho Bo
    'pho-ga' => 'https://images.unsplash.com/photo-1626082927389-6cd097cdc6ec?w=800&h=600&fit=crop', // Pho Ga
    'bun-bo-hue' => 'https://images.unsplash.com/photo-1585032226651-759b368d7246?w=800&h=600&fit=crop', // Bun Bo Hue
    'banh-mi' => 'https://images.unsplash.com/photo-1598511726623-d2e9996892f0?w=800&h=600&fit=crop', // Banh Mi
    'com-tam' => 'https://images.unsplash.com/photo-1626074353765-517a681e40be?w=800&h=600&fit=crop', // Com Tam
    'ca-phe-sua' => 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=800&h=600&fit=crop', // Vietnamese Coffee
    'ca-phe-trung' => 'https://images.unsplash.com/photo-1568057806120-977e53acf8ba?w=800&h=600&fit=crop', // Egg Coffee
    'sinh-to' => 'https://images.unsplash.com/photo-1505252585461-04db1eb84625?w=800&h=600&fit=crop', // Smoothie
    'goi-cuon' => 'https://images.unsplash.com/photo-1559314809-0d155014e29e?w=800&h=600&fit=crop', // Spring Rolls
    'cha-gio' => 'https://images.unsplash.com/photo-1534422298391-e4f8c172dddb?w=800&h=600&fit=crop', // Fried Spring Rolls
    'bun-cha' => 'https://images.unsplash.com/photo-1617093727343-374698b1b08d?w=800&h=600&fit=crop', // Bun Cha
    'mi-xao' => 'https://images.unsplash.com/photo-1612929633738-8fe44f7ec841?w=800&h=600&fit=crop', // Stir Fried Noodles
    'default' => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=800&h=600&fit=crop', // Generic food
];

echo "Fetching products without gallery images...\n\n";

$products = Product::query()
    ->select('id', 'slug', 'name')
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
    // Find matching image URL based on slug
    $imageUrl = null;
    foreach (array_keys($imageMap) as $key) {
        if (str_contains($product->slug, $key)) {
            $imageUrl = $imageMap[$key];
            break;
        }
    }
    $imageUrl = $imageUrl ?? $imageMap['default'];

    echo "Processing: {$product->name} ({$product->slug})... ";

    try {
        // Download image
        $response = Http::timeout(30)->get($imageUrl);

        if ($response->failed()) {
            echo "❌ Failed to download\n";
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
            'fileable_type' => Product::class,
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

        echo "✓ Uploaded ({$filename})\n";
        $created++;

        // Rate limit to avoid hammering Unsplash
        usleep(500000); // 0.5 second delay

    } catch (Exception $e) {
        echo '❌ Error: '.$e->getMessage()."\n";
        $failed++;
    }
}

echo "\n";
echo "========================================\n";
echo "✓ Created: {$created} images\n";
echo "❌ Failed: {$failed} images\n";
echo "========================================\n";
echo "\nDone! Refresh your product pages to see the images.\n";
