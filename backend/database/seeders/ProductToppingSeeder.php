<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\File;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Attaches topping groups to ALL products seeded by ProductSeeder.
 *
 * Creates realistic Vietnamese restaurant topping groups and links them
 * to every food/drink product so the customer-web always shows toppings.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=ProductToppingSeeder
 */
class ProductToppingSeeder extends Seeder
{
    /**
     * Picsum seed string used for the per-topping placeholder image.
     * Keyed by the same `$key` used in `createToppingProducts`. Names are
     * stable across re-runs so the same image is always attached to the
     * same topping (deterministic, idempotent).
     *
     * @var array<string, string>
     */
    private const TOPPING_IMAGE_SEEDS = [
        // Extra toppings
        'trung_op_la' => 'topping-trung-op-la',
        'trung_chien' => 'topping-trung-chien',
        'them_thit' => 'topping-them-thit',
        'them_rau' => 'topping-them-rau',
        'pho_mai' => 'topping-pho-mai',
        // Spice levels
        'khong_cay' => 'topping-khong-cay',
        'cay_nhe' => 'topping-cay-nhe',
        'cay_vua' => 'topping-cay-vua',
        'cay_nhieu' => 'topping-cay-nhieu',
        // Side add-ons
        'nuoc_mam' => 'topping-nuoc-mam',
        'tuong_ot' => 'topping-tuong-ot',
        'chanh' => 'topping-chanh',
        'gia_sach' => 'topping-gia-sach',
        // Drink customization
        'it_duong' => 'topping-it-duong',
        'nhieu_da' => 'topping-nhieu-da',
        'khong_da' => 'topping-khong-da',
        'them_tran_chau' => 'topping-them-tran-chau',
        'them_thach' => 'topping-them-thach',
    ];

    private const FIXTURE_PREFIX = 'topping-fixtures';

    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command->warn('No org found. Run LocalDevSeeder first.');

            return;
        }

        $orgId = $org->id;

        $brands = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($brands->isEmpty()) {
            $this->command->warn('No brands found.');

            return;
        }

        foreach ($brands as $brand) {
            $this->command->info("Seeding toppings for brand: {$brand->name}");
            $this->seedForBrand($orgId, $brand);
        }
    }

    private function seedForBrand(string $orgId, Brand $brand): void
    {
        // Ensure topping product type exists
        $toppingType = ProductType::firstOrCreate(
            ['brand_id' => $brand->id, 'code' => 'topping'],
            [
                'organization_id' => $orgId,
                'name' => 'Topping',
                'product_form' => 'physical',
            ],
        );

        // ─── Create topping products ────────────────────────────────────────
        $toppingProducts = $this->createToppingProducts($brand, $orgId, $toppingType);

        // ─── Create topping groups ──────────────────────────────────────────
        $groups = $this->createToppingGroups($brand, $orgId, $toppingProducts);

        // ─── Attach groups to ALL food/drink products ───────────────────────
        $foodType = ProductType::where('brand_id', $brand->id)->where('code', 'FOOD')->first();
        $drinkType = ProductType::where('brand_id', $brand->id)->where('code', 'DRINK')->first();

        $products = Product::where('brand_id', $brand->id)
            ->where('status', 'active')
            ->whereIn('product_type_id', array_filter([$foodType?->id, $drinkType?->id]))
            ->get();

        $attached = 0;
        foreach ($products as $product) {
            $isFood = $product->product_type_id === $foodType?->id;
            $isDrink = $product->product_type_id === $drinkType?->id;

            // All products get "Extra toppings" group
            $this->attachIfMissing($product, $groups['extras']);

            // All products get "Spice level" group
            $this->attachIfMissing($product, $groups['spice'], 1);

            // Food products get "Side add-ons"
            if ($isFood) {
                $this->attachIfMissing($product, $groups['sides'], 2);
            }

            // Drink products get "Drink customization"
            if ($isDrink) {
                $this->attachIfMissing($product, $groups['drink_custom'], 2);
            }

            $attached++;
        }

        $this->command->info("  Attached topping groups to {$attached} products.");
    }

    /**
     * @return array<string, Product>
     */
    private function createToppingProducts(Brand $brand, string $orgId, ProductType $toppingType): array
    {
        $items = [
            // Extra toppings
            'trung_op_la' => 'Trứng ốp la',
            'trung_chien' => 'Trứng chiên',
            'them_thit' => 'Thêm thịt',
            'them_rau' => 'Thêm rau',
            'pho_mai' => 'Phô mai',
            // Spice levels
            'khong_cay' => 'Không cay',
            'cay_nhe' => 'Cay nhẹ',
            'cay_vua' => 'Cay vừa',
            'cay_nhieu' => 'Cay nhiều',
            // Side add-ons
            'nuoc_mam' => 'Nước mắm thêm',
            'tuong_ot' => 'Tương ớt',
            'chanh' => 'Chanh',
            'gia_sach' => 'Giá sạch',
            // Drink customization
            'it_duong' => 'Ít đường',
            'nhieu_da' => 'Nhiều đá',
            'khong_da' => 'Không đá',
            'them_tran_chau' => 'Thêm trân châu',
            'them_thach' => 'Thêm thạch',
        ];

        $products = [];

        // Disk + storage handle reused across every topping image so we
        // don't re-resolve the config 18 times in the loop below.
        $disk = config('filesystems.default');
        $storage = Storage::disk($disk);

        foreach ($items as $key => $name) {
            $slug = Str::slug($name).'-'.Str::random(4);
            $product = Product::where('brand_id', $brand->id)
                ->where('name', $name)
                ->where('product_type_id', $toppingType->id)
                ->first();

            if (! $product) {
                $product = Product::create([
                    'brand_id' => $brand->id,
                    'organization_id' => $orgId,
                    'product_type_id' => $toppingType->id,
                    'name' => $name,
                    'slug' => $slug,
                    'status' => 'active',
                ]);

                ProductSku::create([
                    'product_id' => $product->id,
                    'sku' => 'TOP-'.strtoupper(substr(preg_replace('/[^a-z]/i', '', $key), 0, 6)).'-'.Str::random(4),
                    'name' => $name,
                    'option_signature' => '||',
                    'is_active' => true,
                ]);
            }

            // Attach a 1:1 placeholder image to the topping product so the
            // POS / customer-web can render thumbnails next to each option.
            // Idempotent: skipped when a `gallery` file already exists.
            $this->ensureToppingImage($product, $key, $disk, $storage);

            $products[$key] = $product;
        }

        return $products;
    }

    /**
     * Download (once) and attach a square placeholder image for the given
     * topping product. Stable across re-seeds because the Picsum seed name
     * is keyed off `$key` rather than randomized, so the same product
     * always points at the same fixture file.
     */
    private function ensureToppingImage(
        Product $product,
        string $key,
        string $disk,
        Filesystem $storage,
    ): void {
        // Idempotency — leave existing gallery alone.
        $hasGallery = File::query()
            ->where('fileable_type', $product->getMorphClass())
            ->where('fileable_id', $product->id)
            ->where('collection', 'gallery')
            ->exists();

        if ($hasGallery) {
            return;
        }

        $seed = self::TOPPING_IMAGE_SEEDS[$key] ?? 'topping-default';
        $path = self::FIXTURE_PREFIX."/{$seed}.jpg";

        if (! $storage->exists($path)) {
            try {
                // 1:1 aspect — POS topping picker shows a square thumbnail.
                $response = Http::timeout(20)
                    ->get("https://picsum.photos/seed/{$seed}/400/400.jpg");

                if (! $response->successful()) {
                    $this->command?->warn(
                        "  topping image fetch failed ({$response->status()}): {$seed}"
                    );

                    return;
                }

                $storage->put($path, $response->body());
            } catch (\Exception $e) {
                $this->command?->warn(
                    "  topping image error: {$seed} — {$e->getMessage()}"
                );

                return;
            }
        }

        File::create([
            'organization_id' => $product->organization_id,
            'fileable_type' => $product->getMorphClass(),
            'fileable_id' => $product->id,
            'collection' => 'gallery',
            'disk' => $disk,
            'path' => $path,
            'original_name' => $seed.'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => $storage->size($path) ?: 0,
            'status' => FileStatusEnum::Permanent,
            'expires_at' => null,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return array<string, ToppingGroup>
     */
    private function createToppingGroups(Brand $brand, string $orgId, array $toppingProducts): array
    {
        $groups = [];

        // ─── 1. Extra toppings (multi-select, 0-3) ──────────────────────────
        $extras = ToppingGroup::where('brand_id', $brand->id)
            ->whereHas('translations', fn ($q) => $q->where('name', 'Topping thêm'))
            ->first();

        if (! $extras) {
            $extras = ToppingGroup::create([
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'name' => 'Topping thêm',
                'modifier_type' => 'add',
                'selection_type' => 'multiple',
                'min_select' => 0,
                'max_select' => 3,
                'is_active' => true,
            ]);

            $this->attachItems($extras, [
                ['product' => $toppingProducts['trung_op_la'], 'extra_price' => 10000],
                ['product' => $toppingProducts['trung_chien'], 'extra_price' => 10000],
                ['product' => $toppingProducts['them_thit'], 'extra_price' => 20000],
                ['product' => $toppingProducts['them_rau'], 'extra_price' => 5000],
                ['product' => $toppingProducts['pho_mai'], 'extra_price' => 15000],
            ]);
        }
        $groups['extras'] = $extras;

        // ─── 2. Spice level (single-select, required) ───────────────────────
        $spice = ToppingGroup::where('brand_id', $brand->id)
            ->whereHas('translations', fn ($q) => $q->where('name', 'Mức cay'))
            ->first();

        if (! $spice) {
            $spice = ToppingGroup::create([
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'name' => 'Mức cay',
                'modifier_type' => 'add',
                'selection_type' => 'single',
                'min_select' => 1,
                'max_select' => 1,
                'is_active' => true,
            ]);

            $defaultItem = ToppingGroupItem::create([
                'topping_group_id' => $spice->id,
                'product_id' => $toppingProducts['khong_cay']->id,
                'sort_order' => 0,
                'is_default' => true,
            ]);
            $defaultSkuId = ProductSku::where('product_id', $toppingProducts['khong_cay']->id)
                ->orderBy('created_at')
                ->value('id');
            ToppingGroupItemSku::create([
                'topping_group_item_id' => $defaultItem->id,
                'product_sku_id' => $defaultSkuId,
                'extra_price' => 0,
            ]);

            $this->attachItems($spice, [
                ['product' => $toppingProducts['cay_nhe'], 'extra_price' => 0],
                ['product' => $toppingProducts['cay_vua'], 'extra_price' => 0],
                ['product' => $toppingProducts['cay_nhieu'], 'extra_price' => 0],
            ], 1);
        }
        $groups['spice'] = $spice;

        // ─── 3. Side add-ons for food (multi-select, 0-4) ───────────────────
        $sides = ToppingGroup::where('brand_id', $brand->id)
            ->whereHas('translations', fn ($q) => $q->where('name', 'Gia vị thêm'))
            ->first();

        if (! $sides) {
            $sides = ToppingGroup::create([
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'name' => 'Gia vị thêm',
                'modifier_type' => 'add',
                'selection_type' => 'multiple',
                'min_select' => 0,
                'max_select' => 4,
                'is_active' => true,
            ]);

            $this->attachItems($sides, [
                ['product' => $toppingProducts['nuoc_mam'], 'extra_price' => 0],
                ['product' => $toppingProducts['tuong_ot'], 'extra_price' => 0],
                ['product' => $toppingProducts['chanh'], 'extra_price' => 5000],
                ['product' => $toppingProducts['gia_sach'], 'extra_price' => 5000],
            ]);
        }
        $groups['sides'] = $sides;

        // ─── 4. Drink customization (multi-select, 0-2) ─────────────────────
        $drinkCustom = ToppingGroup::where('brand_id', $brand->id)
            ->whereHas('translations', fn ($q) => $q->where('name', 'Tuỳ chỉnh đồ uống'))
            ->first();

        if (! $drinkCustom) {
            $drinkCustom = ToppingGroup::create([
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'name' => 'Tuỳ chỉnh đồ uống',
                'modifier_type' => 'add',
                'selection_type' => 'multiple',
                'min_select' => 0,
                'max_select' => 2,
                'is_active' => true,
            ]);

            $this->attachItems($drinkCustom, [
                ['product' => $toppingProducts['it_duong'], 'extra_price' => 0],
                ['product' => $toppingProducts['nhieu_da'], 'extra_price' => 0],
                ['product' => $toppingProducts['khong_da'], 'extra_price' => 0],
                ['product' => $toppingProducts['them_tran_chau'], 'extra_price' => 10000],
                ['product' => $toppingProducts['them_thach'], 'extra_price' => 8000],
            ]);
        }
        $groups['drink_custom'] = $drinkCustom;

        return $groups;
    }

    private function attachItems(ToppingGroup $group, array $items, int $startOrder = 0): void
    {
        foreach ($items as $i => $row) {
            $item = ToppingGroupItem::create([
                'topping_group_id' => $group->id,
                'product_id' => $row['product']->id,
                'sort_order' => $startOrder + $i,
            ]);

            // Bind to the topping product's first ProductSku — required so
            // the POS picker can resolve a non-null product_sku_id for the
            // BR-OI06 merge key + the addItems request payload (the BE
            // FormRequest validates `product_sku_id` as required).
            $skuId = ProductSku::where('product_id', $row['product']->id)
                ->orderBy('created_at')
                ->value('id');

            ToppingGroupItemSku::create([
                'topping_group_item_id' => $item->id,
                'product_sku_id' => $skuId,
                'extra_price' => $row['extra_price'],
            ]);
        }
    }

    private function attachIfMissing(Product $product, ToppingGroup $group, int $sortOrder = 0): void
    {
        $exists = ProductToppingGroup::where('product_id', $product->id)
            ->where('topping_group_id', $group->id)
            ->exists();

        if (! $exists) {
            ProductToppingGroup::create([
                'product_id' => $product->id,
                'topping_group_id' => $group->id,
                'sort_order' => $sortOrder,
            ]);
        }
    }
}
