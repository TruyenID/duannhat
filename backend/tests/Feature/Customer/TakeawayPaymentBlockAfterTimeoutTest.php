<?php

/**
 * plan-031 — block payment once the takeaway payment window has elapsed.
 *
 * The auto-expire sweep (CancelOverdueTakeawayOrders) only runs every 60s, so
 * between the deadline passing and the next tick an overdue takeaway order can
 * still sit in `checkout`/`paying`. Without a deadline check on the payment
 * funnel, a cashier could collect on an order that should already be gone —
 * the exact "block payment after timeout" acceptance criterion the plan lists,
 * plus the race window flagged in the audit. The guard lives in
 * `OrderPaymentService::create` under the per-order `lockForUpdate`, so it is
 * race-safe against a concurrent expire().
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
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
    $this->method = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
        'is_active' => true,
    ]);
});

/** @param array<string, mixed> $overrides */
function blkOrder(object $ctx, array $overrides = []): CustomerOrder
{
    return CustomerOrder::create(array_merge([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'takeaway',
        'status' => 'paying',
        'subtotal' => 800, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 800, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
    ], $overrides));
}

function blkPay(object $ctx, CustomerOrder $order, float $amount = 800): OrderPayment
{
    return app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $ctx->method->id,
        'amount' => $amount,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $ctx->orgId,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
    ]);
}

it('rejects a payment on an overdue takeaway order still in paying (race window)', function () {
    // Deadline already passed but the sweep has not yet flipped it to expired.
    $order = blkOrder($this, ['status' => 'paying', 'payment_due_at' => now()->subMinute()]);

    try {
        blkPay($this, $order);
        $this->fail('Expected the overdue takeaway payment to be blocked.');
    } catch (HttpResponseException $e) {
        $resp = $e->getResponse();
        expect($resp->getStatusCode())->toBe(422);
        $body = json_decode($resp->getContent(), true);
        expect($body['code'])->toBe('takeaway_payment_window_elapsed');
    }
});

it('rejects a payment on an overdue takeaway order in checkout', function () {
    $order = blkOrder($this, ['status' => 'checkout', 'payment_due_at' => now()->subSeconds(1)]);

    try {
        blkPay($this, $order);
        $this->fail('Expected the overdue takeaway payment to be blocked.');
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(422)
            ->and(json_decode($e->getResponse()->getContent(), true)['code'])
            ->toBe('takeaway_payment_window_elapsed');
    }
});

it('allows a payment on a takeaway order whose window has NOT elapsed', function () {
    $order = blkOrder($this, ['status' => 'paying', 'payment_due_at' => now()->addMinutes(5)]);

    $payment = blkPay($this, $order);

    expect((float) $payment->amount)->toBe(800.0);
});

it('allows a payment on a takeaway order with no deadline (card / no countdown)', function () {
    $order = blkOrder($this, ['status' => 'paying', 'payment_due_at' => null]);

    $payment = blkPay($this, $order);

    expect((float) $payment->amount)->toBe(800.0);
});

it('does NOT block an overdue dine-in order (guard is takeaway-only)', function () {
    // payment_due_at is only ever stamped on takeaway orders, but a dine-in
    // row carrying one defensively must not trip the takeaway guard.
    $order = blkOrder($this, ['order_type' => 'dine_in', 'status' => 'paying', 'payment_due_at' => now()->subHour()]);

    $payment = blkPay($this, $order);

    expect((float) $payment->amount)->toBe(800.0);
});

it('blocks payment on an already-swept expired takeaway order with the timeout code', function () {
    // Post-sweep terminal state: the deadline guard fires before the generic
    // status guard, so the client gets the actionable timeout code (422) rather
    // than the opaque "must be checkout/paying" 409.
    $order = blkOrder($this, ['status' => 'expired', 'payment_due_at' => now()->subMinutes(20)]);

    try {
        blkPay($this, $order);
        $this->fail('Expected the expired takeaway payment to be blocked.');
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(422)
            ->and(json_decode($e->getResponse()->getContent(), true)['code'])
            ->toBe('takeaway_payment_window_elapsed');
    }
});
