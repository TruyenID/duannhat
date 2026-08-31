<?php

namespace Database\Seeders;

use App\Models\File;
use App\Models\Product;
use App\Models\ProductSku;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UpdateFeaturedProductsSeeder extends Seeder
{
    /**
     * Update featured products with images and prices.
     */
    public function run(): void
    {
        $featuredProducts = [
            [
                'slug' => 'ca-phe-sua',
                // Vietnamese iced coffee with condensed milk
                'image_url' => 'https://images.unsplash.com/photo-1517487881594-2787fef5ebf7?w=800&q=80',
                'skus' => [
                    ['name' => 'Regular', 'price' => 390],
                    ['name' => 'Large', 'price' => 490],
                    ['name' => 'Extra Large', 'price' => 590],
                ],
            ],
            [
                'slug' => 'banh-mi',
                // Vietnamese baguette sandwich
                'image_url' => 'https://images.unsplash.com/photo-1560624052-449f5ddf0c31?w=800&q=80',
                'skus' => [
                    ['name' => 'Regular', 'price' => 590],
                    ['name' => 'Large', 'price' => 790],
                    ['name' => 'Combo', 'price' => 990],
                ],
            ],
            [
                'slug' => 'pho-bo',
                // Vietnamese beef noodle soup
                'image_url' => 'https://images.unsplash.com/photo-1582878826629-29b7ad1cdc43?w=800&q=80',
                'skus' => [
                    ['name' => 'Regular', 'price' => 1090],
                    ['name' => 'Large', 'price' => 1290],
                    ['name' => 'Special', 'price' => 1490],
                ],
            ],
            [
                'slug' => 'goi-cuon',
                // Fresh spring rolls (Gỏi Cuốn)
                'image_url' => 'https://images.unsplash.com/photo-1534422298391-e4f8c172dddb?w=800&q=80',
                'skus' => [
                    ['name' => '2pc', 'price' => 600],
                    ['name' => '4pc', 'price' => 1050],
                ],
            ],
            [
                'slug' => 'cha-gio',
                // Fried spring rolls (Chả Giò)
                'image_url' => 'https://images.unsplash.com/photo-1563245372-f21724e3856d?w=800&q=80',
                'skus' => [
                    ['name' => '3pc', 'price' => 675],
                    ['name' => '5pc', 'price' => 1020],
                ],
            ],
        ];

        foreach ($featuredProducts as $data) {
            $products = Product::where('slug', $data['slug'])->get();

            if ($products->isEmpty()) {
                $this->command->warn("Product not found: {$data['slug']}");

                continue;
            }

            $this->command->info("Found {$products->count()} products with slug: {$data['slug']}");

            // Process each product
            foreach ($products as $product) {
                // Download image from Unsplash (once per product)
                try {
                    $response = Http::timeout(30)->get($data['image_url']);

                    if (! $response->successful()) {
                        $this->command->warn("  Failed to download image for {$product->name}");

                        continue;
                    }

                    $contents = $response->body();
                    $disk = config('omnify.storage.disk', 'public');
                    $storage = Storage::disk($disk);
                    $pathPrefix = 'products/gallery';
                    $path = "{$pathPrefix}/{$product->getKey()}.jpg";

                    // Upload to storage
                    $storage->put($path, $contents, ['visibility' => 'public']);

                    // Create or update File record
                    File::updateOrCreate(
                        [
                            'fileable_type' => $product->getMorphClass(),
                            'fileable_id' => $product->getKey(),
                            'collection' => 'gallery',
                        ],
                        [
                            'organization_id' => $product->organization_id,
                            'disk' => $disk,
                            'path' => $path,
                            'original_name' => Str::slug($product->name).'.jpg',
                            'mime_type' => 'image/jpeg',
                            'size' => strlen($contents),
                            'status' => FileStatusEnum::Permanent,
                            'expires_at' => null,
                            'sort_order' => 0,
                        ]
                    );
                } catch (\Exception $e) {
                    $this->command->warn("  Error downloading image for {$product->name}: {$e->getMessage()}");

                    continue;
                }

                // Update SKU prices
                $skus = $product->skus()->get();
                foreach ($skus as $index => $sku) {
                    if (isset($data['skus'][$index])) {
                        $newPrice = $data['skus'][$index]['price'];

                        // Update ProductSku price
                        $sku->update(['selling_price' => $newPrice]);

                        // Update MenuProductSku prices for this SKU across all menus
                        \DB::table('menu_product_skus')
                            ->where('product_sku_id', $sku->id)
                            ->update(['selling_price' => $newPrice]);
                    }
                }

                $this->command->info("  Updated product: {$product->name} (org: {$product->organization_id})");
            }
        }

        $this->command->info('✅ Featured products update completed!');
    }
}
