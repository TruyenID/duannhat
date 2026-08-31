<?php

/**
 * #130 P2 — Shop/CatalogLookupController coverage
 *
 * Endpoints under test (auth: sso, scope: shop):
 *   GET /api/v1/shops/{shopSlug}/product-skus/lookup  — SKU dropdown for forms
 *   GET /api/v1/shops/{shopSlug}/materials/lookup     — Material dropdown
 *
 * Used by stock count / disposal / production order forms — staff need a
 * lookup that's already brand-scoped so they don't accidentally count SKUs
 * from another brand.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Services\Product\ProductQueryService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'cl-shop-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}";
});

// =============================================================================
// /product-skus/lookup
// =============================================================================

it('returns active product SKUs scoped to the shop\'s brand', function () {
    $type = ProductType::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    // 3 separate products → each gets its own default SKU. Same product
    // can't have two SKUs at the same option_signature (composite unique).
    Product::factory()->count(3)->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $type->id,
        'status' => 'active',
    ])->each(fn ($p) => ProductSku::factory()->create(['product_id' => $p->id]));

    $response = $this->actingAs($this->user)->getJson("{$this->base}/product-skus/lookup");
    $response->assertOk();
    expect($response->json('data'))->toBeArray()->not->toBeEmpty();
});

it('does not include SKUs from another brand', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    $type = ProductType::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
    ]);
    $product = Product::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $type->id,
        'status' => 'active',
    ]);
    ProductSku::factory()->create(['product_id' => $product->id]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}/product-skus/lookup");
    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('never lets explicitly included SKU IDs escape the organization and brand scope', function () {
    $localType = ProductType::factory()->create(['brand_id' => $this->brand->id, 'organization_id' => $this->orgId]);
    $localProduct = Product::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $localType->id,
    ]);
    $localInactive = ProductSku::factory()->inactive()->create(['product_id' => $localProduct->id]);

    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId, 'is_active' => true]);
    $otherType = ProductType::factory()->create(['brand_id' => $otherBrand->id, 'organization_id' => $this->orgId]);
    $otherProduct = Product::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
        'product_type_id' => $otherType->id,
    ]);
    $foreign = ProductSku::factory()->inactive()->create(['product_id' => $otherProduct->id]);

    $rows = app(ProductQueryService::class)->skuLookup(
        $this->orgId,
        $this->brand->id,
        [$localInactive->id, $foreign->id],
    );

    expect(collect($rows)->pluck('id')->all())->toBe([$localInactive->id]);
});

it('returns 401 without auth on /product-skus/lookup', function () {
    $this->getJson("{$this->base}/product-skus/lookup")->assertUnauthorized();
});

// =============================================================================
// /materials/lookup
// =============================================================================

it('returns active materials scoped to the shop\'s brand', function () {
    Material::factory()->count(2)->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}/materials/lookup");
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('excludes inactive materials', function () {
    Material::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Material::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'is_active' => false,
    ]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}/materials/lookup");
    expect($response->json('data'))->toHaveCount(1);
});

it('does not include materials from another brand', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Material::factory()->create([
        'brand_id' => $otherBrand->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->user)->getJson("{$this->base}/materials/lookup");
    expect($response->json('data'))->toBeEmpty();
});

it('returns 401 without auth on /materials/lookup', function () {
    $this->getJson("{$this->base}/materials/lookup")->assertUnauthorized();
});
