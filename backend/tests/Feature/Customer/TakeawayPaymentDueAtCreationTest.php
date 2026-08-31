<?php

/**
 * plan-031 — order-creation write side (payment_due_at stamping).
 *
 * `CustomerOrderService::insertOrder` is the single funnel that stamps
 * `payment_due_at = now() + timeout` when a TAKEAWAY order is created with
 * COUNTER payment. Previously the whole plan-031 backend had zero coverage on
 * this write path — only the read-side resource countdown + the sweep job were
 * tested — so a regression that stopped stamping the deadline (which silently
 * disables the entire auto-expire feature, since the sweep only ever looks at
 * `whereNotNull('payment_due_at')`) would have shipped undetected.
 *
 * The timeout resolves shop-override first (branch), then brand default; a
 * missing/zero/non-positive value leaves the deadline unset. Dine-in, card
 * payment, and the `prep_before_payment=false` (status pre-set to `open`) path
 * must never carry a deadline.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

/** @param array<string, mixed> $overrides */
function makeOrderViaService(object $ctx, array $overrides = []): CustomerOrder
{
    return app(CustomerOrderService::class)->create(array_merge([
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
        'order_type' => 'takeaway',
        'payment_method' => 'counter',
    ], $overrides));
}

it('stamps payment_due_at from the shop-level override for a takeaway counter order', function () {
    $this->branch->update(['takeaway_payment_timeout_minutes' => 20]);
    $this->brand->update(['takeaway_payment_timeout_minutes' => 15]);

    $before = now();
    $order = makeOrderViaService($this);

    expect($order->payment_due_at)->not->toBeNull();
    // Shop override (20) wins over brand default (15). Allow a small slack for
    // the second that ticks during the transaction.
    $expected = $before->copy()->addMinutes(20);
    expect($order->payment_due_at->timestamp)
        ->toBeGreaterThanOrEqual($expected->timestamp - 2)
        ->toBeLessThanOrEqual($expected->copy()->addSeconds(2)->timestamp);
});

it('falls back to the brand default when the shop has no override', function () {
    $this->branch->update(['takeaway_payment_timeout_minutes' => null]);
    $this->brand->update(['takeaway_payment_timeout_minutes' => 15]);

    $before = now();
    $order = makeOrderViaService($this);

    $expected = $before->copy()->addMinutes(15);
    expect($order->payment_due_at)->not->toBeNull()
        ->and($order->payment_due_at->timestamp)
        ->toBeGreaterThanOrEqual($expected->timestamp - 2)
        ->toBeLessThanOrEqual($expected->copy()->addSeconds(2)->timestamp);
});

it('arms the countdown from the brand DB default (15) with no explicit config', function () {
    // The `brands.takeaway_payment_timeout_minutes` column defaults to 15, so
    // a takeaway counter order carries a deadline out of the box — this is the
    // plan's "default 15 minutes" behaviour. Pin it so a default change can't
    // silently disarm every new order.
    $before = now();
    $order = makeOrderViaService($this);

    $expected = $before->copy()->addMinutes(15);
    expect($order->payment_due_at)->not->toBeNull()
        ->and($order->payment_due_at->timestamp)
        ->toBeGreaterThanOrEqual($expected->timestamp - 2)
        ->toBeLessThanOrEqual($expected->copy()->addSeconds(2)->timestamp);
});

it('leaves payment_due_at null when both shop and brand timeouts are explicitly cleared', function () {
    $this->branch->update(['takeaway_payment_timeout_minutes' => null]);
    $this->brand->update(['takeaway_payment_timeout_minutes' => null]);

    $order = makeOrderViaService($this);

    expect($order->payment_due_at)->toBeNull();
});

it('leaves payment_due_at null when the configured timeout is zero', function () {
    $this->branch->update(['takeaway_payment_timeout_minutes' => 0]);
    $this->brand->update(['takeaway_payment_timeout_minutes' => 0]);

    $order = makeOrderViaService($this);

    expect($order->payment_due_at)->toBeNull();
});

it('never stamps payment_due_at on a dine-in order even with a timeout configured', function () {
    $this->branch->update(['takeaway_payment_timeout_minutes' => 20]);

    $order = makeOrderViaService($this, ['order_type' => 'dine_in']);

    expect($order->payment_due_at)->toBeNull();
});

it('never stamps payment_due_at on a takeaway card-payment order', function () {
    $this->branch->update(['takeaway_payment_timeout_minutes' => 20]);

    $order = makeOrderViaService($this, ['payment_method' => 'card']);

    expect($order->payment_due_at)->toBeNull();
});

it('never stamps payment_due_at when the order is created straight into open (prep-before-payment=false)', function () {
    // plan-035: shop policy `prep_before_payment=false` pre-sets status=open —
    // the kitchen already started cooking, so there is no expire-if-not-paid
    // semantic and no countdown should be armed.
    $this->branch->update(['takeaway_payment_timeout_minutes' => 20]);

    $order = makeOrderViaService($this, ['status' => CustomerOrderStatusEnum::Open->value]);

    expect($order->payment_due_at)->toBeNull()
        ->and($order->status)->toBe(CustomerOrderStatusEnum::Open);
});
