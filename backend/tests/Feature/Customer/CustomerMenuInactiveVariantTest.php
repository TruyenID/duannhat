<?php

declare(strict_types=1);

/**
 * A product variant whose menu_product_sku is deactivated at the shop must NOT
 * appear on the customer picker. The bug: transformOptions looped product
 * option VALUES and emitted a variant even when no active menu_product_sku
 * carried that value, so a shop-disabled size/temperature still showed up
 * (with sku_id = null) and the customer could "select" an unorderable option.
 */

use App\Models\Branch;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId]);
    $this->zone = Zone::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $this->table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'inactive-variant-token',
        'is_active' => true,
    ]);
});

it('hides a variant whose menu_product_sku is deactivated at the shop', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Drinks']);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create(['organization_id' => $this->orgId]);

    // Option "Size" with two values.
    $option = ProductOption::factory()->create(['product_id' => $product->id, 'is_active' => true, 'position' => 0]);
    $valS = ProductOptionValue::factory()->create(['option_id' => $option->id, 'label' => 'S', 'position' => 0, 'is_active' => true]);
    $valM = ProductOptionValue::factory()->create(['option_id' => $option->id, 'label' => 'M', 'position' => 1, 'is_active' => true]);

    $skuS = ProductSku::factory()->create(['product_id' => $product->id, 'option_value1_id' => $valS->id, 'selling_price' => 500, 'is_active' => true]);
    $skuM = ProductSku::factory()->create(['product_id' => $product->id, 'option_value1_id' => $valM->id, 'selling_price' => 700, 'is_active' => true]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    // S stays sellable, M is deactivated in THIS shop's menu.
    MenuProductSku::factory()->create(['menu_product_id' => $menuProduct->id, 'product_sku_id' => $skuS->id, 'selling_price' => 500, 'is_active' => true]);
    MenuProductSku::factory()->create(['menu_product_id' => $menuProduct->id, 'product_sku_id' => $skuM->id, 'selling_price' => 700, 'is_active' => false]);

    $response = $this->getJson('/api/v1/customer/tables/inactive-variant-token/menu');
    $response->assertOk();

    $item = $response->json('data.categories.0.items.0');
    $variants = collect($item['options'][0]['variants']);

    expect($variants->pluck('name')->all())->toBe(['S'])          // only the active variant
        ->and($variants->firstWhere('name', 'M'))->toBeNull();    // deactivated one is gone
});

it('drops the whole option when every variant is deactivated', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->branch->console_brand_id ?? $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);
    $section = MenuSection::factory()->create(['name' => 'Drinks']);
    $menu->menuSections()->attach($section);

    $product = Product::factory()->active()->create(['organization_id' => $this->orgId]);
    $option = ProductOption::factory()->create(['product_id' => $product->id, 'is_active' => true, 'position' => 0]);
    $valS = ProductOptionValue::factory()->create(['option_id' => $option->id, 'label' => 'S', 'position' => 0, 'is_active' => true]);
    $skuS = ProductSku::factory()->create(['product_id' => $product->id, 'option_value1_id' => $valS->id, 'selling_price' => 500, 'is_active' => true]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create(['menu_product_id' => $menuProduct->id, 'product_sku_id' => $skuS->id, 'selling_price' => 500, 'is_active' => false]);

    $response = $this->getJson('/api/v1/customer/tables/inactive-variant-token/menu');
    $response->assertOk();

    $item = $response->json('data.categories.0.items.0');
    // options is null (no selectable option left) rather than an empty picker.
    expect($item['options'])->toBeNull();
});
