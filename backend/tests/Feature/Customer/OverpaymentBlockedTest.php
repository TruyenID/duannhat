<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * #417 Tầng 3 — the overpayment guard used to abort with an opaque generic
 * 422 ("Payment amount exceeds the outstanding order balance") that gave staff
 * no clue why. It now returns a structured `overpayment_blocked` code carrying
 * the outstanding balance, the given amount, and any live pending hold so the
 * FE can render an actionable message.
 *
 * Tầng 1 (expired stuck pending excluded from the reserve) is exercised too as
 * a regression guard — a crashed cash session must never permanently block the
 * order's remaining payments.
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
    // Plain method: not auto-confirm, no tendered — keeps the create() path
    // focused on the overpayment guard.
    $this->method = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_auto_confirm' => false,
        'requires_tendered' => false,
        'is_active' => true,
    ]);
});

function obOrder(float $total = 800): CustomerOrder
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

function obSeedPayment(CustomerOrder $order, float $amount, string $status, ?DateTimeInterface $expiresAt = null): OrderPayment
{
    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->method->id,
        'amount' => $amount,
        'status' => $status,
        'expires_at' => $expiresAt,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
    ]);
}

function obCreatePayment(CustomerOrder $order, float $amount): OrderPayment
{
    return app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->method->id,
        'amount' => $amount,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
    ]);
}

it('returns a structured overpayment_blocked code with exact balances', function () {
    $order = obOrder(800);
    obSeedPayment($order, 500, 'succeeded'); // outstanding = 300

    try {
        obCreatePayment($order, 500); // overpay by 200
        $this->fail('Expected the overpayment to be blocked.');
    } catch (HttpResponseException $e) {
        $resp = $e->getResponse();
        expect($resp->getStatusCode())->toBe(422);
        $body = json_decode($resp->getContent(), true);
        expect($body['code'])->toBe('overpayment_blocked')
            ->and($body['outstanding_amount'])->toBe('300.00')
            ->and($body['given_amount'])->toBe('500.00')
            ->and($body['pending_hold_amount'])->toBe('0.00');
    }
});

it('reports the live pending hold that is reserving part of the balance', function () {
    $order = obOrder(800);
    obSeedPayment($order, 500, 'succeeded');                    // outstanding base
    obSeedPayment($order, 100, 'pending', now()->addMinutes(10)); // live hold → reserves 100

    try {
        obCreatePayment($order, 500); // outstanding now 200 → overpay 300
        $this->fail('Expected the overpayment to be blocked.');
    } catch (HttpResponseException $e) {
        $body = json_decode($e->getResponse()->getContent(), true);
        expect($body['code'])->toBe('overpayment_blocked')
            ->and($body['outstanding_amount'])->toBe('200.00')
            ->and($body['pending_hold_amount'])->toBe('100.00');
    }
});

it('Tầng 1 — an expired stuck pending never blocks the remaining payment', function () {
    $order = obOrder(800);
    obSeedPayment($order, 500, 'succeeded');                       // real money
    obSeedPayment($order, 300, 'pending', now()->subHours(7));     // stuck, expired

    // Outstanding = 800 - 500 = 300 (expired pending excluded). Paying the
    // exact remaining must succeed instead of 422'ing.
    $payment = obCreatePayment($order, 300);

    expect((float) $payment->amount)->toBe(300.0)
        ->and($payment->status->value)->toBe('pending'); // non-auto-confirm method
});
