<?php

/**
 * Plan-039 — `CustomerOrderResource` must mirror `KioskOrderResource`'s
 * `split_mode` + `split_mode_locked` shape so customer-web can read the
 * locked-state and render the "Đã khoá tại quầy" copy without a side
 * fetch to `/kiosk/*`.
 *
 * Also asserts the new `is_counter_pay` flag used by customer-web to
 * conditionally render the "Chia hóa đơn?" card.
 */

use App\Http\Resources\CustomerOrderResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

function resourceFor(CustomerOrder $order): array
{
    return (new CustomerOrderResource($order))->toArray(Request::create('/'));
}

it('exposes split_mode and split_mode_locked', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'pending',
        'paid_amount' => 0,
        'split_mode' => 'by_people',
        'stripe_payment_intent_id' => null,
    ]);

    $payload = resourceFor($order);

    expect($payload)->toHaveKeys(['split_mode', 'split_mode_locked', 'is_counter_pay']);
    expect($payload['split_mode'])->toBe('by_people');
    expect($payload['split_mode_locked'])->toBeFalse();
});

it('marks split_mode_locked true when paid_amount > 0', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'pending',
        'paid_amount' => 12345.67,
        'split_mode' => 'by_items',
    ]);

    expect(resourceFor($order)['split_mode_locked'])->toBeTrue();
});

it('marks is_counter_pay true when no Stripe intent', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'stripe_payment_intent_id' => null,
    ]);

    expect(resourceFor($order)['is_counter_pay'])->toBeTrue();
});

it('marks is_counter_pay false when Stripe intent present', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'stripe_payment_intent_id' => 'pi_test_xyz',
    ]);

    expect(resourceFor($order)['is_counter_pay'])->toBeFalse();
});

it('exposes null split_mode when not yet set', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'split_mode' => null,
        'paid_amount' => 0,
    ]);

    $payload = resourceFor($order);

    expect($payload['split_mode'])->toBeNull();
    expect($payload['split_mode_locked'])->toBeFalse();
});

it('flips split_mode_locked true at the tiniest positive paid_amount', function () {
    // The lock is `(float) paid_amount > 0` — a sub-unit part payment (e.g.
    // a rounding remainder from a first split payer) must already lock the
    // mode, not wait for a whole currency unit.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'pending',
        'paid_amount' => 0.01,
        'split_mode' => 'by_people',
    ]);

    expect(resourceFor($order)['split_mode_locked'])->toBeTrue();
});

it('exposes null split_people_count when the count was never declared', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'split_mode' => 'by_people',
        'split_people_count' => null,
    ]);

    $payload = resourceFor($order);

    expect($payload)->toHaveKey('split_people_count');
    expect($payload['split_people_count'])->toBeNull();
});

it('exposes split_people_count', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'split_mode' => 'by_people',
        'split_people_count' => 6,
    ]);

    $payload = resourceFor($order);

    expect($payload)->toHaveKey('split_people_count');
    expect($payload['split_people_count'])->toBe(6);
});
