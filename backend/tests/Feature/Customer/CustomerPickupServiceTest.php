<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Services\CustomerPickupService;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * #1160 — the ETA is `prep_minutes_per_item x SUM(quantity)`, with the
 * per-item value resolved shop → brand → constant.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->selfRegistered()->create();
    $this->service = app(CustomerPickupService::class);

    $this->line = fn (int $quantity = 1) => [
        'product_sku_id' => (string) Str::uuid(),
        'quantity' => $quantity,
    ];
});

afterEach(function () {
    EffectiveOrderPolicyService::forgetForBranch($this->branch->id);
});

// =============================================================================
// Formula — per-item minutes x total quantity
// =============================================================================

it('falls back to the default per-item minutes when neither shop nor brand set one', function () {
    $minutes = $this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(1),
    ])['preparation_minutes'];

    expect($minutes)->toBe(EffectiveOrderPolicyService::DEFAULT_PREP_MINUTES_PER_ITEM);
});

it('multiplies by QUANTITY, not by line count', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => 4,
    ]);

    // One line, two portions → 8 minutes. The old formula read this as a
    // single item (15') and charged nothing for the second portion.
    expect($this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(2),
    ])['preparation_minutes'])->toBe(8);
});

it('sums quantity across lines', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => 3,
    ]);

    // 1 + 2 + 4 = 7 portions x 3' = 21 minutes.
    expect($this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(1),
        ($this->line)(2),
        ($this->line)(4),
    ])['preparation_minutes'])->toBe(21);
});

it('treats a line with no quantity as one portion', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => 6,
    ]);

    expect($this->service->calculateEstimatedReadyTime($this->branch, [
        ['product_sku_id' => (string) Str::uuid()],
    ])['preparation_minutes'])->toBe(6);
});

it('returns 0 minutes for an empty basket', function () {
    // No food, no wait. The old code promised 15 minutes for nothing.
    expect($this->service->calculateEstimatedReadyTime($this->branch, [])['preparation_minutes'])
        ->toBe(0);
});

it('allows a 0-minute setting for a shop that hands over pre-made goods', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => 0,
    ]);

    expect($this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(3),
    ])['preparation_minutes'])->toBe(0);
});

// =============================================================================
// Setting resolution — shop overrides brand, brand overrides the constant
// =============================================================================

it('uses the brand default when the shop has no override', function () {
    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_prep_minutes_per_item' => 7,
    ]);

    expect($this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(2),
    ])['preparation_minutes'])->toBe(14);
});

it('lets the shop override the brand default', function () {
    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_prep_minutes_per_item' => 7,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => 2,
    ]);

    expect($this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(2),
    ])['preparation_minutes'])->toBe(4);
});

it('inherits the brand default when the shop override is null', function () {
    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_prep_minutes_per_item' => 9,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => null,
    ]);

    expect($this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(1),
    ])['preparation_minutes'])->toBe(9);
});

it('reports where the resolved value came from', function () {
    $policyService = app(EffectiveOrderPolicyService::class);

    expect($policyService->resolve($this->branch)['source']['prep_minutes_per_item'])->toBe('default');

    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_prep_minutes_per_item' => 7,
    ]);
    EffectiveOrderPolicyService::forgetForBranch($this->branch->id);

    expect($policyService->resolve($this->branch)['source']['prep_minutes_per_item'])->toBe('brand');

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => 2,
    ]);
    EffectiveOrderPolicyService::forgetForBranch($this->branch->id);

    expect($policyService->resolve($this->branch)['source']['prep_minutes_per_item'])->toBe('shop');
});

// =============================================================================
// Estimated ready time
// =============================================================================

it('returns estimated_ready_time as now + preparation_minutes', function () {
    Carbon::setTestNow('2026-04-28 12:00:00');

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'prep_minutes_per_item' => 10,
    ]);

    $result = $this->service->calculateEstimatedReadyTime($this->branch, [
        ($this->line)(2),
    ]);

    expect($result['estimated_ready_time'])->toBeInstanceOf(Carbon::class)
        ->and($result['estimated_ready_time']->toDateTimeString())->toBe('2026-04-28 12:20:00');

    Carbon::setTestNow();
});
