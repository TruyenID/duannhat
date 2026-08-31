<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\StripePaymentService;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * plan-020 — MONEY / overpayment race regression.
 *
 * Two guests scan the same QR and each create a split PaymentIntent for the
 * FULL remaining balance before either confirms. The intent-creation guard in
 * CustomerOrderController only validates `amount ≤ total − paid_amount`, and an
 * online split intent moves nothing into paid_amount until it confirms — so
 * both intents pass creation-time validation against the same paid_amount, both
 * cards charge, and both webhooks land here. This exercises the exact interleave
 * outcome: the committed first slice, then the second slice arriving.
 *
 * Invariant under test: the second (over-total) slice is REJECTED — never
 * ledgered, paid_amount never exceeds total_amount.
 */
beforeEach(function () {
    config()->set('services.stripe.key', 'pk_test_dummy');
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');
    config()->set('services.stripe.currency', 'jpy');

    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'total_amount' => 5000,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => null,
    ]);
});

function splitIntent(string $id, int $amount, CustomerOrder $order, Branch $branch, Organization $org): PaymentIntent
{
    return PaymentIntent::constructFrom([
        'id' => $id,
        'object' => 'payment_intent',
        'amount' => $amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'branch_id' => $branch->id,
            'organization_id' => $org->id,
        ],
    ]);
}

it('rejects the second concurrent full-remaining split slice (overpayment race)', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // Both guests independently created an intent for the full ¥5,000 remaining
    // — the classic interleave (each validated against paid_amount = 0).
    $intentA = splitIntent('pi_race_a', 5000, $this->order, $this->branch, $this->organization);
    $intentB = splitIntent('pi_race_b', 5000, $this->order, $this->branch, $this->organization);

    // First webhook commits: order settles exactly.
    $service->markOrderPaidFromIntent($intentA);

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(5000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);

    // Second webhook serialises behind the lock, re-sums the ledger, and is
    // rejected — no row written, paid_amount stays exactly at total.
    $service->markOrderPaidFromIntent($intentB);

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(5000.0);
    expect(
        OrderPayment::where('customer_order_id', $this->order->id)
            ->where('reference_no', 'pi_race_b')
            ->count()
    )->toBe(0);

    // Exactly one payment row, exactly total — no overpayment.
    $ledger = (float) OrderPayment::where('customer_order_id', $this->order->id)
        ->whereIn('status', [PaymentStatusEnum::Succeeded->value, PaymentStatusEnum::Refunded->value])
        ->sum('amount');
    expect($ledger)->toBe(5000.0);
    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(1);
});

it('rejects a partial second slice that would jointly exceed the total', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // Guest A pays ¥3,000; guest B — racing on the same stale remaining —
    // pays ¥3,000 too. 3000 + 3000 = 6000 > 5000 total.
    $service->markOrderPaidFromIntent(splitIntent('pi_partial_a', 3000, $this->order, $this->branch, $this->organization));
    $service->markOrderPaidFromIntent(splitIntent('pi_partial_b', 3000, $this->order, $this->branch, $this->organization));

    $this->order->refresh();

    // Only the first ¥3,000 is ledgered; the over-total slice is rejected.
    expect((float) $this->order->paid_amount)->toBe(3000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
    expect(OrderPayment::where('reference_no', 'pi_partial_b')->count())->toBe(0);
    expect((float) $this->order->total_amount - (float) $this->order->paid_amount)->toBe(2000.0);
});

it('still accepts a second slice that exactly settles the remaining balance', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // Legitimate split: ¥2,000 then ¥3,000 = ¥5,000 total. The guard must not
    // false-reject the closing slice that lands exactly on the total.
    $service->markOrderPaidFromIntent(splitIntent('pi_ok_a', 2000, $this->order, $this->branch, $this->organization));
    $service->markOrderPaidFromIntent(splitIntent('pi_ok_b', 3000, $this->order, $this->branch, $this->organization));

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(5000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(2);
});

it('does not treat a replayed webhook as an overpayment', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // A closing slice, then a duplicate delivery of the SAME intent. The
    // idempotency check must win over the overpayment guard — the replay is a
    // no-op, not a rejection that would depend on ordering.
    $intent = splitIntent('pi_replay', 5000, $this->order, $this->branch, $this->organization);

    $service->markOrderPaidFromIntent($intent);
    $service->markOrderPaidFromIntent($intent); // replay of the exact same intent

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(5000.0);
    expect(OrderPayment::where('reference_no', 'pi_replay')->count())->toBe(1);
});
