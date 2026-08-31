<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * #816 (adjacent) — the overpayment guard carried the SAME sign bug as the
 * void guard.
 *
 * `$existingPaidTotal` summed `succeeded` + live `pending`, omitting
 * `refunded`. A refund keeps the original's +X and flips it to `refunded`,
 * then adds a -X `succeeded` row — so counting only `succeeded` dropped the +X
 * and kept the -X, subtracting the refund TWICE:
 *
 *     800 order, 300 paid then fully refunded
 *     existingPaidTotal = -300      (should be 0)
 *     outstanding = 800 - (-300) = 1100   ← the order only ever owed 800
 *
 * The guard would then accept an 1100 payment on an 800 order.
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

    $this->order = CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'subtotal' => 800, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 800, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
});

function oarPay(float $amount): OrderPayment
{
    return app(OrderPaymentService::class)->create([
        'customer_order_id' => test()->order->id,
        'payment_method_id' => test()->method->id,
        'amount' => $amount,
        'tendered_amount' => $amount, // cash method requires_tendered
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
    ]);
}

it('does not inflate the outstanding balance after a refund', function () {
    // 300 collected, then refunded in full → the order is back to owing 800.
    $paid = oarPay(300);
    app(OrderPaymentService::class)->refund($paid, []);

    // Sanity: the ledger really is (+300 refunded) + (-300 succeeded).
    expect(OrderPayment::netCollectedForOrder($this->order->id))->toBe(0.0);

    // 1100 is what the BUGGY outstanding (800 - (-300)) would have permitted.
    try {
        oarPay(1100);
    } catch (HttpResponseException $e) {
        $resp = $e->getResponse();
        expect($resp->getStatusCode())->toBe(422);
        $body = json_decode($resp->getContent(), true);
        expect($body['code'])->toBe('overpayment_blocked')
            ->and($body['outstanding_amount'])->toBe('800.00');

        return;
    }

    $this->fail('Accepted 1100 on an 800 order — refund double-subtracted from outstanding (#816).');
});

it('still accepts the exact outstanding balance after a refund', function () {
    $paid = oarPay(300);
    app(OrderPaymentService::class)->refund($paid, []);

    // The order owes its full 800 again — paying exactly that must succeed.
    $settle = oarPay(800);

    expect((float) $settle->amount)->toBe(800.0)
        ->and($settle->status->value)->toBe(PaymentStatusEnum::Succeeded->value);
});
