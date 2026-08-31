<?php

use App\Exceptions\OverpaymentRejectedException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\StripePaymentService;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * BLOCKER (MONEY) — overpayment race captures money but never refunds.
 *
 * When a full/split PaymentIntent races another payer and the per-order
 * overpayment guard refuses to ledger the (already-captured) slice, the
 * customer's money was stranded: the guard only Log::warning()'d and the
 * synchronous path threw "your charge will be refunded" — but NO code path
 * actually issued the refund.
 *
 * Fix under test: the rejection point (both the webhook markOrderPaidFromIntent
 * path and the synchronous confirmAndRecordPayment path) now AUTO-ISSUES a
 * Stripe refund of the captured intent, keyed idempotently on the intent id
 * ('refund_overpay_<intentId>'), gated by STRIPE_LIVE_REFUNDS_ENABLED.
 */
beforeEach(function () {
    config()->set('services.stripe.key', 'pk_test_dummy');
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');
    config()->set('services.stripe.currency', 'jpy');
    config()->set('payments.stripe_live_refunds_enabled', true);

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

function autoRefundSplitIntent(string $id, int $amount, CustomerOrder $order, Branch $branch, Organization $org): PaymentIntent
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

it('auto-refunds exactly once, with the stable intent-derived key, when a split webhook overpays', function () {
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->expects('create')
        ->once()
        ->withArgs(function (array $params, array $opts) {
            // JPY (zero-decimal): 5000 major → 5000 minor. Refund is against the
            // SECOND (rejected) intent, keyed stably off its id so a retried
            // webhook can't double-refund.
            return $params['payment_intent'] === 'pi_race_b'
                && $params['amount'] === 5000
                && $opts['idempotency_key'] === 'refund_overpay_pi_race_b';
        })
        ->andReturn(Refund::constructFrom(['id' => 're_overpay', 'object' => 'refund', 'amount' => 5000, 'status' => 'succeeded']));

    $service = new StripePaymentService($stripe);

    // First guest's slice settles the bill exactly — legitimate, NO refund.
    $service->markOrderPaidFromIntent(
        autoRefundSplitIntent('pi_race_a', 5000, $this->order, $this->branch, $this->organization)
    );

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(5000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);

    // Second guest's card also captured; the guard rejects it → auto-refund.
    $service->markOrderPaidFromIntent(
        autoRefundSplitIntent('pi_race_b', 5000, $this->order, $this->branch, $this->organization)
    );

    // Ledger integrity preserved: the rejected slice is never recorded.
    expect(OrderPayment::where('reference_no', 'pi_race_b')->count())->toBe(0);
    expect((float) $this->order->fresh()->paid_amount)->toBe(5000.0);
});

it('auto-refunds an overpaying full-flow webhook against the stored intent', function () {
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->expects('create')
        ->once()
        ->withArgs(fn (array $params, array $opts) => $params['payment_intent'] === 'pi_full_stale'
            && $params['amount'] === 5000
            && $opts['idempotency_key'] === 'refund_overpay_pi_full_stale')
        ->andReturn(Refund::constructFrom(['id' => 're_full', 'object' => 'refund', 'status' => 'succeeded']));

    $service = new StripePaymentService($stripe);

    // A split slice commits first (paid_amount = 3000).
    $service->markOrderPaidFromIntent(
        autoRefundSplitIntent('pi_split_first', 3000, $this->order, $this->branch, $this->organization)
    );

    // A stale "full" intent (charged the whole 5000) lands after → overpayment.
    $this->order->update(['stripe_payment_intent_id' => 'pi_full_stale']);
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => 'pi_full_stale',
        'object' => 'payment_intent',
        'amount' => 5000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [],
    ]));

    expect(OrderPayment::where('reference_no', 'pi_full_stale')->count())->toBe(0);
    expect((float) $this->order->fresh()->paid_amount)->toBe(3000.0);
});

it('auto-refunds on the synchronous confirm path AND still throws the refund-pending 409', function () {
    // First guest closed the bill via webhook (no refund on this legit slice).
    $webhookService = new StripePaymentService(Mockery::mock(StripeClient::class));
    $webhookService->markOrderPaidFromIntent(
        autoRefundSplitIntent('pi_first', 5000, $this->order, $this->branch, $this->organization)
    );

    $intentB = autoRefundSplitIntent('pi_second', 5000, $this->order, $this->branch, $this->organization);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')->with('pi_second')->once()->andReturn($intentB);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->expects('create')
        ->once()
        ->withArgs(fn (array $params, array $opts) => $params['payment_intent'] === 'pi_second'
            && $opts['idempotency_key'] === 'refund_overpay_pi_second')
        ->andReturn(Refund::constructFrom(['id' => 're_sync', 'object' => 'refund', 'status' => 'succeeded']));

    $service = new StripePaymentService($stripe);

    // The payer still gets a clear non-200 — but now their money is really back.
    expect(fn () => $service->confirmAndRecordPayment('pi_second'))
        ->toThrow(OverpaymentRejectedException::class);

    expect(OrderPayment::where('reference_no', 'pi_second')->count())->toBe(0);
});

it('does NOT auto-refund when STRIPE_LIVE_REFUNDS_ENABLED is off (kill-switch)', function () {
    config()->set('payments.stripe_live_refunds_enabled', false);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->shouldNotReceive('create');

    $service = new StripePaymentService($stripe);

    $service->markOrderPaidFromIntent(
        autoRefundSplitIntent('pi_kill_a', 5000, $this->order, $this->branch, $this->organization)
    );
    // Overpaying slice — rejected, but the kill-switch suppresses the refund.
    $service->markOrderPaidFromIntent(
        autoRefundSplitIntent('pi_kill_b', 5000, $this->order, $this->branch, $this->organization)
    );

    expect(OrderPayment::where('reference_no', 'pi_kill_b')->count())->toBe(0);
    expect((float) $this->order->fresh()->paid_amount)->toBe(5000.0);
});
