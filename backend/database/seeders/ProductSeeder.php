<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductTranslation;
use App\Models\ProductType;
use App\Omnify\Enums\ProductStatusEnum;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Product\Commands\CreateProductCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\ValueObjects\ProductPayload;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a full Vietnamese restaurant product catalog with i18n (ja/en/vi).
 *
 * Requires: ProductTypes (FOOD, DRINK) and Categories (CAT-MAIN, CAT-SIDE, CAT-DRINK).
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=ProductSeeder
 */
class ProductSeeder extends Seeder
{
    public function run(ProductMutationFacade $mutations): void
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
            $foodType = ProductType::where('brand_id', $brand->id)->where('code', 'FOOD')->first();
            $drinkType = ProductType::where('brand_id', $brand->id)->where('code', 'DRINK')->first();
            $catMain = Category::where('brand_id', $brand->id)->where('sku', 'CAT-MAIN')->first();
            $catSide = Category::where('brand_id', $brand->id)->where('sku', 'CAT-SIDE')->first();
            $catDrink = Category::where('brand_id', $brand->id)->where('sku', 'CAT-DRINK')->first();

            if (! $foodType || ! $drinkType) {
                $this->command->warn("ProductTypes FOOD/DRINK not found for brand {$brand->slug}. Run LocalDevSeeder first.");

                continue;
            }

            $this->command->info("Seeding brand: {$brand->name} (slug: {$brand->slug})");
            $this->seedForBrand($orgId, $brand, $foodType, $drinkType, $catMain, $catSide, $catDrink, $mutations);
        }
    }

    private function seedForBrand(
        string $orgId,
        Brand $brand,
        ProductType $foodType,
        ProductType $drinkType,
        ?Category $catMain,
        ?Category $catSide,
        ?Category $catDrink,
        ProductMutationFacade $mutations,
    ): void {

        $products = [
            // ── メイン (Main dishes) ─────────────────────────
            ['slug' => 'pho-bo', 'ja' => 'フォー・ボー', 'en' => 'Beef Pho', 'vi' => 'Phở Bò', 'desc' => 'Traditional Vietnamese beef noodle soup with rice noodles, tender beef slices, and aromatic herbs', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'PHO-BO-R', 'name' => 'Regular', 'cost' => 800],
                ['sku' => 'PHO-BO-L', 'name' => 'Large', 'cost' => 1050],
            ]],
            ['slug' => 'pho-ga', 'ja' => 'フォー・ガー', 'en' => 'Chicken Pho', 'vi' => 'Phở Gà', 'desc' => 'Light and flavorful chicken noodle soup with tender chicken, rice noodles, and fresh herbs', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'PHO-GA-R', 'name' => 'Regular', 'cost' => 750],
                ['sku' => 'PHO-GA-L', 'name' => 'Large', 'cost' => 1000],
            ]],
            ['slug' => 'bun-cha', 'ja' => 'ブンチャー', 'en' => 'Bun Cha', 'vi' => 'Bún Chả', 'desc' => 'Grilled pork patties and slices served with vermicelli noodles, fresh herbs, and dipping sauce', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'BC-R', 'name' => 'Regular', 'cost' => 900],
            ]],
            ['slug' => 'bun-bo-hue', 'ja' => 'ブンボーフエ', 'en' => 'Bun Bo Hue', 'vi' => 'Bún Bò Huế', 'desc' => 'Spicy beef noodle soup from Hue with thick rice noodles, tender beef, and lemongrass broth', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'BBH-R', 'name' => 'Regular', 'cost' => 850],
                ['sku' => 'BBH-L', 'name' => 'Large', 'cost' => 1100],
            ]],
            ['slug' => 'banh-mi', 'ja' => 'バインミー', 'en' => 'Banh Mi', 'vi' => 'Bánh Mì', 'desc' => 'Vietnamese baguette sandwich with savory fillings, pickled vegetables, cilantro, and chili', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'BM-CL', 'name' => 'Classic', 'cost' => 500],
                ['sku' => 'BM-SP', 'name' => 'Special', 'cost' => 700],
                ['sku' => 'BM-VG', 'name' => 'Veggie', 'cost' => 480],
            ]],
            ['slug' => 'com-tam', 'ja' => 'コムタム', 'en' => 'Broken Rice', 'vi' => 'Cơm Tấm', 'desc' => 'Broken rice served with grilled meat, fried egg, pickled vegetables, and fish sauce', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'CT-SN', 'name' => 'Pork', 'cost' => 850],
                ['sku' => 'CT-GA', 'name' => 'Chicken', 'cost' => 800],
            ]],
            ['slug' => 'banh-xeo', 'ja' => 'バインセオ', 'en' => 'Vietnamese Crepe', 'vi' => 'Bánh Xèo', 'desc' => 'Crispy rice flour crepe filled with shrimp, pork, and bean sprouts, served with fresh herbs', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'BX-R', 'name' => 'Regular', 'cost' => 750],
            ]],
            ['slug' => 'banh-cuon', 'ja' => 'バインクオン', 'en' => 'Steamed Rice Rolls', 'vi' => 'Bánh Cuốn', 'desc' => 'Delicate steamed rice rolls filled with minced pork and mushrooms, served with fish sauce', 'type' => $foodType, 'cat' => $catMain, 'status' => 'active', 'skus' => [
                ['sku' => 'BQ-R', 'name' => 'Regular', 'cost' => 650],
            ]],
            ['slug' => 'mi-quang', 'ja' => 'ミークアン', 'en' => 'Mi Quang Noodles', 'vi' => 'Mì Quảng', 'desc' => 'Turmeric-infused noodles with shrimp, pork, peanuts, and fresh herbs in light broth', 'type' => $foodType, 'cat' => $catMain, 'status' => 'pending', 'skus' => [
                ['sku' => 'MQ-R', 'name' => 'Regular', 'cost' => 850],
            ]],
            ['slug' => 'cao-lau', 'ja' => 'カオラウ', 'en' => 'Cao Lau Noodles', 'vi' => 'Cao Lầu', 'desc' => 'Hoi An specialty noodles with pork, crispy rice crackers, greens, and savory broth', 'type' => $foodType, 'cat' => $catMain, 'status' => 'draft', 'skus' => [
                ['sku' => 'CL-R', 'name' => 'Regular', 'cost' => 900],
            ]],

            // ── サイド (Side dishes) ─────────────────────────
            ['slug' => 'goi-cuon', 'ja' => '生春巻き', 'en' => 'Fresh Spring Rolls', 'vi' => 'Gỏi Cuốn', 'type' => $foodType, 'cat' => $catSide, 'status' => 'active', 'skus' => [
                ['sku' => 'GC-2', 'name' => '2pc', 'cost' => 400],
                ['sku' => 'GC-4', 'name' => '4pc', 'cost' => 700],
            ]],
            ['slug' => 'cha-gio', 'ja' => '揚げ春巻き', 'en' => 'Fried Spring Rolls', 'vi' => 'Chả Giò', 'type' => $foodType, 'cat' => $catSide, 'status' => 'active', 'skus' => [
                ['sku' => 'CG-3', 'name' => '3pc', 'cost' => 450],
                ['sku' => 'CG-5', 'name' => '5pc', 'cost' => 680],
            ]],
            ['slug' => 'goi-du-du', 'ja' => '青パパイヤサラダ', 'en' => 'Green Papaya Salad', 'vi' => 'Gỏi Đu Đủ', 'type' => $foodType, 'cat' => $catSide, 'status' => 'active', 'skus' => [
                ['sku' => 'GDD-R', 'name' => 'Regular', 'cost' => 500],
            ]],
            ['slug' => 'banh-flan', 'ja' => 'バインフラン', 'en' => 'Crème Caramel', 'vi' => 'Bánh Flan', 'type' => $foodType, 'cat' => $catSide, 'status' => 'active', 'skus' => [
                ['sku' => 'BF-1', 'name' => '1pc', 'cost' => 300],
            ]],
            ['slug' => 'che', 'ja' => 'チェー', 'en' => 'Chè Dessert', 'vi' => 'Chè', 'type' => $foodType, 'cat' => $catSide, 'status' => 'active', 'skus' => [
                ['sku' => 'CHE-R', 'name' => 'Regular', 'cost' => 450],
            ]],
            ['slug' => 'xoi-hat-sen', 'ja' => '蓮の実おこわ', 'en' => 'Lotus Seed Sticky Rice', 'vi' => 'Xôi Hạt Sen', 'type' => $foodType, 'cat' => $catSide, 'status' => 'active', 'skus' => [
                ['sku' => 'XHS-R', 'name' => 'Regular', 'cost' => 550],
            ]],

            // ── ドリンク (Drinks) ─────────────────────────
            ['slug' => 'ca-phe-sua', 'ja' => 'ベトナムコーヒー', 'en' => 'Vietnamese Coffee', 'vi' => 'Cà Phê Sữa', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'CPS-H', 'name' => 'Hot', 'cost' => 400],
                ['sku' => 'CPS-I', 'name' => 'Iced', 'cost' => 450],
            ]],
            ['slug' => 'ca-phe-trung', 'ja' => 'エッグコーヒー', 'en' => 'Egg Coffee', 'vi' => 'Cà Phê Trứng', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'CPT-H', 'name' => 'Hot', 'cost' => 500],
                ['sku' => 'CPT-I', 'name' => 'Iced', 'cost' => 550],
            ]],
            ['slug' => 'ca-phe-dua', 'ja' => 'ココナッツコーヒー', 'en' => 'Coconut Coffee', 'vi' => 'Cà Phê Dừa', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'CPD-I', 'name' => 'Iced', 'cost' => 550],
            ]],
            ['slug' => 'tra-xa', 'ja' => 'レモングラスティー', 'en' => 'Lemongrass Tea', 'vi' => 'Trà Xả', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'TX-H', 'name' => 'Hot', 'cost' => 300],
                ['sku' => 'TX-I', 'name' => 'Iced', 'cost' => 350],
            ]],
            ['slug' => 'tra-hoa-nhai', 'ja' => 'ジャスミンティー', 'en' => 'Jasmine Tea', 'vi' => 'Trà Hoa Nhài', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'THN-H', 'name' => 'Hot', 'cost' => 300],
                ['sku' => 'THN-I', 'name' => 'Iced', 'cost' => 350],
            ]],
            ['slug' => 'tra-sen', 'ja' => '蓮茶', 'en' => 'Lotus Tea', 'vi' => 'Trà Sen', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'TS-H', 'name' => 'Hot', 'cost' => 350],
            ]],
            ['slug' => 'bia-saigon', 'ja' => 'サイゴンビール', 'en' => 'Saigon Beer', 'vi' => 'Bia Sài Gòn', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'BSG-330', 'name' => '330ml', 'cost' => 350],
            ]],
            ['slug' => 'bia-333', 'ja' => '333ビール', 'en' => '333 Beer', 'vi' => 'Bia 333', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'B333-330', 'name' => '330ml', 'cost' => 320],
            ]],
            ['slug' => 'sinh-to', 'ja' => 'シントー（スムージー）', 'en' => 'Smoothie', 'vi' => 'Sinh Tố', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'ST-MG', 'name' => 'Mango', 'cost' => 450],
                ['sku' => 'ST-AV', 'name' => 'Avocado', 'cost' => 480],
                ['sku' => 'ST-DL', 'name' => 'Dragon Fruit', 'cost' => 500],
            ]],
            ['slug' => 'nuoc-mia', 'ja' => 'ヌックミア', 'en' => 'Sugarcane Juice', 'vi' => 'Nước Mía', 'type' => $drinkType, 'cat' => $catDrink, 'status' => 'active', 'skus' => [
                ['sku' => 'NM-R', 'name' => 'Regular', 'cost' => 300],
            ]],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($products as $p) {
            $existing = Product::where('brand_id', $brand->id)
                ->where('slug', $p['slug'])
                ->first();

            if ($existing === null) {
                $description = $p['desc'] ?? null;
                $payload = new ProductPayload(
                    name: $p['ja'],
                    description: $description,
                    skus: [],
                    productTypeId: $p['type']->id,
                    categoryIds: $p['cat'] !== null ? [$p['cat']->id] : [],
                    hidden: false,
                    slug: $p['slug'],
                    translations: [
                        new LocalizedText(SupportedLocale::Japanese, $p['ja'], $description),
                        new LocalizedText(SupportedLocale::English, $p['en'], $description),
                        new LocalizedText(SupportedLocale::Vietnamese, $p['vi'], $description),
                    ],
                    status: ProductStatusEnum::from($p['status']),
                );

                $mutations->create(new CreateProductCommand(
                    new MutationContext($orgId, null, 'product-seeder', "product-seeder:{$brand->id}:{$p['slug']}"),
                    (string) Str::uuid(),
                    $brand->id,
                    $payload,
                    $payload->fingerprint(),
                ));

                $created++;
            } else {
                $skipped++;
            }

            $product = Product::where('brand_id', $brand->id)
                ->where('slug', $p['slug'])
                ->firstOrFail();

            $this->syncTranslations($product, $p);

            // SKUs (bypass observer — option_value*_id are all null for seed data)
            ProductSku::withoutEvents(function () use ($product, $p) {
                foreach ($p['skus'] as $s) {
                    ProductSku::firstOrCreate(
                        ['product_id' => $product->id, 'sku' => $s['sku']],
                        [
                            'name' => $s['name'],
                            'option_signature' => $s['sku'],
                            // issue #875 — the price operators enter is the menu
                            // price (selling_price, the single source of truth for
                            // menu display). `cost` in the seed data IS that price.
                            // cost_price stays 0 (auto-derived from recipe later).
                            'selling_price' => $s['cost'],
                            'cost_price' => 0,
                            'cost_price_auto' => 0,
                            'is_cost_override' => false,
                            'is_active' => true,
                        ]
                    );
                }
            });
        }

        $totalSkus = ProductSku::whereHas('product', fn ($q) => $q->where('brand_id', $brand->id))->count();

        $this->command->info("  Products: {$created} created, {$skipped} skipped");
        $this->command->info('  Total: '.Product::where('brand_id', $brand->id)->count()." products, {$totalSkus} SKUs");
    }

    /**
     * Backfill translations on every run, including products that already
     * existed before ProductSeeder gained localized payload support.
     *
     * @param  array{ja: string, en: string, vi: string, desc?: string|null}  $definition
     */
    private function syncTranslations(Product $product, array $definition): void
    {
        $table = (new ProductTranslation)->getTable();
        $description = $definition['desc'] ?? null;

        foreach ([
            'ja' => $definition['ja'],
            'en' => $definition['en'],
            'vi' => $definition['vi'],
        ] as $locale => $name) {
            DB::table($table)->updateOrInsert(
                ['product_id' => $product->id, 'locale' => $locale],
                ['name' => $name, 'description' => $description],
            );
        }
    }
}
