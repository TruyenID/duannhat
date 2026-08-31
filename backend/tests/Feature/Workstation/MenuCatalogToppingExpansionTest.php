<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\File;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Topping-product expansion: products referenced via
 * `topping_group_items.product_id` are typically NOT in any
 * `menu_products` row (e.g. "Cheese" is a topping, not a menu item).
 * Pre-fix the menu-catalog feed only included menu products, so the
 * workstation pulled DOWN a topping group whose items pointed at
 * product_ids it never received — the LAN handler then rendered the
 * topping dialog with empty names, no thumbnails, no variant labels.
 *
 * This test seeds a menu product whose product has one topping group
 * referencing a SEPARATE topping product with its own gallery + option
 * variants + product_sku, and asserts every piece of that topping
 * product surfaces in the feed.
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
});

it('expands productIds + skuIds to cover topping products + their skus', function () {
    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    // Parent menu product (the burger).
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

    // Topping product (the cheese). NOT in any menu_products.
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

    // Topping product image + option + value (so workstation can show
    // a thumbnail + variant label on the topping picker).
    $toppingImage = File::factory()->create([
        'organization_id' => $this->orgId,
        // Morph alias, not FQCN — see MenuCatalogReplicaController for why.
        'fileable_type' => (new Product)->getMorphClass(),
        'fileable_id' => $toppingProduct->id,
        'status' => FileStatusEnum::Permanent,
        'collection' => 'gallery',
        'sort_order' => 1,
    ]);
    // Direct inserts so the translation trait doesn't sit between us
    // and the canonical column. The replica controller reads the
    // base column with DB::table — same path it takes in production.
    $optionId = (string) Str::uuid();
    DB::table('product_options')->insert([
        'id' => $optionId,
        'product_id' => $toppingProduct->id,
        'name' => 'Cheese Type',
        'key' => 'cheese_type',
        'position' => 1,
        'is_active' => true,
    ]);
    $optionValueId = (string) Str::uuid();
    DB::table('product_option_values')->insert([
        'id' => $optionValueId,
        'option_id' => $optionId,
        'value' => 'cheddar',
        'label' => 'Cheddar',
        'position' => 0,
        'is_active' => true,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
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

    // Topping group bound to the burger via the pivot. Inserted via
    // DB::table to avoid the ToppingGroup factory's max_qty_per_item
    // default (column dropped on 2026-02-13 omnify alter).
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

    $resp = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-catalog')
        ->assertOk();

    $data = $resp->json('data');

    // Expansion check #1 — topping product appears in products[] with image_url.
    $toppingOut = collect($data['products'])->firstWhere('id', $toppingProduct->id);
    expect($toppingOut)->not->toBeNull()
        ->and($toppingOut['name'])->toBe('Cheese')
        ->and($toppingOut['image_url'])->toBe($toppingImage->getUrl());

    // #2 — gallery row for the topping product is in product_galleries[].
    $galleryOut = collect($data['product_galleries'])->firstWhere('product_id', $toppingProduct->id);
    expect($galleryOut)->not->toBeNull()
        ->and($galleryOut['url'])->toBe($toppingImage->getUrl());

    // #3 — topping product's own SKU is in skus[].
    $skuOut = collect($data['skus'])->firstWhere('id', $toppingSku->id);
    expect($skuOut)->not->toBeNull()
        ->and($skuOut['name'])->toBe('CheddarSlice');

    // #4 — topping product's option + value land in product_options /
    // product_option_values so the LAN handler can resolve variant
    // labels for the topping picker.
    $optionOut = collect($data['product_options'])->firstWhere('id', $optionId);
    expect($optionOut)->not->toBeNull()
        ->and($optionOut['name'])->toBe('Cheese Type')
        ->and($optionOut['key'])->toBe('cheese_type');

    $valueOut = collect($data['product_option_values'])->firstWhere('option_id', $optionId);
    expect($valueOut)->not->toBeNull()
        ->and($valueOut['value'])->toBe('cheddar')
        ->and($valueOut['label'])->toBe('Cheddar');
});
