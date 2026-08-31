<?php

namespace Database\Seeders;

use App\Models\File;
use App\Models\Product;
use App\Models\ProductSku;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeds one gallery image per product/SKU by pointing each `files`
 * row at one of the JPGs under `gallery-fixtures/` on the configured
 * disk. If the directory is empty, placeholder images are downloaded
 * from Picsum Photos (seeded URLs — same image every run) before
 * proceeding, so no manual setup is required.
 *
 * Each model is mapped deterministically to one pool slot based on
 * its UUID, so re-seeds remain stable and same model → same image.
 *
 * Idempotent: products/SKUs that already have a `gallery` file are
 * skipped.
 */
class ProductGallerySeeder extends Seeder
{
    private const FIXTURE_PREFIX = 'gallery-fixtures';

    /**
     * How many gallery photos each product should end up with so the
     * customer menu cards can render a real multi-image carousel. SKUs
     * keep a single image (used only for the variant swap thumbnail).
     */
    private const PRODUCT_GALLERY_TARGET = 3;

    private const PICSUM_SEEDS = [
        // High-quality food images from Unsplash (no ugly red backgrounds)
        'pho-bo' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=800&q=80',  // Vietnamese beef pho
        'pho-ga' => 'https://images.unsplash.com/photo-1623341214825-9f4f963727da?w=800&q=80',  // Vietnamese chicken pho
        'bun-cha' => 'https://images.unsplash.com/photo-1559314809-0d155014e29e?w=800&q=80',  // Bun cha with herbs
        'bun-bo-hue' => 'https://images.unsplash.com/photo-1582878826629-29b7ad1cdc43?w=800&q=80',  // Spicy noodle soup
        'banh-mi' => 'https://images.unsplash.com/photo-1618449840665-9ed506d73a34?w=800&q=80',  // Vietnamese banh mi
        'com-tam' => 'https://images.unsplash.com/photo-1612929633738-8fe44f7ec841?w=800&q=80',  // Broken rice with grilled meat
        'banh-xeo' => 'https://images.unsplash.com/photo-1626804475297-41608ea09aeb?w=800&q=80',  // Vietnamese crepe
        'banh-cuon' => 'https://images.unsplash.com/photo-1626804475319-6980f2a1e3ae?w=800&q=80',  // Steamed rice rolls
        'mi-quang' => 'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?w=800&q=80',  // Turmeric noodles
        'cao-lau' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=800&q=80',  // Cao lau noodles
        // Side dishes
        'goi-cuon' => 'https://images.unsplash.com/photo-1601050690597-df0568f70950?w=800&q=80',  // Fresh spring rolls
        'cha-gio' => 'https://images.unsplash.com/photo-1625398407796-82650a8c135f?w=800&q=80',  // Fried spring rolls
        'goi-du-du' => 'https://images.unsplash.com/photo-1540189549336-e6e99c3679fe?w=800&q=80',  // Papaya salad
        'banh-flan' => 'https://images.unsplash.com/photo-1551024601-bec78aea704b?w=800&q=80',  // Flan dessert
        'che' => 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=800&q=80',  // Sweet soup dessert
        'xoi-hat-sen' => 'https://images.unsplash.com/photo-1571167530149-c6ec4f1b1dff?w=800&q=80',  // Sticky rice with lotus seeds
        // Drinks
        'ca-phe-sua' => 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=800&q=80',  // Vietnamese iced coffee
        'ca-phe-trung' => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=800&q=80',  // Egg coffee
        'ca-phe-dua' => 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=800&q=80',  // Coconut coffee
        'tra-xa' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=800&q=80',  // Lemongrass tea
        'tra-hoa-nhai' => 'https://images.unsplash.com/photo-1597481499750-3e6b22637e12?w=800&q=80',  // Jasmine tea
        'tra-sen' => 'https://images.unsplash.com/photo-1563822249548-9a72b6353cd1?w=800&q=80',  // Lotus tea
        'bia-saigon' => 'https://images.unsplash.com/photo-1535958636474-b021ee887b13?w=800&q=80',  // Beer
        'bia-333' => 'https://images.unsplash.com/photo-1608270586620-248524c67de9?w=800&q=80',  // Beer bottle
        'sinh-to' => 'https://images.unsplash.com/photo-1505252585461-04db1eb84625?w=800&q=80',  // Fruit smoothie
        'nuoc-mia' => 'https://images.unsplash.com/photo-1622597467836-f3285f2131b8?w=800&q=80',  // Sugarcane juice
    ];

    public function run(): void
    {
        $disk = config('filesystems.default');
        $storage = Storage::disk($disk);

        $this->downloadPlaceholders($storage);

        $pool = $this->loadPool($storage);

        if ($pool === []) {
            $this->command?->warn('ProductGallerySeeder: download failed — skipping gallery seeding.');

            return;
        }

        $productStats = $this->seedProducts($disk, $pool);
        $skuStats = $this->seedSkus($disk, $pool);

        $created = $productStats['created'] + $skuStats['created'];
        $failed = $productStats['failed'] + $skuStats['failed'];

        $this->command?->info(
            'ProductGallerySeeder: '.count($pool)." fixture(s) reused, {$created} row(s) created, {$failed} failed."
        );
    }

    /**
     * Download placeholder food images from Picsum Photos or direct URLs into the
     * fixture directory. Uses named seeds so the same image is fetched
     * on every run (deterministic across rebuilds).
     */
    private function downloadPlaceholders(Filesystem $storage): void
    {
        foreach (self::PICSUM_SEEDS as $key => $value) {
            // Support both array format ('ramen-1' => 'https://...') and plain strings ('pho-bo')
            if (is_string($key)) {
                $seed = $key;
                $url = $value;
            } else {
                $seed = $value;
                $url = "https://picsum.photos/seed/{$seed}/800/600.jpg";
            }

            $path = self::FIXTURE_PREFIX."/{$seed}.jpg";

            if ($storage->exists($path)) {
                continue;
            }

            try {
                $response = Http::timeout(20)->get($url);

                if ($response->successful()) {
                    $storage->put($path, $response->body());
                    $this->command?->info("  downloaded: {$seed}.jpg");
                } else {
                    $this->command?->warn("  failed ({$response->status()}): {$seed}.jpg");
                }
            } catch (\Exception $e) {
                $this->command?->warn("  error: {$seed}.jpg — {$e->getMessage()}");
            }
        }
    }

    /**
     * Scan the fixture directory and return the relative paths +
     * cached sizes of every JPG inside. Sizes are cached up-front so
     * we don't `stat` the same file 200 times during the seed.
     *
     * @return list<array{path: string, size: int}>
     */
    private function loadPool(Filesystem $storage): array
    {
        $files = $storage->files(self::FIXTURE_PREFIX);

        $pool = [];
        foreach ($files as $path) {
            if (! Str::endsWith(strtolower($path), '.jpg')) {
                continue;
            }
            $pool[] = ['path' => $path, 'size' => $storage->size($path)];
        }

        sort($pool);

        return $pool;
    }

    /**
     * @param  list<array{path: string, size: int}>  $pool
     * @return array{created: int, failed: int}
     */
    private function seedProducts(string $disk, array $pool): array
    {
        // Eager-load existing gallery files so we can top up each product to
        // PRODUCT_GALLERY_TARGET photos without N+1 queries. Products that
        // already have enough images are skipped inside topUpGallery().
        $products = Product::query()->with('gallery')->get();

        if ($products->isEmpty()) {
            return ['created' => 0, 'failed' => 0];
        }

        $this->command?->info("ProductGallerySeeder: topping up {$products->count()} product(s) to ".self::PRODUCT_GALLERY_TARGET." gallery image(s) via disk [{$disk}]…");

        $created = 0;

        foreach ($products as $product) {
            $created += $this->topUpGallery(
                model: $product,
                label: $product->slug ?? 'product',
                disk: $disk,
                pool: $pool,
                target: self::PRODUCT_GALLERY_TARGET,
            );
        }

        return ['created' => $created, 'failed' => 0];
    }

    /**
     * Ensure a model has at least `$target` distinct gallery photos.
     *
     * The first image is kept as the slug-named fixture (so the primary
     * thumbnail stays the dish's real photo); additional slots are picked
     * deterministically by walking the pool from a UUID-derived offset,
     * skipping any path the model already has. Idempotent — re-running
     * only appends what's missing, so it safely backfills existing data.
     *
     * @param  list<array{path: string, size: int}>  $pool
     * @return int number of new gallery rows created
     */
    private function topUpGallery(Model $model, string $label, string $disk, array $pool, int $target): int
    {
        $poolSize = count($pool);
        $target = min($target, $poolSize);

        /** @var Collection<int, File> $existing */
        $existing = $model->gallery;
        $existingPaths = $existing->pluck('path')->all();
        $have = $existing->count();

        if ($have >= $target) {
            return 0;
        }

        // Build the ordered candidate list: slug-named fixture first (if any),
        // then the rest of the pool starting at a stable per-model offset.
        $candidates = [];
        $namedPath = self::FIXTURE_PREFIX.'/'.Str::slug($label).'.jpg';
        if ($named = collect($pool)->firstWhere('path', $namedPath)) {
            $candidates[] = $named;
        }
        $start = $this->poolIndex((string) $model->getKey(), $poolSize);
        for ($i = 0; $i < $poolSize; $i++) {
            $candidates[] = $pool[($start + $i) % $poolSize];
        }

        $nextSort = $existing->max('sort_order');
        $nextSort = $nextSort === null ? 0 : ((int) $nextSort) + 1;

        $created = 0;
        foreach ($candidates as $slot) {
            if ($have >= $target) {
                break;
            }
            if (in_array($slot['path'], $existingPaths, true)) {
                continue;
            }

            File::create([
                'organization_id' => $model->organization_id,
                'fileable_type' => $model->getMorphClass(),
                'fileable_id' => $model->getKey(),
                'collection' => 'gallery',
                'disk' => $disk,
                'path' => $slot['path'],
                'original_name' => Str::slug($label).'-'.($nextSort + 1).'.jpg',
                'mime_type' => 'image/jpeg',
                'size' => $slot['size'],
                'status' => FileStatusEnum::Permanent,
                'expires_at' => null,
                'sort_order' => $nextSort,
            ]);

            $existingPaths[] = $slot['path'];
            $nextSort++;
            $have++;
            $created++;
        }

        return $created;
    }

    /**
     * @param  list<array{path: string, size: int}>  $pool
     * @return array{created: int, failed: int}
     */
    private function seedSkus(string $disk, array $pool): array
    {
        $skus = ProductSku::query()
            ->with('product:id,slug')
            ->whereDoesntHave('files', fn ($q) => $q->where('collection', 'gallery'))
            ->get();

        if ($skus->isEmpty()) {
            $this->command?->info('ProductGallerySeeder: all SKUs already have a gallery file — skipping SKU pass.');

            return ['created' => 0, 'failed' => 0];
        }

        $this->command?->info("ProductGallerySeeder: seeding {$skus->count()} SKU(s) via disk [{$disk}]…");

        $created = 0;
        $failed = 0;

        foreach ($skus as $sku) {
            $parentSlug = $sku->product?->slug;
            $label = $sku->sku ?? ($parentSlug ? "{$parentSlug}-sku" : 'sku');

            $ok = $this->attachFixture(
                model: $sku,
                label: $label,
                disk: $disk,
                pool: $pool,
            );

            $ok ? $created++ : $failed++;
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Pick a fixture deterministically and create the `files` row.
     * Prefers a fixture named after the model's slug when one exists,
     * otherwise falls back to a UUID-based pool slot.
     *
     * @param  list<array{path: string, size: int}>  $pool
     */
    private function attachFixture(Model $model, string $label, string $disk, array $pool): bool
    {
        $namedPath = self::FIXTURE_PREFIX.'/'.Str::slug($label).'.jpg';
        $byName = collect($pool)->firstWhere('path', $namedPath);
        $slot = $byName ?? $pool[$this->poolIndex((string) $model->getKey(), count($pool))];

        File::create([
            'organization_id' => $model->organization_id,
            'fileable_type' => $model->getMorphClass(),
            'fileable_id' => $model->getKey(),
            'collection' => 'gallery',
            'disk' => $disk,
            'path' => $slot['path'],
            'original_name' => Str::slug($label).'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => $slot['size'],
            'status' => FileStatusEnum::Permanent,
            'expires_at' => null,
            'sort_order' => 0,
        ]);

        return true;
    }

    /**
     * Map any string key to a stable [0, $size) pool slot.
     */
    private function poolIndex(string $key, int $size): int
    {
        return abs(crc32($key)) % $size;
    }
}
