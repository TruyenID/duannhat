<?php

/**
 * plan-054 M4 — the single funnel every PayPay QR payment lands through.
 *
 * Modelled on markOrderPaidFromIntent rather than recordStripeWebhookPayment:
 * that method is a bare SELECT-then-INSERT, safe only because every caller
 * already holds the order row lock. This one has two callers in different
 * processes (the provider-event worker and the customer's status poll), so each
 * guard below is exercised on its own.
 *
 * Money that a guard refuses is never quietly kept: the amount comes back to the
 * caller so it can be returned once out of the lock.
 */

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('payment');

function plan054Order(array $overrides = []): CustomerOrder
{
    $branch = Branch::factory()->create(['currency' => 'JPY']);

    $order = CustomerOrder::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ], $overrides));

    // The currency guard reads the order's priced currency the same way the
    // Stripe funnel does, so the branch needs the setting rather than just the
    // branches.currency column.
    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $order->organization_id,
        'currency_code' => 'JPY',
    ]);

    return $order;
}

function plan054Record(CustomerOrder $order, string $mpid = 'tempoqr-abc', float $amount = 3000, string $currency = 'JPY'): array
{
    return app(OrderPaymentService::class)->recordPayPayPaymentByOrderId((string) $order->id, $mpid, $amount, $currency);
}

it('records the money PayPay says it took and settles the order', function () {
    $order = plan054Order();

    $outcome = plan054Record($order);

    expect($outcome['recorded'])->toBeTrue()
        ->and($outcome['stranded_amount'])->toBeNull();

    $row = OrderPayment::query()->where('customer_order_id', $order->id)->sole();

    expect((float) $row->amount)->toBe(3000.0)
        ->and($row->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and($row->reference_no)->toBe('tempoqr-abc')
        // The DB backstop: order_payments is unique on
        // (customer_order_id, idempotency_key) and nothing enforces uniqueness
        // on reference_no, so this stamping is what makes a race fail loudly.
        ->and($row->idempotency_key)->toBe('tempoqr-abc')
        // Server-owned column — also what keeps this money out of the cashier's
        // shift reconciliation, since no drawer collected it.
        ->and($row->channel)->toBe(PaymentChannelEnum::CustomerWeb->value);

    expect((float) $order->fresh()->paid_amount)->toBe(3000.0);
});

it('is idempotent when the webhook and the poll both report the same payment', function () {
    $order = plan054Order();

    $first = plan054Record($order);
    $second = plan054Record($order);

    expect($first['recorded'])->toBeTrue()
        ->and($second['recorded'])->toBeFalse()
        ->and($second['reason'])->toBe('already_recorded')
        // Nothing to hand back — the money IS on the books, just not twice.
        ->and($second['stranded_amount'])->toBeNull();

    expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1);
});

it('refuses money for an order that was voided while its QR was still live', function () {
    // A QR lives ~5 minutes and the order can die inside that window. The Stripe
    // funnel needs no equivalent check because its expiry sweep cancels the
    // intent at the provider first; nothing does that for a QR.
    $order = plan054Order(['status' => CustomerOrderStatusEnum::Voided->value]);

    $outcome = plan054Record($order);

    expect($outcome['recorded'])->toBeFalse()
        ->and($outcome['reason'])->toBe('order_not_payable')
        ->and($outcome['stranded_amount'])->toBe(3000.0);

    expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(0);
});

it('refuses a charge in a currency the order was not priced in', function () {
    $order = plan054Order();

    $outcome = plan054Record($order, currency: 'VND');

    expect($outcome['recorded'])->toBeFalse()
        ->and($outcome['reason'])->toBe('currency_mismatch')
        ->and($outcome['stranded_amount'])->toBe(3000.0);

    expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(0);
});

it('refuses money that would push the order past its total', function () {
    // The customer paid at the counter while their QR was still live. Recording
    // this would hide a real overpayment behind a clamped paid_amount.
    $order = plan054Order();
    plan054Record($order, mpid: 'tempoqr-counter', amount: 3000);

    $outcome = plan054Record($order->fresh(), mpid: 'tempoqr-scanned', amount: 3000);

    expect($outcome['recorded'])->toBeFalse()
        ->and($outcome['reason'])->toBe('overpayment')
        ->and($outcome['stranded_amount'])->toBe(3000.0);

    expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->fresh()->paid_amount)->toBe(3000.0);
});

it('accepts the remaining slice of a partly paid order', function () {
    $order = plan054Order(['total_amount' => 5000]);
    plan054Record($order, mpid: 'tempoqr-first', amount: 2000);

    $outcome = plan054Record($order->fresh(), mpid: 'tempoqr-second', amount: 3000);

    expect($outcome['recorded'])->toBeTrue()
        ->and((float) $order->fresh()->paid_amount)->toBe(5000.0);
});

it('guards in an order that blames the right thing', function () {
    // A voided order in the wrong currency should report the order, not the
    // currency: the order state is the fact the operator has to act on.
    $order = plan054Order(['status' => CustomerOrderStatusEnum::Voided->value]);

    expect(plan054Record($order, currency: 'VND')['reason'])->toBe('order_not_payable');
});

it('refuses to refund a PayPay payment rather than faking the reversal', function () {
    // Without this guard the refund falls through to the ledger-only branch: the
    // original flips to refunded, a negative row is written, paid_amount drops,
    // the invoice is voided and a 適格返還請求書 is issued — for money that never
    // left PayPay. It would also bypass the live-refund kill switch and the
    // per-refund cap, both of which live inside the Stripe branch.
    $order = plan054Order();
    plan054Record($order);

    $payment = OrderPayment::query()->where('customer_order_id', $order->id)->sole();

    expect(fn () => app(OrderPaymentService::class)->refund($payment, ['amount' => 3000]))
        ->toThrow(HttpException::class);

    expect($payment->fresh()->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->fresh()->paid_amount)->toBe(3000.0);
});
