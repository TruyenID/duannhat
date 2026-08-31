<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.3a ShowProductTest
 *
 * Covers TESTS.md scenarios:
 *   - Happy path #4        — GET /{id} returns product with relations
 *   - Authorization #23    — cross-brand product → 404/403 (brand cross-check)
 *   - Error handling #3    — non-existent uuid → 404
 *   - Error handling #4    — malformed uuid → 404
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-show',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

it('returns a product with its categories, options and skus eager-loaded', function () {
    $product = Product::factory()->forBrand($this->brand)->withOptions(2, 2)->create();
    $category = Category::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'parent_id' => null,
    ]);
    $product->categories()->attach($category->id);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}/{$product->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.name', $product->name)
        ->assertJsonStructure([
            'data' => [
                'id', 'name', 'categories', 'options', 'skus',
            ],
        ]);

    expect($response->json('data.options'))->toHaveCount(2);
});

it('returns 403 when the product belongs to another organization', function () {
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
        ->getJson("{$this->base}/{$foreignProduct->id}")
        ->assertForbidden();
});

it('returns 404 for a non-existent uuid', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}/".(string) Str::uuid())
        ->assertNotFound();
});

it('returns 404 for a malformed uuid', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}/not-a-uuid")
        ->assertNotFound();
});
