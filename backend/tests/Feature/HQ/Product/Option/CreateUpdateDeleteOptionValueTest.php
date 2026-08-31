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
 * Plan 003 — T5.7 CreateUpdateDeleteOptionValueTest
 *
 * Covers TESTS.md scenarios:
 *   - Error handling #35 — DELETE returns 409 OPTION_VALUE_IN_USE with blocking_skus
 *   - Validation          — create requires value + label; slug format rule
 *   - Authorization       — unauthenticated / foreign org
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-optval',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->product = Product::factory()->forBrand($this->brand)->create();

    $this->option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'position' => 1,
        'key' => 'size',
        'name' => 'Size',
    ]);

    $this->optionBase = "/api/v1/hq/{$this->brand->slug}/product-options/{$this->option->id}/values";
    $this->valueBase = "/api/v1/hq/{$this->brand->slug}/product-option-values";
});

it('creates an option value', function () {
    $response = $this->actingAs($this->user)
        ->postJson($this->optionBase, [
            'value' => 's',
            'label' => 'Small',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.value', 's')
        ->assertJsonPath('data.label', 'Small');
});

it('returns 422 when value or label is missing', function () {
    $this->actingAs($this->user)
        ->postJson($this->optionBase, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value', 'label']);
});

it('returns 422 when value slug contains invalid characters', function () {
    $this->actingAs($this->user)
        ->postJson($this->optionBase, [
            'value' => 'Not Valid Slug!',
            'label' => 'X',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);
});

it('returns 409 OPTION_VALUE_IN_USE with blocking_skus when deleting a referenced value', function () {
    $value = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id,
        'value' => 's',
        'label' => 'S',
        'position' => 1,
    ]);
    $sku = ProductSku::factory()->withOptionValues($value)->create([
        'product_id' => $this->product->id,
        'sku' => 'VAL-BLOCK-1',
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("{$this->valueBase}/{$value->id}");

    $response->assertStatus(409)
        ->assertJsonPath('error', 'OPTION_VALUE_IN_USE');

    $blocking = $response->json('blocking_skus');
    expect($blocking)->toHaveCount(1);
    expect($blocking[0]['id'])->toBe($sku->id);
    expect($blocking[0]['sku'])->toBe('VAL-BLOCK-1');
});

it('deletes an unreferenced option value', function () {
    $value = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id,
        'value' => 'l',
        'label' => 'L',
        'position' => 2,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->valueBase}/{$value->id}")
        ->assertNoContent();

    expect(ProductOptionValue::find($value->id))->toBeNull();
});

it('returns 401 when unauthenticated', function () {
    $this->postJson($this->optionBase, [
        'value' => 's',
        'label' => 'Small',
    ])->assertUnauthorized();
});

it('returns 403 when mutating a value in another organization', function () {
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
    $foreignValue = ProductOptionValue::factory()->create([
        'option_id' => $foreignOption->id,
        'value' => 'xl',
        'label' => 'XL',
        'position' => 1,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->valueBase}/{$foreignValue->id}")
        ->assertForbidden();
});
