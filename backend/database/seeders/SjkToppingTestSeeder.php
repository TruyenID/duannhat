<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds realistic test toppings for shop `sjk` (新宿店).
 *
 * Plan 015 — provides enough breadth that POS staff can exercise every
 * topping UX path:
 *   - single mandatory selection (Spice, Sweetness)
 *   - single optional selection (Ice, Sauces)
 *   - multiple optional with cap (Add-ons, Drink toppings)
 *   - free_up_to_n strategy (Drink toppings — 1st free)
 *   - modifier_type=remove (No onion / No cilantro / …)
 *
 * Brand-scoped: groups are created PER brand owning the parent product
 * so the brand_id FK on topping_groups stays consistent. Attaches groups
 * by the parent product's stable slug (Pho/noodle → spice + add-ons +
 * remove, Coffee → sweetness + ice + drink toppings, …).
 *
 * Idempotent — every row uses firstOrCreate, safe to re-run.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=SjkToppingTestSeeder
 */
class SjkToppingTestSeeder extends Seeder
{
    private const SHOP_SLUG = 'sjk';

    /**
     * Group recipes — slug ⇒ config + items. Created once per brand.
     *
     * @var array<string, array{
     *     name: string,
     *     translations: array<string, string>,
     *     selection_type: string,
     *     modifier_type: string,
     *     min_select: int,
     *     max_select: int|null,
     *     price_strategy: string,
     *     free_quantity: int|null,
     *     items: array<int, array{name: string, price: int, is_default: bool}>,
     * }>
     */
    private const GROUP_RECIPES = [
        'spice' => [
            'name' => 'Spice level',
            'translations' => ['ja' => '辛さレベル', 'en' => 'Spice level', 'vi' => 'Mức độ cay'],
            'selection_type' => 'single',
            'modifier_type' => 'add',
            'min_select' => 1, 'max_select' => 1,
            'price_strategy' => 'flat', 'free_quantity' => null,
            'items' => [
                ['name' => 'Mild', 'price' => 0, 'is_default' => false],
                ['name' => 'Medium', 'price' => 0, 'is_default' => true],
                ['name' => 'Hot', 'price' => 0, 'is_default' => false],
                ['name' => 'Extra hot', 'price' => 0, 'is_default' => false],
            ],
        ],
        'sweetness' => [
            'name' => 'Sweetness',
            'translations' => ['ja' => '甘さ', 'en' => 'Sweetness', 'vi' => 'Độ ngọt'],
            'selection_type' => 'single',
            'modifier_type' => 'add',
            'min_select' => 1, 'max_select' => 1,
            'price_strategy' => 'flat', 'free_quantity' => null,
            'items' => [
                ['name' => '0% sugar', 'price' => 0, 'is_default' => false],
                ['name' => '50% sugar', 'price' => 0, 'is_default' => false],
                ['name' => '100% sugar', 'price' => 0, 'is_default' => true],
                ['name' => 'Extra sweet', 'price' => 0, 'is_default' => false],
            ],
        ],
        'ice' => [
            'name' => 'Ice level',
            'translations' => ['ja' => '氷レベル', 'en' => 'Ice level', 'vi' => 'Lượng đá'],
            'selection_type' => 'single',
            'modifier_type' => 'add',
            'min_select' => 0, 'max_select' => 1,
            'price_strategy' => 'flat', 'free_quantity' => null,
            'items' => [
                ['name' => 'Hot', 'price' => 0, 'is_default' => false],
                ['name' => 'Iced', 'price' => 0, 'is_default' => true],
                ['name' => 'Less ice', 'price' => 0, 'is_default' => false],
                ['name' => 'No ice', 'price' => 0, 'is_default' => false],
            ],
        ],
        // Add-on prices target ~5-15% of typical main-dish price
        // (¥1.000-¥3.000 mains in this seed). Egg ¥100, Extra beef ¥300
        // (most expensive — ~15%), Cheese ¥150, Extra noodles ¥150.
        // Veggies stays free as the pre-ticked default.
        'addons-food' => [
            'name' => 'Add-ons',
            'translations' => ['ja' => 'トッピング追加', 'en' => 'Add-ons', 'vi' => 'Thêm nguyên liệu'],
            'selection_type' => 'multiple',
            'modifier_type' => 'add',
            'min_select' => 0, 'max_select' => 3,
            'price_strategy' => 'flat', 'free_quantity' => null,
            'items' => [
                ['name' => 'Egg', 'price' => 100, 'is_default' => false],
                ['name' => 'Extra beef', 'price' => 300, 'is_default' => false],
                ['name' => 'Extra noodles', 'price' => 150, 'is_default' => false],
                ['name' => 'Cheese', 'price' => 150, 'is_default' => false],
                ['name' => 'Veggies', 'price' => 0, 'is_default' => true],
            ],
        ],
        // Drink-topping prices target ~5-10% of typical drink (¥1.000-2.000):
        // Boba ¥80, Aloe ¥80, Lychee ¥120, Coconut jelly ¥100. Three picked
        // would total ¥260 → with free_up_to_n=1 the most expensive (¥120)
        // is waived → customer pays ¥160. Sample math the staff can verify.
        'drink-toppings' => [
            'name' => 'Drink toppings (1st free)',
            'translations' => ['ja' => 'ドリンクトッピング（1つ無料）', 'en' => 'Drink toppings (1st free)', 'vi' => 'Topping đồ uống (1 miễn phí)'],
            'selection_type' => 'multiple',
            'modifier_type' => 'add',
            'min_select' => 0, 'max_select' => 3,
            'price_strategy' => 'free_up_to_n', 'free_quantity' => 1,
            'items' => [
                ['name' => 'Boba pearls', 'price' => 80, 'is_default' => false],
                ['name' => 'Aloe vera', 'price' => 80, 'is_default' => false],
                ['name' => 'Lychee jelly', 'price' => 120, 'is_default' => false],
                ['name' => 'Coconut jelly', 'price' => 100, 'is_default' => false],
            ],
        ],
        'sauces' => [
            'name' => 'Sauce',
            'translations' => ['ja' => 'ソース', 'en' => 'Sauce', 'vi' => 'Sốt'],
            'selection_type' => 'single',
            'modifier_type' => 'add',
            'min_select' => 0, 'max_select' => 1,
            'price_strategy' => 'flat', 'free_quantity' => null,
            'items' => [
                ['name' => 'Fish sauce', 'price' => 0, 'is_default' => true],
                ['name' => 'Soy sauce', 'price' => 0, 'is_default' => false],
                ['name' => 'Sriracha', 'price' => 0, 'is_default' => false],
                ['name' => 'Sweet chili', 'price' => 0, 'is_default' => false],
            ],
        ],
        'removes' => [
            'name' => 'Remove ingredients',
            'translations' => ['ja' => '材料を省く', 'en' => 'Remove ingredients', 'vi' => 'Bỏ bớt nguyên liệu'],
            'selection_type' => 'multiple',
            'modifier_type' => 'remove',
            'min_select' => 0, 'max_select' => 4,
            'price_strategy' => 'flat', 'free_quantity' => null,
            'items' => [
                ['name' => 'No onion', 'price' => 0, 'is_default' => false],
                ['name' => 'No cilantro', 'price' => 0, 'is_default' => false],
                ['name' => 'No bean sprouts', 'price' => 0, 'is_default' => false],
                ['name' => 'No peanuts (allergy)', 'price' => 0, 'is_default' => false],
            ],
        ],
    ];

    /**
     * Per-product attachment keyed by the stable, locale-independent slug.
     *
     * ProductSeeder stores the Japanese name in products.name, so matching
     * English keywords against that field silently left every SJK product
     * without pivots. Slugs are the canonical identifiers for seed data and
     * remain stable regardless of the active locale or translation backfills.
     *
     * @var array<string, array<int, string>>
     */
    private const ATTACHMENT_RULES = [
        'pho-bo' => ['spice', 'addons-food', 'removes'],
        'pho-ga' => ['spice', 'addons-food', 'removes'],
        'bun-cha' => ['spice', 'addons-food', 'removes'],
        'bun-bo-hue' => ['spice', 'addons-food', 'removes'],
        'mi-quang' => ['spice', 'addons-food', 'removes'],
        'cao-lau' => ['spice', 'addons-food', 'removes'],
        'banh-mi' => ['sauces', 'addons-food', 'removes'],
        'com-tam' => ['sauces', 'addons-food', 'removes'],
        'banh-cuon' => ['sauces', 'addons-food', 'removes'],
        'xoi-hat-sen' => ['sauces', 'addons-food', 'removes'],
        'goi-cuon' => ['sauces', 'removes'],
        'cha-gio' => ['sauces', 'removes'],
        'goi-du-du' => ['spice', 'removes'],
        'banh-xeo' => ['spice', 'removes'],
        'ca-phe-sua' => ['sweetness', 'ice', 'drink-toppings'],
        'ca-phe-trung' => ['sweetness', 'ice', 'drink-toppings'],
        'ca-phe-dua' => ['sweetness', 'ice', 'drink-toppings'],
        'tra-xa' => ['sweetness', 'ice', 'drink-toppings'],
        'tra-hoa-nhai' => ['sweetness', 'ice', 'drink-toppings'],
        'tra-sen' => ['sweetness', 'ice', 'drink-toppings'],
        'sinh-to' => ['sweetness', 'ice', 'drink-toppings'],
        'nuoc-mia' => ['sweetness', 'ice', 'drink-toppings'],
    ];

    public function run(): void
    {
        $shop = Branch::where('slug', self::SHOP_SLUG)->first();
        if (! $shop) {
            $this->command->warn('Shop "'.self::SHOP_SLUG.'" not found — run BranchSeeder first.');

            return;
        }

        // Collect distinct (brand_id, organization_id) pairs of all parent
        // products on every active branch menu of this shop. We scope the
        // groups per (brand) so attaching to a product within that brand
        // doesn't violate the topping_groups.brand_id FK.
        $brandIds = Menu::where('branch_id', $shop->id)
            ->where('status', 'Active')
            ->with('menuProducts.product:id,name,brand_id,organization_id')
            ->get()
            ->flatMap(fn ($m) => $m->menuProducts->pluck('product.brand_id')->filter()->unique())
            ->unique()
            ->values();

        $this->command->info("Seeding test toppings for {$shop->name} across {$brandIds->count()} brand(s):");

        // Cache groups per brand so we don't re-create groups every iteration.
        $groupsByBrandSlug = [];

        foreach ($brandIds as $brandId) {
            $brand = Brand::find($brandId);
            if (! $brand) {
                continue;
            }
            $this->command->info("  brand: {$brand->name}");

            $groupsByBrandSlug[$brand->id] = [];
            foreach (self::GROUP_RECIPES as $slug => $recipe) {
                $groupsByBrandSlug[$brand->id][$slug] = $this->ensureGroup($brand, $recipe);
            }
        }

        // Now walk every menu product on the shop and attach matching groups.
        $attached = 0;
        $menus = Menu::where('branch_id', $shop->id)
            ->where('status', 'Active')
            ->with('menuProducts.product')
            ->get();

        foreach ($menus as $menu) {
            foreach ($menu->menuProducts as $mp) {
                $product = $mp->product;
                if (! $product) {
                    continue;
                }

                $matched = $this->resolveGroupSlugs($product->slug);
                if ($matched === []) {
                    continue;
                }

                $sync = [];
                foreach ($matched as $i => $slug) {
                    $group = $groupsByBrandSlug[$product->brand_id][$slug] ?? null;
                    if ($group) {
                        $sync[$group->id] = ['sort_order' => $i];
                    }
                }

                if ($sync) {
                    $product->toppingGroups()->syncWithoutDetaching($sync);
                    $attached++;
                }
            }
        }

        $this->command->info("Attached topping groups to {$attached} menu products. Done.");
    }

    /**
     * Create (or fetch) a topping group + items + per-SKU prices.
     */
    private function ensureGroup(Brand $brand, array $recipe): ToppingGroup
    {
        // ToppingGroup.organization_id FK → organizations.id (local PK), NOT
        // the SSO console_organization_id on Brand. Resolve via Organization
        // lookup on the Brand's console SSO id.
        $orgId = Organization::where('console_organization_id', $brand->console_organization_id)
            ->value('id') ?? Organization::query()->value('id');

        $group = ToppingGroup::firstOrCreate(
            ['brand_id' => $brand->id, 'name' => $recipe['name']],
            [
                'organization_id' => $orgId,
                'is_active' => true,
                'min_select' => $recipe['min_select'],
                'max_select' => $recipe['max_select'],
                'modifier_type' => $recipe['modifier_type'],
                'selection_type' => $recipe['selection_type'],
                'price_strategy' => $recipe['price_strategy'],
                'free_quantity' => $recipe['free_quantity'],
            ],
        );

        // Sync translations — runs on both create and re-seed so all locales
        // are always present in topping_group_translations. Use DB::table
        // directly to bypass Astrotomic's id-resolution from firstOrCreate.
        foreach ($recipe['translations'] as $locale => $name) {
            DB::table('topping_group_translations')->updateOrInsert(
                ['topping_group_id' => $group->id, 'locale' => $locale],
                ['name' => $name],
            );
        }

        // $group->organization_id is the local PK; reuse for child Products too.

        foreach ($recipe['items'] as $i => $itemDef) {
            // Reuse an existing topping product with the same brand+name; create
            // via factory if missing (factory handles slug + product_type_id).
            $tp = Product::where('brand_id', $brand->id)
                ->where('name', $itemDef['name'])
                ->first();

            if (! $tp) {
                $tp = Product::factory()->create([
                    'organization_id' => $orgId,
                    'brand_id' => $brand->id,
                    'name' => $itemDef['name'],
                    'slug' => Str::slug($itemDef['name']).'-'.substr(md5($brand->id.$itemDef['name']), 0, 6),
                    'status' => 'active',
                ]);
            }

            $tsku = $tp->skus()->first();
            if (! $tsku) {
                $tsku = ProductSku::factory()->create([
                    'product_id' => $tp->id,
                    'name' => $itemDef['name'],
                    'is_active' => true,
                    'selling_price' => $itemDef['price'],
                ]);
            } else {
                // Refresh selling_price on re-run so price tweaks propagate.
                $tsku->update(['selling_price' => $itemDef['price']]);
            }

            $item = ToppingGroupItem::firstOrCreate(
                ['topping_group_id' => $group->id, 'product_id' => $tp->id],
                ['is_default' => $itemDef['is_default'], 'sort_order' => $i],
            );

            // updateOrCreate so re-running the seeder rescales prices —
            // firstOrCreate would freeze whatever was set on the first run.
            ToppingGroupItemSku::updateOrCreate(
                ['topping_group_item_id' => $item->id, 'product_sku_id' => $tsku->id],
                ['extra_price' => $itemDef['price']],
            );
        }

        return $group;
    }

    /**
     * Resolve which group slugs apply to a stable product slug.
     *
     * @return array<int, string>
     */
    private function resolveGroupSlugs(string $productSlug): array
    {
        return self::ATTACHMENT_RULES[$productSlug] ?? [];
    }
}
