<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ensure every brand has a baseline set of categories + at least the
 * top-N products linked to them. Idempotent — uses firstOrCreate so it
 * can run any number of times without dupes.
 *
 * Reason it exists: DashboardSeeder seeds categories for one demo brand
 * only. Other brands end up with an empty `categories.brand_id` set,
 * which makes the POS revenue 商品別売上 category dropdown appear empty
 * on any shop that points at those brands. This seeder fills the gap so
 * the filter has something to pick on every brand.
 */
class CategoriesPerBrandSeeder extends Seeder
{
    /**
     * @var array<string, array{slug: string, ja: string, en: string, vi: string}>
     */
    private array $categories = [
        'CAT-MAIN' => [
            'slug' => 'main',
            'ja' => 'メイン',
            'en' => 'Main',
            'vi' => 'Món chính',
        ],
        'CAT-SIDE' => [
            'slug' => 'side',
            'ja' => 'サイド',
            'en' => 'Side',
            'vi' => 'Món phụ',
        ],
        'CAT-DRINK' => [
            'slug' => 'drink',
            'ja' => 'ドリンク',
            'en' => 'Drink',
            'vi' => 'Đồ uống',
        ],
        'CAT-DESSERT' => [
            'slug' => 'dessert',
            'ja' => 'デザート',
            'en' => 'Dessert',
            'vi' => 'Tráng miệng',
        ],
    ];

    public function run(): void
    {
        $brands = Brand::all();

        foreach ($brands as $brand) {
            // Skip brands that already have at least one un-prefixed
            // category from a prior DashboardSeeder run — those rows
            // are the source of truth and ours would just shadow them.
            $hasOriginals = Category::where('brand_id', $brand->id)
                ->where('sku', 'NOT LIKE', '%::%')
                ->exists();
            if ($hasOriginals) {
                $this->command?->info("Brand {$brand->id}: already has categories, skipping");

                continue;
            }

            // Brand.console_organization_id is the SSO identifier; the
            // categories table FKs against the LOCAL organizations.id.
            $localOrgId = Organization::where('console_organization_id', $brand->console_organization_id)->value('id');
            if (! $localOrgId) {
                $this->command?->warn("Brand {$brand->id}: no local organization row for console_organization_id={$brand->console_organization_id}, skipping");

                continue;
            }

            $createdCats = [];

            foreach ($this->categories as $sku => $def) {
                // categories has a unique key on (organization_id, sku),
                // so categories can't repeat across brands in the same
                // org. Disambiguate by prefixing the sku with the
                // brand id; the human-facing name still matches the
                // mockup vocabulary.
                $scopedSku = "{$brand->id}::{$sku}";
                $cat = Category::firstOrCreate(
                    [
                        'organization_id' => $localOrgId,
                        'brand_id' => $brand->id,
                        'sku' => $scopedSku,
                    ],
                    [
                        'name' => $def['ja'],
                        'slug' => $def['slug'],
                        'is_active' => true,
                    ],
                );

                // Backfill translations for every locale.
                foreach (['ja', 'en', 'vi'] as $locale) {
                    $trans = $cat->translateOrNew($locale);
                    if (empty($trans->name)) {
                        $trans->name = $def[$locale];
                        $trans->save();
                    }
                }

                $createdCats[$sku] = $cat;
            }

            // Link every product in this brand to CAT-MAIN at minimum so
            // the revenue ranking always has a category label per row.
            // Skip if the product already belongs to any category.
            $products = Product::where('brand_id', $brand->id)->get();
            foreach ($products as $product) {
                $hasAny = DB::table('product_category')
                    ->where('product_id', $product->id)
                    ->exists();
                if ($hasAny) {
                    continue;
                }

                DB::table('product_category')->insert([
                    'product_id' => $product->id,
                    'category_id' => $createdCats['CAT-MAIN']->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->command?->info("Brand {$brand->id}: ensured ".count($createdCats).' categories, linked '.$products->count().' products');
        }
    }
}
