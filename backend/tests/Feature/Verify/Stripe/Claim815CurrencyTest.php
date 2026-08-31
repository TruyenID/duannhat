<?php

/**
 * CLAIM #815 — StripePaymentService takes the charge currency from a GLOBAL
 * env (`config('services.stripe.currency', 'jpy')`) at every intent-creation
 * site and never reads the branch's `shop_order_settings.currency_code`.
 *
 * Drives the REAL HTTP routes (POST /orders/{id}/full-payment-intent,
 * POST /orders/{id}/split-payment-intent, POST /stripe/webhook) with only the
 * Stripe SDK client mocked. Every assertion below is on the ACTUAL params the
 * production code hands to Stripe.
 */

require_once __DIR__.'/vst_helpers.php';

use App\Models\OrderPayment;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Mockery\MockInterface;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

beforeEach(function () {
    // The GLOBAL env default the platform ships with.
    vstConfigureStripe(currency: 'jpy');
});

/**
 * Mock the SDK client and CAPTURE the create params.
 */
function vst815CaptureClient(array &$captured, string $intentId = 'pi_vst815'): StripeClient
{
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();

    $client->paymentIntents
        ->shouldReceive('create')
        ->andReturnUsing(function (array $params) use (&$captured, $intentId) {
            $captured = $params;

            return PaymentIntent::constructFrom(vstIntentObject(
                $intentId,
                (int) $params['amount'],
                (string) $params['currency'],
                'requires_payment_method',
                (array) ($params['metadata'] ?? []),
            ));
        });

    return $client;
}

// =============================================================================
// (i) VND branch, order total 250,000
// =============================================================================

it('#815(i) charges a VND 250,000 branch order in VND and closes it as fully paid', function () {
    $tenant = vstTenant(branchCurrency: 'VND');
    $order = vstOrder($tenant, total: 250000);

    expect($order->branch->orderSetting?->currency_code ?? 'VND')->toBe('VND');

    $captured = [];
    vstBindStripe(vst815CaptureClient($captured, 'pi_vnd_250k'));

    // --- REAL route: customer taps "pay full" ---------------------------------
    $this->postJson("/api/v1/customer/orders/{$order->id}/full-payment-intent")
        ->assertOk();

    // RESOLVED (#821 #815): the charge currency is the branch's VND, not the
    // global-env JPY. VND is zero-decimal, so the minor amount equals the major.
    expect($captured['currency'])->toBe('vnd')
        ->and($captured['amount'])->toBe(250000);

    // --- REAL route: Stripe fires payment_intent.succeeded --------------------
    $event = vstSignedEvent('payment_intent.succeeded', vstIntentObject(
        'pi_vnd_250k', 250000, 'vnd', 'succeeded',
        ['order_id' => $order->id, 'flow' => 'full'],
    ));

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    $order->refresh();

    // NO currency mismatch was ever detected: the order closes as fully paid.
    expect($order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value)
        ->and((float) $order->paid_amount)->toBe(250000.0);

    $payment = OrderPayment::where('customer_order_id', $order->id)->first();
    expect($payment)->not->toBeNull()
        ->and((float) $payment->amount)->toBe(250000.0);
});

// =============================================================================
// (ii) USD branch, order total 25.00 (whole number — dodges the overpay epsilon)
// =============================================================================

it('#815(ii) charges a USD 25.00 branch order in USD cents and closes it as fully paid', function () {
    $tenant = vstTenant(branchCurrency: 'USD');
    $order = vstOrder($tenant, total: 25.00);

    $captured = [];
    vstBindStripe(vst815CaptureClient($captured, 'pi_usd_25'));

    $this->postJson("/api/v1/customer/orders/{$order->id}/full-payment-intent")
        ->assertOk();

    // RESOLVED (#821 #815): USD is charged in cents — $25.00 → 2500 — from the
    // branch currency, not the zero-decimal JPY that undercharged to ¥25 ≈ $0.16.
    expect($captured['currency'])->toBe('usd')
        ->and($captured['amount'])->toBe(2500);

    $event = vstSignedEvent('payment_intent.succeeded', vstIntentObject(
        'pi_usd_25', 2500, 'usd', 'succeeded',
        ['order_id' => $order->id, 'flow' => 'full'],
    ));

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    $order->refresh();

    // The full $25.00 was charged and collected, so the order closes paid in full.
    expect($order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value)
        ->and((float) $order->paid_amount)->toBe(25.0);
});

// =============================================================================
// The split-intent site has the same global-env read
// =============================================================================

it('#815 the split-payment-intent site charges the branch currency too', function () {
    $tenant = vstTenant(branchCurrency: 'VND');
    $order = vstOrder($tenant, total: 100000);

    $captured = [];
    vstBindStripe(vst815CaptureClient($captured, 'pi_split_vnd'));

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-payment-intent", [
        'amount' => 50000,
    ])->assertOk();

    // RESOLVED (#821 #815): the split intent is charged in the branch's VND.
    expect($captured['currency'])->toBe('vnd')
        ->and($captured['amount'])->toBe(50000);
});

// =============================================================================
// Flip the GLOBAL env → every branch follows it, regardless of its own setting
// =============================================================================

it('#815 the GLOBAL env never overrides a branch that has its own currency', function () {
    // A JP restaurant. Operator sets STRIPE_CURRENCY=usd for a US branch —
    // the JP branch is dragged along.
    config(['services.stripe.currency' => 'usd']);

    $tenant = vstTenant(branchCurrency: 'JPY');
    $order = vstOrder($tenant, total: 1500); // ¥1,500

    $captured = [];
    vstBindStripe(vst815CaptureClient($captured, 'pi_jpy_as_usd'));

    $this->postJson("/api/v1/customer/orders/{$order->id}/full-payment-intent")
        ->assertOk();

    // RESOLVED (#821 #815): the JP branch carries currency JPY, so it is charged
    // ¥1,500 as jpy/1500 — the global STRIPE_CURRENCY=usd is only a fallback for
    // a branch with NO currency, never an override.
    expect($captured['currency'])->toBe('jpy')
        ->and($captured['amount'])->toBe(1500);
});
