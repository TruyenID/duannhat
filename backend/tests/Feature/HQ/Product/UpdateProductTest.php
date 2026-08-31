<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.3b UpdateProductTest
 *
 * Covers TESTS.md scenarios:
 *   - Happy path #5   — PUT updates name/description/category_ids; pivot synced
 *   - Side effect #44 — category_ids sync semantics (not attach)
 *   - Authorization   — update on foreign product → 403
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-update',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

it('updates name, description and syncs category_ids', function () {
    $product = Product::factory()->forBrand($this->brand)->create();
    $categoryA = Category::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'parent_id' => null,
    ]);
    $categoryB = Category::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'parent_id' => null,
    ]);
    $categoryC = Category::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'parent_id' => null,
    ]);
    $product->categories()->attach([$categoryA->id, $categoryC->id]);

    $response = $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'category_ids' => [$categoryA->id, $categoryB->id],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Updated Name')
        ->assertJsonPath('data.description', 'Updated description');

    $pivot = $product->categories()->pluck('categories.id')->sort()->values()->all();
    $expected = collect([$categoryA->id, $categoryB->id])->sort()->values()->all();

    expect($pivot)->toBe($expected);
});

it('accepts a PUT that keeps the existing slug on the same product (no duplicate error)', function () {
    $product = Product::factory()->forBrand($this->brand)->create(['slug' => 'keeper']);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$product->id}", [
            'name' => 'Renamed',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

it('returns 403 when updating a product in another organization', function () {
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
        ->putJson("{$this->base}/{$foreignProduct->id}", [
            'name' => 'hack',
        ])
        ->assertForbidden();
});
