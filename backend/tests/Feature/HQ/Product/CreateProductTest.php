<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Plan 003 — T5.2 CreateProductTest
 *
 * Covers TESTS.md scenarios:
 *   - Happy path #3     — 201, product persisted, default SKU created with option_signature=""
 *   - Validation #12    — missing name → 422
 *   - Validation #13    — missing product_type_id → 422
 *   - Validation #14    — product_type_id from another brand/org → 422
 *   - Validation #15    — category_ids with a foreign id → 422
 *   - Validation #16/17 — slug uniqueness (not surfaced in API, category_ids duplicate scope check instead)
 *   - Validation #18    — status must be in allowed enum
 *   - Authorization #21 — unauthenticated → 401
 *   - Side effects #41  — created_by_id is the acting user
 *   - Side effects #45  — organization_id is forced to the acting user's org (not request body)
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-create',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products";
});

describe('happy path', function () {
    it('creates a product and auto-generates one default SKU with empty option signature', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'フォー',
            'slug' => 'pho',
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
            'status' => 'draft',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'フォー');

        $productId = $response->json('data.id');
        $product = Product::findOrFail($productId);

        expect($product->organization_id)->toBe($this->orgId)
            ->and($product->brand_id)->toBe($this->brand->id);

        $skus = ProductSku::where('product_id', $productId)->get();
        expect($skus)->toHaveCount(1);
        expect($skus->first()->option_signature)->toBe('');
    });
});

describe('validation', function () {
    it('returns 422 when name is missing', function () {
        // ProductStoreRequest::withValidator surfaces the cross-locale "no name in any
        // language" failure under the `ja.name` key (single error rather than one per
        // locale).
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'product_type_id' => $this->productType->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ja.name']);
    });

    it('returns 422 when product_type_id is missing', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'No Type',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_type_id']);
    });

    it('returns 422 when product_type_id belongs to a different organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreignType = ProductType::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
        ]);

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Cross-Org',
                'product_type_id' => $foreignType->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_type_id']);
    });

    it('returns 422 when category_ids contain a category from a foreign org', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreignCategory = Category::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $foreignBrand->id,
            'parent_id' => null,
        ]);

        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Cat Cross',
                'product_type_id' => $this->productType->id,
                'category_ids' => [$foreignCategory->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_ids.0']);
    });

    it('returns 422 when status is not in the allowed enum', function () {
        $this->actingAs($this->user)
            ->postJson($this->base, [
                'name' => 'Bad Status',
                'product_type_id' => $this->productType->id,
                'status' => 'bogus',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });
});

describe('authorization', function () {
    it('returns 401 when unauthenticated', function () {
        $this->postJson($this->base, [
            'name' => 'Guest',
            'product_type_id' => $this->productType->id,
        ])->assertUnauthorized();
    });
});

describe('side effects', function () {
    it('stamps created_by_id with the acting user', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Stamped',
            'slug' => 'stamped-'.Str::random(4),
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
        ]);

        $response->assertCreated();

        $product = Product::findOrFail($response->json('data.id'));
        expect($product->created_by_id)->toBe($this->user->id);
    });

    it('forces organization_id from the user context, not the request body', function () {
        $foreignOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $foreignOrgId,
            'console_organization_id' => $foreignOrgId,
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'Forced Org',
            'slug' => 'forced-'.Str::random(4),
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
            'organization_id' => $foreignOrgId,
        ]);

        $response->assertCreated();
        $product = Product::findOrFail($response->json('data.id'));
        expect($product->organization_id)->toBe($this->orgId);
    });
});
