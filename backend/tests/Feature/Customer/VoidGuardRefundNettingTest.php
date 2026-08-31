<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * #816 — the #547 collected-payment guard summed only `succeeded` rows.
 *
 * A refund flips the ORIGINAL to `refunded` and inserts a NEGATIVE `succeeded`
 * row, so a refunded payment contributed `-amount` instead of 0. On a split
 * bill that negative cancelled a co-diner's real cash and the void slipped
 * through, orphaning the cash in the drawer.
 *
 * Every case below drives the real OrderPaymentService::refund() so the ledger
 * shape is the one production actually produces, not one the test invents.
 */
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
    $this->method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function vgnOrder(float $total = 1500): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'subtotal' => $total, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => $total, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function vgnCash(CustomerOrder $order, float $amount): OrderPayment
{
    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->method->id,
        'amount' => $amount,
        'status' => 'succeeded',
        'paid_at' => now(),
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
    ]);
}

function vgnVoidStatus(CustomerOrder $order): int
{
    try {
        app(CustomerOrderService::class)->voidOrder($order, ['void_reason' => 'test']);

        return 200;
    } catch (HttpResponseException $e) {
        return $e->getResponse()->getStatusCode();
    }
}

it('blocks the void when a co-diner cash payment survives the refund of another', function () {
    $order = vgnOrder(1500);
    $paidA = vgnCash($order, 500);
    vgnCash($order, 500); // diner B — this cash stays in the drawer

    app(OrderPaymentService::class)->refund($paidA, ['amount' => 500]);

    expect(vgnVoidStatus($order))->toBe(409)
        ->and($order->fresh()->status->value)->toBe('paying');
});

it('blocks the void when only part of a payment was refunded', function () {
    $order = vgnOrder(1500);
    $paid = vgnCash($order, 1000);

    app(OrderPaymentService::class)->refund($paid, ['amount' => 1]); // 999 still held

    expect(vgnVoidStatus($order))->toBe(409)
        ->and($order->fresh()->status->value)->toBe('paying');
});

it('still allows the void once every payment is fully refunded (nets to 0)', function () {
    $order = vgnOrder(1500);
    $paidA = vgnCash($order, 500);
    $paidB = vgnCash($order, 500);

    app(OrderPaymentService::class)->refund($paidA, ['amount' => 500]);
    app(OrderPaymentService::class)->refund($paidB, ['amount' => 500]);

    expect(vgnVoidStatus($order))->toBe(200)
        ->and($order->fresh()->status->value)->toBe('voided');
});
