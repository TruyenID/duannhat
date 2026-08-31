<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Services\Workstation\MenuCatalogReplicaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plan-047 thin-controller/fat-service — MenuCatalogReplicaController::index was
 * ~550 lines of query + flatten logic. It now lives in
 * MenuCatalogReplicaBuilder. These tests exercise the builder DIRECTLY (the
 * HTTP surface is covered by MenuCatalogToppingExpansionTest) to pin the feed
 * contract the workstation puller depends on.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->builder = app(MenuCatalogReplicaBuilder::class);

    // Seed a published menu with one product + one active sku. Returns the ids
    // so individual tests can layer overrides / translations / soft-deletes.
    $this->seedMenuProduct = function (array $productAttrs = [], array $skuAttrs = [], string $menuStatus = 'Active'): array {
        $product = Product::factory()->create(array_merge([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
            'status' => 'active',
            'is_hidden' => false,
            'name' => 'Burger',
        ], $productAttrs));

        $sku = ProductSku::factory()->create(array_merge([
            'product_id' => $product->id,
            'is_active' => true,
            'selling_price' => 1000,
        ], $skuAttrs));

        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => $menuStatus,
        ]);

        $mpId = (string) Str::uuid();
        DB::table('menu_products')->insert([
            'id' => $mpId,
            'menu_id' => $menu->id,
            'product_id' => $product->id,
            'is_active' => true,
            'display_order' => 1,
        ]);
        DB::table('menu_product_skus')->insert([
            'id' => (string) Str::uuid(),
            'menu_product_id' => $mpId,
            'product_sku_id' => $sku->id,
            'selling_price' => 1000,
            'is_active' => true,
        ]);

        return compact('product', 'sku', 'menu', 'mpId');
    };
});

it('returns the full empty shape for a null branch id', function () {
    $out = $this->builder->buildForBranch(null);

    expect($out)->toBe($this->builder->emptyShape())
        ->and(array_keys($out))->toContain(
            'menus', 'sections', 'menu_products', 'products', 'product_galleries',
            'skus', 'product_options', 'product_option_values', 'topping_groups',
            'topping_group_items', 'topping_group_item_skus', 'product_topping_groups',
            'product_topping_item_overrides', 'menu_product_topping_overrides',
        )
        ->and($out['menus'])->toBe([])
        ->and($out['skus'])->toBe([]);
});

it('returns the empty shape when the branch has no published menus', function () {
    ($this->seedMenuProduct)(menuStatus: 'draft');

    expect($this->builder->buildForBranch($this->branch->id))->toBe($this->builder->emptyShape());
});

it('includes published/active menus and excludes draft ones', function () {
    ($this->seedMenuProduct)(['name' => 'Live'], menuStatus: 'published');
    ($this->seedMenuProduct)(['name' => 'Hidden'], menuStatus: 'draft');

    $out = $this->builder->buildForBranch($this->branch->id);

    expect($out['menus'])->toHaveCount(1)
        ->and($out['products'])->toHaveCount(1)
        ->and($out['products'][0]['name'])->toBe('Live');
});

it('drops a menu_product whose base product is soft-deleted (SQLite FK 787 guard)', function () {
    $live = ($this->seedMenuProduct)(['name' => 'Live']);
    $gone = ($this->seedMenuProduct)(['name' => 'Gone']);
    $gone['product']->delete(); // soft delete

    $out = $this->builder->buildForBranch($this->branch->id);

    $productIds = collect($out['products'])->pluck('id')->all();
    $menuProductProductIds = collect($out['menu_products'])->pluck('product_id')->all();

    expect($productIds)->toContain($live['product']->id)
        ->and($productIds)->not->toContain($gone['product']->id)
        // The dangling menu_product must be dropped too, else the workstation
        // inserts a pos_product_skus row referencing a missing pos_products id.
        ->and($menuProductProductIds)->not->toContain($gone['product']->id);
});

it('derives is_active from status + is_hidden', function () {
    ($this->seedMenuProduct)(['name' => 'Shown', 'status' => 'active', 'is_hidden' => false]);
    ($this->seedMenuProduct)(['name' => 'Flagged', 'status' => 'active', 'is_hidden' => true]);

    $out = $this->builder->buildForBranch($this->branch->id);

    $shown = collect($out['products'])->firstWhere('name', 'Shown');
    $flagged = collect($out['products'])->firstWhere('name', 'Flagged');

    expect($shown['is_active'])->toBeTrue()
        ->and($flagged['is_active'])->toBeFalse();
});

it('applies the menu_product_sku price override and reports default_price + flag', function () {
    $seed = ($this->seedMenuProduct)([], ['selling_price' => 1000]);

    // Flip the menu override to 1200 and mark it overridden.
    DB::table('menu_product_skus')
        ->where('menu_product_id', $seed['mpId'])
        ->update(['selling_price' => 1200, 'is_price_overridden' => true]);

    $out = $this->builder->buildForBranch($this->branch->id);
    $sku = collect($out['skus'])->firstWhere('id', $seed['sku']->id);

    expect($sku['selling_price'])->toBe(1200)      // effective (override)
        ->and($sku['default_price'])->toBe(1000)   // canonical product_sku price
        ->and($sku['is_price_overridden'])->toBeTrue();
});

it('resolves the localized product name (translation wins over base column)', function () {
    app()->setLocale('ja');
    $seed = ($this->seedMenuProduct)(['name' => 'Burger']);

    DB::table('product_translations')->updateOrInsert(
        ['product_id' => $seed['product']->id, 'locale' => 'ja'],
        ['name' => 'バーガー'],
    );
    // Drop any auto-created non-ja translations so the base-column fallback
    // assertions below aren't shadowed by a factory-seeded en/vi row.
    DB::table('product_translations')
        ->where('product_id', $seed['product']->id)
        ->where('locale', '!=', 'ja')
        ->delete();

    $out = $this->builder->buildForBranch($this->branch->id);
    $product = collect($out['products'])->firstWhere('id', $seed['product']->id);

    expect($product['name'])->toBe('バーガー')
        ->and($product['name_ja'])->toBe('バーガー')
        ->and($product['name_en'])->toBeNull();
});

it('falls back to the base name column when no translation exists', function () {
    $seed = ($this->seedMenuProduct)(['name' => 'PlainName']);

    $out = $this->builder->buildForBranch($this->branch->id);
    $product = collect($out['products'])->firstWhere('id', $seed['product']->id);

    expect($product['name'])->toBe('PlainName')
        ->and($product['name_ja'])->toBeNull();
});

it('inherits the master menu service_type when the branch menu leaves it null (#481)', function () {
    // Master (HQ) menu carries the service_type; branch menu inherits it.
    $master = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'status' => 'Active',
        'service_type' => 'DineIn',
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Inherited',
    ]);
    $branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
        'service_type' => null,
        'master_menu_id' => $master->id,
    ]);
    DB::table('menu_products')->insert([
        'id' => (string) Str::uuid(),
        'menu_id' => $branchMenu->id,
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $out = $this->builder->buildForBranch($this->branch->id);
    $menu = collect($out['menus'])->firstWhere('id', $branchMenu->id);

    expect($menu['service_type'])->toBe('DineIn');
});

it('sorts sections by the pivot display_order and surfaces is_active true', function () {
    $seed = ($this->seedMenuProduct)();

    $sectionA = (string) Str::uuid();
    $sectionB = (string) Str::uuid();
    DB::table('menu_sections')->insert([
        ['id' => $sectionA, 'name' => 'Drinks', 'organization_id' => $this->orgId, 'brand_id' => $this->brand->id],
        ['id' => $sectionB, 'name' => 'Mains', 'organization_id' => $this->orgId, 'brand_id' => $this->brand->id],
    ]);
    DB::table('menu_menu_sections')->insert([
        ['menu_id' => $seed['menu']->id, 'menu_section_id' => $sectionA, 'display_order' => 2],
        ['menu_id' => $seed['menu']->id, 'menu_section_id' => $sectionB, 'display_order' => 1],
    ]);

    $out = $this->builder->buildForBranch($this->branch->id);

    expect($out['sections'])->toHaveCount(2)
        ->and($out['sections'][0]['name'])->toBe('Mains')   // display_order 1 first
        ->and($out['sections'][1]['name'])->toBe('Drinks')
        ->and($out['sections'][0]['is_active'])->toBeTrue();
});
