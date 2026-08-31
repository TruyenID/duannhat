<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regression: the menu-catalog feed must never emit a SKU (or any child
 * row) whose parent product it did not also emit. The `products` query
 * filters `whereNull('p.deleted_at')`, so a since-soft-deleted product
 * still referenced by an active `menu_products` row OR a
 * `topping_group_items` row used to leak its SKU into `skus[]` while its
 * `products[]` entry was dropped. On the workstation that orphan SKU
 * violates the `pos_product_skus.product_id -> pos_products(id)` foreign
 * key (SQLite error 787), aborting the whole catalog transaction and
 * leaving `pos_menus` empty — POS then shows "no menu at all".
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
    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
});

/** Every SKU the feed emits must have its parent product in products[]. */
function assertNoOrphanSkus(array $data): void
{
    $productIds = collect($data['products'])->pluck('id')->flip();
    $orphans = collect($data['skus'])
        ->reject(fn ($sku) => $productIds->has($sku['product_id']))
        ->pluck('id')
        ->all();

    expect($orphans)->toBe([], 'feed emitted SKUs whose product is missing from products[]');
}

it('drops a soft-deleted topping product and its orphan SKU from the feed', function () {
    // Menu product (the burger) — active.
    $burger = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Burger',
    ]);
    $burgerSku = ProductSku::factory()->create([
        'product_id' => $burger->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    // Topping product (the cheese) — SOFT-DELETED, but still wired to the
    // burger via an active topping group + item + item sku.
    $cheese = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Cheese',
    ]);
    $cheeseSku = ProductSku::factory()->create([
        'product_id' => $cheese->id,
        'is_active' => true,
        'selling_price' => 100,
    ]);

    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $this->menu->id,
        'product_id' => $burger->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $burgerSku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $groupId = (string) Str::uuid();
    DB::table('topping_groups')->insert([
        'id' => $groupId,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Extras',
        'selection_type' => 'multiple',
        'modifier_type' => 'add',
        'price_strategy' => 'flat',
        'min_select' => 0,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    DB::table('product_topping_groups')->insert([
        'product_id' => $burger->id,
        'topping_group_id' => $groupId,
        'sort_order' => 0,
    ]);
    $itemId = (string) Str::uuid();
    DB::table('topping_group_items')->insert([
        'id' => $itemId,
        'topping_group_id' => $groupId,
        'product_id' => $cheese->id,
        'sort_order' => 0,
        'is_default' => false,
    ]);
    DB::table('topping_group_item_skus')->insert([
        'id' => (string) Str::uuid(),
        'topping_group_item_id' => $itemId,
        'product_sku_id' => $cheeseSku->id,
        'extra_price' => 100,
    ]);

    // Delete the topping product AFTER wiring — mirrors production, where a
    // product is retired but its menu/topping graph is left behind.
    $cheese->delete();

    $data = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-catalog')
        ->assertOk()
        ->json('data');

    // The burger still ships in full.
    expect(collect($data['products'])->firstWhere('id', $burger->id))->not->toBeNull();
    expect(collect($data['skus'])->firstWhere('id', $burgerSku->id))->not->toBeNull();

    // The soft-deleted cheese + its SKU are gone — no orphan.
    expect(collect($data['products'])->firstWhere('id', $cheese->id))->toBeNull();
    expect(collect($data['skus'])->firstWhere('id', $cheeseSku->id))->toBeNull();

    assertNoOrphanSkus($data);
});

it('drops a menu_product whose base product is soft-deleted', function () {
    $ghost = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Ghost',
    ]);
    $ghostSku = ProductSku::factory()->create([
        'product_id' => $ghost->id,
        'is_active' => true,
        'selling_price' => 500,
    ]);

    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $this->menu->id,
        'product_id' => $ghost->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $ghostSku->id,
        'selling_price' => 500,
        'is_active' => true,
    ]);

    $ghost->delete();

    $data = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-catalog')
        ->assertOk()
        ->json('data');

    // The menu_product for the deleted product is excluded, and no orphan
    // SKU leaks through.
    expect(collect($data['menu_products'])->firstWhere('id', $mpId))->toBeNull();
    expect(collect($data['skus'])->firstWhere('id', $ghostSku->id))->toBeNull();

    assertNoOrphanSkus($data);
});
