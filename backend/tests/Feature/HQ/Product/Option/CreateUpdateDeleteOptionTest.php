<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.6 CreateUpdateDeleteOptionTest
 *
 * Covers TESTS.md scenarios:
 *   - Edge case #32        — position is immutable when SKUs reference the option
 *   - Edge case #33        — key is immutable when SKUs reference the option
 *   - Error handling #36   — DELETE returns 409 OPTION_IN_USE with blocking_skus
 *   - Authorization #25-28 — unauthenticated / foreign org / etc.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-opt',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->product = Product::factory()->forBrand($this->brand)->create();

    $this->productBase = "/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/options";
    $this->optionBase = "/api/v1/hq/{$this->brand->slug}/product-options";
});

it('creates an option in the given position and key', function () {
    $response = $this->actingAs($this->user)
        ->postJson($this->productBase, [
            'key' => 'size',
            'name' => 'Size',
            'position' => 1,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.key', 'size')
        ->assertJsonPath('data.position', 1);
});

it('allows PUT to change position even when SKUs reference the option (signature is order-independent)', function () {
    // Plan-003 update (commit 8ce7c249): option_signature is order-independent,
    // so swapping option positions no longer recreates SKUs and is allowed.
    $option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'position' => 1,
        'key' => 'size',
        'name' => 'Size',
    ]);
    $value = ProductOptionValue::factory()->create([
        'option_id' => $option->id,
        'value' => 's',
        'label' => 'S',
        'position' => 1,
    ]);
    ProductSku::factory()->withOptionValues($value)->create([
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->optionBase}/{$option->id}", [
            'position' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.position', 2);
});

it('rejects a PUT that changes key when SKUs reference the option', function () {
    $option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'position' => 1,
        'key' => 'size',
        'name' => 'Size',
    ]);
    $value = ProductOptionValue::factory()->create([
        'option_id' => $option->id,
        'value' => 's',
        'label' => 'S',
        'position' => 1,
    ]);
    ProductSku::factory()->withOptionValues($value)->create([
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->optionBase}/{$option->id}", [
            'key' => 'color',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['key']);
});

it('returns 409 OPTION_IN_USE with blocking_skus when deleting an option referenced by SKUs', function () {
    $option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'position' => 1,
        'key' => 'size',
        'name' => 'Size',
    ]);
    $value = ProductOptionValue::factory()->create([
        'option_id' => $option->id,
        'value' => 's',
        'label' => 'S',
        'position' => 1,
    ]);
    $sku = ProductSku::factory()->withOptionValues($value)->create([
        'product_id' => $this->product->id,
        'sku' => 'BLOCK-1',
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("{$this->optionBase}/{$option->id}");

    $response->assertStatus(409)
        ->assertJsonPath('error', 'OPTION_IN_USE');

    $blocking = $response->json('blocking_skus');
    expect($blocking)->toHaveCount(1);
    expect($blocking[0]['id'])->toBe($sku->id);
    expect($blocking[0]['sku'])->toBe('BLOCK-1');
});

it('deletes an option when no SKU references its values', function () {
    $option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'position' => 2,
        'key' => 'color',
        'name' => 'Color',
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->optionBase}/{$option->id}")
        ->assertNoContent();

    expect(ProductOption::find($option->id))->toBeNull();
});

it('returns 401 when unauthenticated on option endpoints', function () {
    $this->postJson($this->productBase, [
        'key' => 'size',
        'name' => 'Size',
        'position' => 1,
    ])->assertUnauthorized();
});

it('returns 403 when modifying an option in another organization', function () {
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
    $foreignOption = ProductOption::factory()->create([
        'product_id' => $foreignProduct->id,
        'position' => 1,
        'key' => 'size',
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->optionBase}/{$foreignOption->id}", [
            'name' => 'hack',
        ])
        ->assertForbidden();
});
