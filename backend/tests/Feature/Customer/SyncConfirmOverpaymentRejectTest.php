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
use Stripe\StripeClient;

/**
 * plan-020 — MONEY / overpayment race, SYNCHRONOUS payer path.
 *
 * A guest confirms their own PaymentIntent via POST .../confirm-payment right
 * after Stripe.js finishes. The card has already `succeeded`, but a concurrent
 * full/split payment closed the bill in between. The overpayment guard refuses
 * to ledger this slice — the card was charged but nothing is credited.
 *
 * Regression: this MUST return a clear non-200 (409) to the payer and throw
 * OverpaymentRejectedException at the service layer, so the money is flagged
 * for refund reconciliation instead of a silent HTTP 200 that hides the stranded
 * charge. (The webhook path stays a silent 200 to Stripe — asserted elsewhere.)
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

function racedSplitIntent(string $id, int $amount, CustomerOrder $order, Branch $branch, Organization $org): PaymentIntent
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

it('throws OverpaymentRejectedException when a synchronously-confirmed charge is rejected', function () {
    // First guest already closed the bill (webhook path).
    $webhookService = new StripePaymentService(Mockery::mock(StripeClient::class));
    $webhookService->markOrderPaidFromIntent(
        racedSplitIntent('pi_first', 5000, $this->order, $this->branch, $this->organization)
    );

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(5000.0);

    // Second guest's card also succeeded; they confirm synchronously.
    $intentB = racedSplitIntent('pi_second', 5000, $this->order, $this->branch, $this->organization);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')->with('pi_second')->once()->andReturn($intentB);
    $service = new StripePaymentService($stripe);

    expect(fn () => $service->confirmAndRecordPayment('pi_second'))
        ->toThrow(OverpaymentRejectedException::class);

    // No row written for the stranded charge, ledger stays exactly at total.
    expect(OrderPayment::where('reference_no', 'pi_second')->count())->toBe(0);
    expect((float) $this->order->fresh()->paid_amount)->toBe(5000.0);
});

it('returns 409 (not a silent 200) from the confirm-payment endpoint on a rejected overpayment', function () {
    // Bill already fully paid by a racing guest.
    (new StripePaymentService(Mockery::mock(StripeClient::class)))->markOrderPaidFromIntent(
        racedSplitIntent('pi_a', 5000, $this->order, $this->branch, $this->organization)
    );

    $intentB = racedSplitIntent('pi_b', 5000, $this->order, $this->branch, $this->organization);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')->with('pi_b')->once()->andReturn($intentB);
    $this->app->instance(StripePaymentService::class, new StripePaymentService($stripe));

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_b',
    ]);

    $response->assertStatus(409);
    $response->assertJsonPath('error_code', 'overpayment_refund_pending');
    $response->assertJsonPath('meta.payment_intent_id', 'pi_b');

    expect(OrderPayment::where('reference_no', 'pi_b')->count())->toBe(0);
});

it('still returns 200 for a legitimate synchronous confirm (no false reject)', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_ok'])->save();

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_ok',
        'object' => 'payment_intent',
        'amount' => 5000,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')->with('pi_ok')->once()->andReturn($intent);
    $this->app->instance(StripePaymentService::class, new StripePaymentService($stripe));

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_ok',
    ])->assertOk()->assertJsonPath('data.is_fully_paid', true);

    expect(OrderPayment::where('reference_no', 'pi_ok')->count())->toBe(1);
});
