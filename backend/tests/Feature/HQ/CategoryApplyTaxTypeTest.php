<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\TaxType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * #1074 — POST /api/v1/hq/{brand}/categories/{category}/apply-tax-type
 *
 * Single-rate world (#1099): the assigned tax type governs every product in
 * the category — alcohol included, by operator decision. `tax_type_id: null`
 * clears the per-product override (fall back to inheritance).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-hq',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->category = Category::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->taxType = TaxType::factory()->standard()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->url = "/api/v1/hq/{$this->brand->slug}/categories/{$this->category->id}/apply-tax-type";
});

function makeCategoryProduct(string $orgId, string $brandId, ?string $taxTypeId = null): Product
{
    return Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'tax_type_id' => $taxTypeId,
    ]);
}

it('bulk-assigns the tax type to every product in the category', function () {
    $products = collect([
        makeCategoryProduct($this->orgId, $this->brand->id),
        makeCategoryProduct($this->orgId, $this->brand->id),
        makeCategoryProduct($this->orgId, $this->brand->id),
    ]);
    $this->category->products()->attach($products->pluck('id')->all());

    $response = $this->actingAs($this->user)
        ->postJson($this->url, ['tax_type_id' => $this->taxType->id]);

    $response->assertOk()->assertJson(['data' => [
        'category_id' => $this->category->id,
        'tax_type_id' => $this->taxType->id,
        'updated' => 3,
    ]]);

    $products->each(function (Product $product) {
        expect($product->refresh()->tax_type_id)->toBe($this->taxType->id);
    });
});

it('clears the per-product override when tax_type_id is null', function () {
    $product = makeCategoryProduct($this->orgId, $this->brand->id, $this->taxType->id);
    $this->category->products()->attach($product->id);

    $response = $this->actingAs($this->user)
        ->postJson($this->url, ['tax_type_id' => null]);

    $response->assertOk()->assertJson(['data' => [
        'tax_type_id' => null,
        'updated' => 1,
    ]]);

    expect($product->refresh()->tax_type_id)->toBeNull();
});

it('does not touch products outside the category', function () {
    $inside = makeCategoryProduct($this->orgId, $this->brand->id);
    $outside = makeCategoryProduct($this->orgId, $this->brand->id);
    $this->category->products()->attach($inside->id);

    $this->actingAs($this->user)
        ->postJson($this->url, ['tax_type_id' => $this->taxType->id])
        ->assertOk()
        ->assertJsonPath('data.updated', 1);

    expect($inside->refresh()->tax_type_id)->toBe($this->taxType->id)
        ->and($outside->refresh()->tax_type_id)->toBeNull();
});

it('rejects a tax type belonging to another brand with 422', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-brand',
        'is_active' => true,
    ]);
    $foreignTaxType = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $otherBrand->id,
    ]);

    $product = makeCategoryProduct($this->orgId, $this->brand->id);
    $this->category->products()->attach($product->id);

    $this->actingAs($this->user)
        ->postJson($this->url, ['tax_type_id' => $foreignTaxType->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tax_type_id');

    expect($product->refresh()->tax_type_id)->toBeNull();
});

it('requires the tax_type_id key to be present', function () {
    $this->actingAs($this->user)
        ->postJson($this->url, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tax_type_id');
});

it('returns 403 for a category of another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $otherOrgId,
        'is_active' => true,
    ]);
    $foreignCategory = Category::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
    ]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/hq/{$this->brand->slug}/categories/{$foreignCategory->id}/apply-tax-type", [
            'tax_type_id' => $this->taxType->id,
        ])
        ->assertForbidden();
});
