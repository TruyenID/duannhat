<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Services\Order\Coupon\OrderCouponService;
use Illuminate\Support\Str;

/*
 * #555 M14 — CouponService::recalculateOrderTotal re-sums total from the raw
 * columns. A fixed-amount coupon larger than the subtotal must not persist a
 * NEGATIVE total_amount (which poisons pre-checkout reads and HQ revenue
 * aggregation). The formula is now clamped to 0.
 */

it('clamps total_amount to zero when discount exceeds subtotal', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    // Subtotal 3,000 but a 5,000 fixed-amount coupon already stamped → the
    // naive re-sum would be 3,000 − 5,000 = −2,000.
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-C'.random_int(1000, 9999),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 3_000,
        'discount_amount' => 5_000,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 3_000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
    ]);

    $service = app(OrderCouponService::class);
    $method = new ReflectionMethod($service, 'recalculateOrderTotal');
    $method->setAccessible(true);
    $method->invoke($service, $order);

    expect((float) $order->fresh()->total_amount)->toBe(0.0);
});
