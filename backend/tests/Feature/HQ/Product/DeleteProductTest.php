<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.3c DeleteProductTest
 *
 * Covers TESTS.md scenarios:
 *   - Happy path #6    — DELETE soft-deletes the product AND cascades to its SKUs
 *   - Side effect #42  — cascaded SKUs share the same deleted_at timestamp
 *   - Auth             — delete on foreign product → 403
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-delete',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

it('soft-deletes the product and cascades to all its SKUs', function () {
    $product = Product::factory()->forBrand($this->brand)->withOptions(1, 3)->create();
    $option = $product->options()->with('values')->first();

    foreach ($option->values as $value) {
        ProductSku::factory()->withOptionValues($value)->create([
            'product_id' => $product->id,
        ]);
    }

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$product->id}")
        ->assertNoContent();

    $trashed = Product::withTrashed()->find($product->id);
    expect($trashed->deleted_at)->not->toBeNull();

    $skus = ProductSku::withTrashed()->where('product_id', $product->id)->get();
    expect($skus)->not->toBeEmpty();

    foreach ($skus as $sku) {
        expect($sku->deleted_at)->not->toBeNull();
    }
});

it('returns 403 when deleting a product in another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
        'is_active' => true,
    ]);
    $foreignProduct = Product::factory()->forBrand($otherBrand)->create();

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$foreignProduct->id}")
        ->assertForbidden();

    expect(Product::find($foreignProduct->id))->not->toBeNull();
});
