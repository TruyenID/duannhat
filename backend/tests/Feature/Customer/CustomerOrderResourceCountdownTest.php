<?php

/**
 * plan-031 — `CustomerOrderResource::seconds_until_due` regression.
 *
 * The countdown value is a signed Carbon diff FROM now TO `payment_due_at`,
 * clamped at 0. A LIVE order (deadline in the future) must report the seconds
 * REMAINING (> 0); an already-overdue order must clamp to 0. The previous
 * implementation had the diff operands reversed, so it returned 0 for every
 * live countdown and a positive value for overdue orders — the exact inverse
 * of the customer-facing intent.
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

function countdownResourceFor(CustomerOrder $order): array
{
    return (new CustomerOrderResource($order))->toArray(Request::create('/'));
}

it('reports positive seconds_until_due for a live countdown order', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'order_type' => 'takeaway',
        'status' => 'confirmed',
        'payment_due_at' => now()->addMinutes(10),
    ]);

    $payload = countdownResourceFor($order);

    // ~600s remaining; allow slack for the second that ticks during the call.
    expect($payload['seconds_until_due'])->toBeGreaterThan(0)
        ->and($payload['seconds_until_due'])->toBeGreaterThanOrEqual(595)
        ->and($payload['seconds_until_due'])->toBeLessThanOrEqual(600)
        ->and($payload['is_payment_overdue'])->toBeFalse();
});

it('clamps seconds_until_due to 0 for an already-overdue order', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'order_type' => 'takeaway',
        'status' => 'confirmed',
        'payment_due_at' => now()->subMinutes(3),
    ]);

    $payload = countdownResourceFor($order);

    expect($payload['seconds_until_due'])->toBe(0)
        ->and($payload['is_payment_overdue'])->toBeTrue();
});

it('reports null seconds_until_due when no payment_due_at is set', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'payment_due_at' => null,
    ]);

    expect(countdownResourceFor($order)['seconds_until_due'])->toBeNull();
});
