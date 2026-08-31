<?php

/**
 * Issue #161 — Per-attachment override fields on ProductToppingGroup
 *
 * Covers:
 *   - min_select_override / max_select_override accept Int via the model layer
 *   - NULL override defaults preserved (= "inherit group default")
 *   - Override on one attachment doesn't leak to another attachment of same
 *     group on a different product
 *
 * The HTTP sync endpoint test (verifying the payload accepts the override
 * fields) lives in `ProductToppingGroupTest.php` once the service is wired
 * to read them from the request — left as a Phase 2 task because the
 * customer-web POS effective-min/max calculator is what consumes them.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
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

    $productType = ProductType::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->burger = Product::factory()->create([
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->fries = Product::factory()->create([
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->sauceGroup = ToppingGroup::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'min_select' => 0,
        'max_select' => 3,
    ]);
});

it('persists min/max override columns on a pivot row', function () {
    $row = ProductToppingGroup::create([
        'product_id' => $this->burger->id,
        'topping_group_id' => $this->sauceGroup->id,
        'sort_order' => 0,
        'min_select_override' => 1,
        'max_select_override' => 1,
    ]);

    $fresh = $row->fresh();
    expect($fresh->min_select_override)->toBe(1)
        ->and($fresh->max_select_override)->toBe(1);
});

it('defaults override columns to NULL when not specified', function () {
    $row = ProductToppingGroup::create([
        'product_id' => $this->burger->id,
        'topping_group_id' => $this->sauceGroup->id,
    ]);

    $fresh = $row->fresh();
    expect($fresh->min_select_override)->toBeNull()
        ->and($fresh->max_select_override)->toBeNull();
});

it('keeps override values isolated between attachments on different products', function () {
    // Same group attached to two products — the Burger override (max=1) must
    // NOT leak onto the Fries attachment (max=NULL ⇒ inherit group default of 3).
    ProductToppingGroup::create([
        'product_id' => $this->burger->id,
        'topping_group_id' => $this->sauceGroup->id,
        'max_select_override' => 1,
    ]);
    ProductToppingGroup::create([
        'product_id' => $this->fries->id,
        'topping_group_id' => $this->sauceGroup->id,
        // no override — should remain NULL
    ]);

    $burgerRow = ProductToppingGroup::where('product_id', $this->burger->id)->first();
    $friesRow = ProductToppingGroup::where('product_id', $this->fries->id)->first();

    expect($burgerRow->max_select_override)->toBe(1)
        ->and($friesRow->max_select_override)->toBeNull();
});

it('demonstrates the COALESCE pattern for effective min/max', function () {
    // Documents how Phase 2 customer-web is expected to compute the effective
    // selection limits — the pivot row override wins when set, otherwise we
    // fall back to the group's default. This test pins the expected helper
    // behaviour so a future Phase 2 implementation can rely on it.
    $row = ProductToppingGroup::create([
        'product_id' => $this->burger->id,
        'topping_group_id' => $this->sauceGroup->id,
        'min_select_override' => 1,   // override
        'max_select_override' => null, // inherit
    ]);

    $effectiveMin = $row->min_select_override ?? $this->sauceGroup->min_select;
    $effectiveMax = $row->max_select_override ?? $this->sauceGroup->max_select;

    expect($effectiveMin)->toBe(1)         // override wins
        ->and($effectiveMax)->toBe(3);     // inherit from group
});
