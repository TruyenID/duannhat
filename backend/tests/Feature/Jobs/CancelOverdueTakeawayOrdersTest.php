<?php

use App\Events\OrderVoided;
use App\Jobs\CancelOverdueTakeawayOrders;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Illuminate\Support\Facades\Event;

/**
 * issue #512 — CancelOverdueTakeawayOrders regression.
 *
 * The job previously referenced a non-existent `Order` model plus dropped
 * columns and an invalid status, so it fataled every run and overdue takeaway
 * orders stayed stuck. These cover the corrected discriminator + terminal
 * `expired` outcome.
 */
it('expires an overdue takeaway order and stamps auto_cancelled_at', function () {
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Confirmed->value,
        'payment_due_at' => now()->subMinutes(5),
        'auto_cancelled_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    $order->refresh();
    expect($order->status)->toBe(CustomerOrderStatusEnum::Expired)
        ->and($order->auto_cancelled_at)->not->toBeNull();
});

it('expires overdue takeaway orders in every not-yet-paid status', function (string $status) {
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => $status,
        'payment_due_at' => now()->subMinute(),
        'auto_cancelled_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    expect($order->refresh()->status)->toBe(CustomerOrderStatusEnum::Expired);
})->with(['pending', 'awaiting_confirmation', 'confirmed']);

it('leaves a takeaway order whose payment window has not elapsed', function () {
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Confirmed->value,
        'payment_due_at' => now()->addMinutes(10),
        'auto_cancelled_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    expect($order->refresh()->status)->toBe(CustomerOrderStatusEnum::Confirmed)
        ->and($order->auto_cancelled_at)->toBeNull();
});

it('never touches a dine-in order even when overdue', function () {
    $order = CustomerOrder::factory()->create([
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Confirmed->value,
        'payment_due_at' => now()->subHour(),
        'auto_cancelled_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    expect($order->refresh()->status)->toBe(CustomerOrderStatusEnum::Confirmed);
});

it('never touches an already-paying or closed takeaway order', function (string $status) {
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => $status,
        'payment_due_at' => now()->subHour(),
        'auto_cancelled_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    expect($order->refresh()->status->value)->toBe($status);
})->with(['paying', 'closed']);

it('does not re-sweep an order already stamped auto_cancelled_at', function () {
    $stampedAt = now()->subMinutes(30);
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Expired->value,
        'payment_due_at' => now()->subHour(),
        'auto_cancelled_at' => $stampedAt,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    $order->refresh();
    expect($order->auto_cancelled_at->timestamp)->toBe($stampedAt->timestamp);
});

/**
 * plan-audit critical — the sweep must route through the full void-style
 * teardown, not a raw status flip. These pin the teardown side-effects that
 * the previous raw `update()` silently skipped: item void, coupon release,
 * domain event, and audit trail.
 */
it('voids every non-voided item on the expired order', function () {
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Confirmed->value,
        'payment_due_at' => now()->subMinutes(5),
        'auto_cancelled_at' => null,
    ]);
    $live = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
        'voided_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    $live->refresh();
    expect($order->refresh()->status)->toBe(CustomerOrderStatusEnum::Expired)
        ->and($live->status->value ?? $live->status)->toBe('voided')
        ->and($live->voided_at)->not->toBeNull();
});

it('releases an applied coupon when expiring the order', function () {
    $coupon = Coupon::factory()->create(['times_used' => 1]);
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Confirmed->value,
        'payment_due_at' => now()->subMinutes(5),
        'auto_cancelled_at' => null,
        'coupon_id' => $coupon->id,
        'discount_amount' => 500,
    ]);
    $redemption = CouponRedemption::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_order_id' => $order->id,
        'released_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    expect($order->refresh()->coupon_id)->toBeNull()
        ->and($redemption->refresh()->released_at)->not->toBeNull()
        ->and($coupon->refresh()->times_used)->toBe(0);
});

it('dispatches an OrderVoided event for each expired order', function () {
    Event::fake([OrderVoided::class]);

    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Confirmed->value,
        'payment_due_at' => now()->subMinutes(5),
        'auto_cancelled_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    Event::assertDispatched(OrderVoided::class, fn (OrderVoided $e) => $e->order->id === $order->id);
});

it('writes an expired audit log entry', function () {
    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Confirmed->value,
        'payment_due_at' => now()->subMinutes(5),
        'auto_cancelled_at' => null,
    ]);

    (new CancelOverdueTakeawayOrders)->handle();

    expect(AuditLog::where('auditable_id', $order->id)->where('action', 'expired')->exists())->toBeTrue();
});
