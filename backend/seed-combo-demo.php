<?php

/**
 * One-shot demo seeder: combo products + topping groups, attached to shop sjk's
 * branch menu. Idempotent — checks existence by slug before creating, so
 * re-running is safe.
 *
 * Layout:
 *   beto-kitchen (brand)
 *     └── product_type: combo (existing) + topping (created if missing)
 *     └── 4 topping products (Tương ớt, Tương cà, Mayonnaise, Coca-Cola)
 *     └── 2 topping groups (Nước sốt, Đồ uống thêm) with the toppings inside
 *     └── 3 combo products (Burger Combo, Gà Rán Combo, Phở Combo)
 *           with topping groups attached + 1-2 SKUs each
 *     └── 1 branch menu (sjk-demo) containing the 3 combos
 *           with menu_product_skus auto-created via MenuService::addProducts
 *
 * Run:  docker compose exec app php seed-combo-demo.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Product\MenuService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

function ok(string $msg): void
{
    echo "  ✓ $msg\n";
}

function info(string $msg): void
{
    echo "→ $msg\n";
}

// ---------------------------------------------------------------------------
// 1. Resolve brand + branch
// ---------------------------------------------------------------------------
info('Resolving brand + branch');
$branch = Branch::where('slug', 'sjk')->firstOrFail();
$brand = Brand::where('console_brand_id', $branch->console_brand_id)->firstOrFail();
// brand.console_organization_id is the external SSO id; the internal
// organizations.id is what other tables FK to. Look up the local row.
$orgId = Organization::where('console_organization_id', $brand->console_organization_id)
    ->firstOrFail()->id;
ok("brand={$brand->slug} branch={$branch->slug} org={$orgId}");

// ---------------------------------------------------------------------------
// 2. Get-or-create product types: combo (must exist) + topping (created if not)
// ---------------------------------------------------------------------------
info('Resolving product types');
$comboType = ProductType::where('brand_id', $brand->id)->where('code', 'combo')->firstOrFail();
$toppingType = ProductType::firstOrCreate(
    ['brand_id' => $brand->id, 'code' => 'topping'],
    [
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'name' => 'トッピング',
        'sort_order' => 99,
    ],
);
// Translations (Astrotomic) — set via translateOrNew so JA/EN/VI all populate.
foreach (['ja' => 'トッピング', 'en' => 'Topping', 'vi' => 'Topping'] as $loc => $label) {
    if ($toppingType->translateOrNew($loc)->name !== $label) {
        $toppingType->translateOrNew($loc)->name = $label;
    }
}
$toppingType->save();
ok("combo={$comboType->id} topping={$toppingType->id}");

// ---------------------------------------------------------------------------
// 3. Topping products (used as items in topping groups)
// ---------------------------------------------------------------------------
info('Creating topping products');
$toppingDefs = [
    ['slug' => 'sjk-demo-tuong-ot', 'ja' => 'チリソース', 'en' => 'Chili Sauce', 'vi' => 'Tương ớt', 'price' => 5000],
    ['slug' => 'sjk-demo-tuong-ca', 'ja' => 'ケチャップ', 'en' => 'Ketchup', 'vi' => 'Tương cà', 'price' => 5000],
    ['slug' => 'sjk-demo-mayo', 'ja' => 'マヨネーズ', 'en' => 'Mayonnaise', 'vi' => 'Mayonnaise', 'price' => 7000],
    ['slug' => 'sjk-demo-coca', 'ja' => 'コカ・コーラ', 'en' => 'Coca-Cola', 'vi' => 'Coca-Cola', 'price' => 15000],
];
$toppings = [];
foreach ($toppingDefs as $def) {
    $product = Product::firstOrCreate(
        ['slug' => $def['slug']],
        [
            'id' => (string) Str::uuid(),
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'product_type_id' => $toppingType->id,
            'name' => $def['ja'],
            'description' => null,
            'status' => 'active',
            'is_hidden' => false,
        ],
    );
    foreach (['ja', 'en', 'vi'] as $loc) {
        $product->translateOrNew($loc)->name = $def[$loc];
    }
    $product->save();

    // 1 simple SKU per topping product (no variants).
    ProductSku::firstOrCreate(
        // ProductSkuObserver writes signature as "||" for the all-NULL case
        // (no option_value*_id), so look it up under that exact value.
        ['product_id' => $product->id, 'option_signature' => '||'],
        [
            'id' => (string) Str::uuid(),
            'sku' => strtoupper(Str::random(8)),
            'name' => null,
            'cost_price' => $def['price'] * 0.4,
            'cost_price_auto' => $def['price'] * 0.4,
            'is_cost_override' => true,
            'selling_price' => $def['price'],
            'is_active' => true,
        ],
    );
    $toppings[$def['slug']] = $product;
    ok("topping product: {$def['ja']} ({$product->id})");
}

// ---------------------------------------------------------------------------
// 4. Topping groups (Nước sốt, Đồ uống thêm) with items
// ---------------------------------------------------------------------------
info('Creating topping groups');
$groupDefs = [
    [
        'slug_marker' => 'sjk-demo-sauces',
        'ja' => 'ソース', 'en' => 'Sauces', 'vi' => 'Nước sốt',
        'min_select' => 0, 'max_select' => 2, 'max_qty_per_item' => 1,
        'items' => ['sjk-demo-tuong-ot', 'sjk-demo-tuong-ca', 'sjk-demo-mayo'],
    ],
    [
        'slug_marker' => 'sjk-demo-drinks',
        'ja' => '追加ドリンク', 'en' => 'Extra drinks', 'vi' => 'Đồ uống thêm',
        'min_select' => 0, 'max_select' => 1, 'max_qty_per_item' => 1,
        'items' => ['sjk-demo-coca'],
    ],
];

// ToppingGroup has no `slug` column — match by per-locale name (ja) instead.
// Idempotent: re-find via translation join.
$groups = [];
foreach ($groupDefs as $def) {
    $existing = ToppingGroup::where('brand_id', $brand->id)
        ->whereHas('translations', fn ($q) => $q->where('locale', 'ja')->where('name', $def['ja']))
        ->first();

    $group = $existing ?? ToppingGroup::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'name' => $def['ja'],
        'min_select' => $def['min_select'],
        'max_select' => $def['max_select'],
        'max_qty_per_item' => $def['max_qty_per_item'],
        'sort_order' => 0,
        'is_active' => true,
    ]);
    foreach (['ja', 'en', 'vi'] as $loc) {
        $group->translateOrNew($loc)->name = $def[$loc];
    }
    $group->save();

    foreach ($def['items'] as $slug) {
        $itemProduct = $toppings[$slug];
        $item = ToppingGroupItem::firstOrCreate(
            ['topping_group_id' => $group->id, 'product_id' => $itemProduct->id],
            [
                'id' => (string) Str::uuid(),
                'sort_order' => 0,
            ],
        );
        // Per-SKU price override row — null product_sku_id = simple topping
        // (no variant). extra_price=0 means "use canonical sku.selling_price".
        ToppingGroupItemSku::firstOrCreate(
            ['topping_group_item_id' => $item->id, 'product_sku_id' => null],
            [
                'id' => (string) Str::uuid(),
                'extra_price' => 0,
            ],
        );
    }

    $groups[$def['slug_marker']] = $group;
    ok("topping group: {$def['ja']} (".count($def['items']).' items)');
}

// ---------------------------------------------------------------------------
// 5. Combo products with SKUs + attach topping groups
// ---------------------------------------------------------------------------
info('Creating combo products');
$comboDefs = [
    [
        'slug' => 'sjk-demo-combo-burger',
        'ja' => 'バーガーセット', 'en' => 'Burger Combo', 'vi' => 'Combo Burger',
        'desc' => 'Hamburger kèm chọn sốt và nước uống thêm theo ý thích.',
        'price' => 90000,
        'groups' => ['sjk-demo-sauces', 'sjk-demo-drinks'],
    ],
    [
        'slug' => 'sjk-demo-combo-fried-chicken',
        'ja' => 'フライドチキンセット', 'en' => 'Fried Chicken Combo', 'vi' => 'Combo Gà Rán',
        'desc' => 'Gà rán giòn rụm, chọn sốt yêu thích.',
        'price' => 90000,
        'groups' => ['sjk-demo-sauces'],
    ],
    [
        'slug' => 'sjk-demo-combo-pho',
        'ja' => 'フォーセット', 'en' => 'Pho Combo', 'vi' => 'Combo Phở Bò',
        'desc' => 'Phở bò truyền thống kèm nước uống.',
        'price' => 75000,
        'groups' => ['sjk-demo-drinks'],
    ],
];

$combos = [];
foreach ($comboDefs as $def) {
    $combo = Product::firstOrCreate(
        ['slug' => $def['slug']],
        [
            'id' => (string) Str::uuid(),
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'product_type_id' => $comboType->id,
            'name' => $def['ja'],
            'description' => $def['desc'],
            'status' => 'active',
            'is_hidden' => false,
        ],
    );
    foreach (['ja', 'en', 'vi'] as $loc) {
        $combo->translateOrNew($loc)->name = $def[$loc];
    }
    $combo->save();

    // Single simple SKU per combo. ProductSkuObserver recomputes
    // option_signature from option_value*_id columns, so creating multiple
    // SKUs on the same product without proper ProductOption setup would
    // collide on the unique (product_id, option_signature) index. Variants
    // require seeding ProductOption + ProductOptionValue first — out of
    // scope for this demo seeder.
    ProductSku::firstOrCreate(
        ['product_id' => $combo->id, 'option_signature' => '||'],
        [
            'id' => (string) Str::uuid(),
            'sku' => strtoupper(Str::random(8)),
            'name' => null,
            'cost_price' => $def['price'] * 0.5,
            'cost_price_auto' => $def['price'] * 0.5,
            'is_cost_override' => true,
            'selling_price' => $def['price'],
            'is_active' => true,
        ],
    );

    // Attach topping groups via product_topping_groups pivot.
    foreach ($def['groups'] as $groupKey) {
        $group = $groups[$groupKey];
        ProductToppingGroup::firstOrCreate(
            ['product_id' => $combo->id, 'topping_group_id' => $group->id],
            [
                'id' => (string) Str::uuid(),
                'sort_order' => 0,
            ],
        );
    }

    $combos[] = $combo;
    ok("combo: {$def['ja']} (1 SKU, ".count($def['groups']).' topping group(s))');
}

// ---------------------------------------------------------------------------
// 6. Branch menu for sjk + add combos to it
// ---------------------------------------------------------------------------
info("Creating branch menu for shop {$branch->slug}");
$menu = Menu::where('branch_id', $branch->id)
    ->where('name', 'SJK Demo Combo Menu')
    ->first();

if (! $menu) {
    // (branch_id, priority) is UNIQUE — find the next free slot. Falls back
    // to 1 when the branch has no menus yet.
    $nextPriority = (int) (Menu::where('branch_id', $branch->id)->max('priority') ?? 0) + 1;
    $menu = Menu::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'name' => 'SJK Demo Combo Menu',
        'description' => 'Demo combos seeded for shop sjk',
        'priority' => $nextPriority,
        'status' => 'Active',
        'is_master' => false,
        'master_menu_id' => null,
    ]);
}
ok("menu={$menu->id}");

info('Adding combos to menu (MenuService::addProducts auto-creates MenuProductSku)');
$menuService = app(MenuService::class);
$existingProductIds = $menu->menuProducts()->pluck('product_id')->all();
$toAdd = [];
foreach ($combos as $c) {
    if (! in_array($c->id, $existingProductIds, true)) {
        $toAdd[] = $c->id;
    }
}
if (count($toAdd) > 0) {
    $menuService->addProducts($menu, $toAdd);
    ok('added '.count($toAdd).' combo(s) to menu');
} else {
    ok('all combos already in menu (no-op)');
}

// ---------------------------------------------------------------------------
// 7. Summary
// ---------------------------------------------------------------------------
echo "\n=========================================\n";
echo "Done. Verify at:\n";
echo "  http://localhost:5430/shop/{$branch->slug}/menus/{$menu->id}\n";
echo "=========================================\n";
foreach ($menu->menuProducts()->with('product.translations', 'menuProductSkus')->get() as $mp) {
    $name = $mp->product->translations->firstWhere('locale', 'vi')?->name
        ?? $mp->product->translations->firstWhere('locale', 'ja')?->name
        ?? $mp->product->name;
    echo "  - {$name} | skus=".$mp->menuProductSkus->count()."\n";
}
