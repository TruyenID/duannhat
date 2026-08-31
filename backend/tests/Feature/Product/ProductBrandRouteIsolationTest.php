<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Models\VariantUnit;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->routeBrand = Brand::factory()->create(['console_organization_id' => $this->organization->id, 'slug' => 'route-brand']);
    $this->siblingBrand = Brand::factory()->create(['console_organization_id' => $this->organization->id, 'slug' => 'sibling-brand']);
    $this->user = User::factory()->create(['console_organization_id' => $this->organization->id]);
    grantOrgAccess($this->user, $this->organization->id);
    $this->actingAs($this->user);
    $type = ProductType::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->siblingBrand->id]);
    $this->product = Product::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->siblingBrand->id, 'product_type_id' => $type->id]);
    $this->option = ProductOption::factory()->create(['product_id' => $this->product->id, 'key' => 'size', 'name' => 'Size', 'position' => 1]);
    $this->value = ProductOptionValue::factory()->create(['option_id' => $this->option->id, 'value' => 'small', 'label' => 'Small', 'position' => 1]);
    $this->sku = ProductSku::factory()->create(['product_id' => $this->product->id]);
    $this->unit = VariantUnit::factory()->create(['product_sku_id' => $this->sku->id, 'is_base' => false]);
    $this->base = "/api/v1/hq/{$this->routeBrand->slug}";
});

it('blocks sibling-brand product show update and delete routes', function () {
    $this->getJson("{$this->base}/products/{$this->product->id}")->assertForbidden();
    $this->putJson("{$this->base}/products/{$this->product->id}", ['name' => 'Leaked'])->assertForbidden();
    $this->deleteJson("{$this->base}/products/{$this->product->id}")->assertForbidden();
    expect($this->product->fresh()->name)->not->toBe('Leaked')->and($this->product->fresh()->trashed())->toBeFalse();
});

it('blocks sibling-brand option and value show update delete routes', function () {
    $this->getJson("{$this->base}/product-options/{$this->option->id}")->assertForbidden();
    $this->putJson("{$this->base}/product-options/{$this->option->id}", ['name' => 'Leaked'])->assertForbidden();
    $this->deleteJson("{$this->base}/product-options/{$this->option->id}")->assertForbidden();
    $this->getJson("{$this->base}/product-option-values/{$this->value->id}")->assertForbidden();
    $this->putJson("{$this->base}/product-option-values/{$this->value->id}", ['label' => 'Leaked'])->assertForbidden();
    $this->deleteJson("{$this->base}/product-option-values/{$this->value->id}")->assertForbidden();
    expect($this->option->fresh()->name)->not->toBe('Leaked')->and($this->value->fresh()->label)->not->toBe('Leaked');
});

it('blocks sibling-brand option expand and sync routes', function () {
    $this->postJson("{$this->base}/products/{$this->product->id}/options/expand", [
        'key' => 'color', 'name' => 'Color', 'position' => 2,
        'values' => [['value' => 'red', 'label' => 'Red']], 'default_value_index' => 0,
    ])->assertForbidden();
    $this->putJson("{$this->base}/product-options/{$this->option->id}/sync-values", [
        'name' => 'Leaked', 'values' => [['id' => $this->value->id, 'value' => 'small', 'label' => 'Small']],
    ])->assertForbidden();
    expect(ProductOption::where('product_id', $this->product->id)->count())->toBe(1)->and($this->option->fresh()->name)->not->toBe('Leaked');
});

it('blocks sibling-brand category show update and delete routes', function () {
    $category = Category::factory()->create(['organization_id' => $this->organization->id, 'brand_id' => $this->siblingBrand->id, 'parent_id' => null]);
    $this->getJson("{$this->base}/categories/{$category->id}")->assertForbidden();
    $this->putJson("{$this->base}/categories/{$category->id}", ['name' => 'Leaked'])->assertForbidden();
    $this->deleteJson("{$this->base}/categories/{$category->id}")->assertForbidden();
    expect($category->fresh()->name)->not->toBe('Leaked')->and($category->fresh()->trashed())->toBeFalse();
});

it('blocks sibling-brand variant unit routes', function () {
    $this->getJson("{$this->base}/skus/{$this->sku->id}/units")->assertForbidden();
    $this->postJson("{$this->base}/skus/{$this->sku->id}/units", ['unit' => 'box', 'ratio' => 2, 'sku' => 'BOX'])->assertForbidden();
    $this->getJson("{$this->base}/sku-units/{$this->unit->id}")->assertForbidden();
    $this->putJson("{$this->base}/sku-units/{$this->unit->id}", ['unit' => 'leaked'])->assertForbidden();
    $this->deleteJson("{$this->base}/sku-units/{$this->unit->id}")->assertForbidden();
    $this->postJson("{$this->base}/sku-units/{$this->unit->id}/set-base")->assertForbidden();
    expect($this->unit->fresh()->unit)->not->toBe('leaked')->and(VariantUnit::whereKey($this->unit->id)->exists())->toBeTrue();
});
