<?php

/**
 * #1126 — pos-web's SKU/topping/option modal reads
 * GET /api/v1/pos/menus/{menu}/products and renders
 * `product.topping_groups`. The report says the modal regressed to
 * sku-only. This pins the Cloud contract end-to-end: a product with an
 * active topping group (+ option/value on the topping product) must come
 * back with the full topping_groups chain.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        'slug' => 'pos-topping-probe',
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
});

it('returns the full topping_groups chain on /pos/menus/{menu}/products', function () {
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $menuProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Burger',
    ]);
    $menuSku = ProductSku::factory()->create([
        'product_id' => $menuProduct->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    $toppingProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Cheese',
    ]);
    $toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => 100,
        'name' => 'CheddarSlice',
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
        // MenuPolicy::shopView denies master menus — the factory randomizes
        // is_master, which made this test flip 403 ~half the runs.
        'is_master' => false,
    ]);
    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $menu->id,
        'product_id' => $menuProduct->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $menuSku->id,
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
        'product_id' => $menuProduct->id,
        'topping_group_id' => $groupId,
        'sort_order' => 0,
    ]);
    $itemId = (string) Str::uuid();
    DB::table('topping_group_items')->insert([
        'id' => $itemId,
        'topping_group_id' => $groupId,
        'product_id' => $toppingProduct->id,
        'sort_order' => 0,
        'is_default' => false,
    ]);
    DB::table('topping_group_item_skus')->insert([
        'id' => (string) Str::uuid(),
        'topping_group_item_id' => $itemId,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => 100,
    ]);

    $data = $this->actingAs($this->user)
        ->withHeader('X-Shop-Slug', $this->branch->slug)
        ->getJson("/api/v1/pos/menus/{$menu->id}/products")
        ->assertOk()
        ->json('data');

    $burger = collect($data)->first(fn ($row) => ($row['product']['name'] ?? null) === 'Burger');

    expect($burger)->not->toBeNull();
    $groups = $burger['product']['topping_groups'] ?? [];
    // Wire shape is FLATTENED (verified against the live serializer,
    // 2026-07-27): items carry name/image_url directly — not a nested
    // `product` object — and each item lists its skus with extra_price.
    // pos-web's ShopMenuToppingGroupItem type mirrors exactly this.
    expect($groups)->toHaveCount(1)
        ->and($groups[0]['name'] ?? null)->toBe('Extras')
        ->and($groups[0]['items'][0]['name'] ?? null)->toBe('Cheese')
        ->and($groups[0]['items'][0]['skus'][0]['extra_price'] ?? null)->toBe('100.00')
        ->and($groups[0]['items'][0]['skus'][0]['sku_label'] ?? null)->toBe('CheddarSlice');
});
