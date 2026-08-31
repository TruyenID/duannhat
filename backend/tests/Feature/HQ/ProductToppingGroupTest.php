<?php

/**
 * Plan 013 — ProductToppingGroupTest
 *
 * Covers:
 *   Happy H8  — POST /sync with group IDs → 200, pivot replaced
 *   Happy H9  — GET /products/{product}/topping-groups → list of assigned groups
 *   Edge E2   — POST sync with empty array → all detached, 200 with empty list
 *   Validation V8 — sync with group_id from different brand → 422
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-ptg-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $productType = ProductType::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->product = Product::factory()->create([
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}/topping-groups";
});

describe('happy path', function () {
    it('syncs topping groups to a product and replaces existing assignments', function () {
        $group1 = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        $group2 = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        // Pre-existing assignment
        ProductToppingGroup::create([
            'product_id' => $this->product->id,
            'topping_group_id' => $group1->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/sync", [
                'topping_group_ids' => [$group2->id],
            ]);

        $response->assertOk();

        // group1 detached, group2 attached
        expect(ProductToppingGroup::where('product_id', $this->product->id)
            ->where('topping_group_id', $group1->id)->exists())->toBeFalse();
        expect(ProductToppingGroup::where('product_id', $this->product->id)
            ->where('topping_group_id', $group2->id)->exists())->toBeTrue();
    });

    it('lists topping groups assigned to a product', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        ProductToppingGroup::create([
            'product_id' => $this->product->id,
            'topping_group_id' => $group->id,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($group->id);
    });
});

describe('edge cases', function () {
    it('detaches all groups when syncing with an empty array', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);
        ProductToppingGroup::create([
            'product_id' => $this->product->id,
            'topping_group_id' => $group->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/sync", [
                'topping_group_ids' => [],
            ]);

        $response->assertOk()
            ->assertJsonPath('data', []);

        expect(ProductToppingGroup::where('product_id', $this->product->id)->count())->toBe(0);
    });
});

describe('validation', function () {
    it('returns 422 when a group belongs to a different brand', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreignGroup = ToppingGroup::factory()->create([
            'brand_id' => $otherBrand->id,
            'organization_id' => $otherOrgId,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->base}/sync", [
                'topping_group_ids' => [$foreignGroup->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topping_group_ids.0']);
    });
});

describe('per-attachment overrides via sync endpoint', function () {
    it('persists min/max overrides keyed by group UUID', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'min_select' => 0,
            'max_select' => 5,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("{$this->base}/sync", [
                'topping_group_ids' => [$group->id],
                'overrides' => [
                    $group->id => ['min' => 1, 'max' => 2],
                ],
            ]);

        $response->assertOk();

        $row = ProductToppingGroup::where('product_id', $this->product->id)
            ->where('topping_group_id', $group->id)
            ->first();

        expect($row->min_select_override)->toBe(1)
            ->and($row->max_select_override)->toBe(2);
    });

    it('rejects min_override that exceeds the group max_select (422)', function () {
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'min_select' => 0,
            'max_select' => 3,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->base}/sync", [
                'topping_group_ids' => [$group->id],
                'overrides' => [
                    $group->id => ['min' => 5, 'max' => null],
                ],
            ])
            ->assertUnprocessable();
    });

    it('accepts a sync without the overrides key — inherit defaults (NULL)', function () {
        // Backwards-compatible — old clients calling sync without `overrides`
        // still work, attachments get NULL/NULL (= inherit).
        $group = ToppingGroup::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
        ]);

        $this->actingAs($this->user)
            ->postJson("{$this->base}/sync", [
                'topping_group_ids' => [$group->id],
            ])
            ->assertOk();

        $row = ProductToppingGroup::where('product_id', $this->product->id)->first();
        expect($row->min_select_override)->toBeNull()
            ->and($row->max_select_override)->toBeNull();
    });
});
