<?php

/**
 * CLAIM #548 — Card/Stripe refund not wired end-to-end.
 *   (a) nothing calls the Stripe Refunds API → in-app refund only marks the
 *       ledger, the customer never gets money back.
 *   (b) the webhook does not handle `charge.refunded` → a dashboard refund
 *       never reaches the books.
 *
 * Drives the REAL OrderPaymentService::refund() (the exact method
 * Shop/OrderPaymentController@refund calls, POST
 * /shops/.../payments/{payment}/refund) and the REAL webhook HTTP route.
 * Only \Stripe\StripeClient is mocked.
 */

require_once __DIR__.'/vst_helpers.php';

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use Mockery\MockInterface;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    vstConfigureStripe(currency: 'jpy');

    $this->tenant = vstTenant();
    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->tenant['org_id'],
        'brand_id' => $this->tenant['brand']->id,
        'branch_id' => $this->tenant['branch']->id,
        'total_amount' => 1500,
        'paid_amount' => 1500,
        'status' => 'closed',
    ]);

    $this->stripeMethod = PaymentMethod::factory()->create([
        'code' => 'stripe',
        'organization_id' => $this->tenant['org_id'],
        'branch_id' => null,
    ]);

    // A succeeded Stripe card payment — shaped exactly as
    // StripePaymentService::recordStripePayment() stamps it (pi_ reference).
    $this->payment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->stripeMethod->id,
        'branch_id' => $this->tenant['branch']->id,
        'brand_id' => $this->tenant['brand']->id,
        'organization_id' => $this->tenant['org_id'],
        'amount' => 1500,
        'reference_no' => 'pi_vst548',
    ]);
});

// =============================================================================
// HALF (a) — does an in-app refund call the Stripe Refunds API?
// =============================================================================

it('#548(a) DEFAULT CONFIG: payments.stripe_live_refunds_enabled ships as FALSE', function () {
    // No override — this is the value a fresh deploy runs with.
    // (config/payments.php: env('STRIPE_LIVE_REFUNDS_ENABLED', false))
    $this->refreshApplication();

    expect(config('payments.stripe_live_refunds_enabled'))->toBeFalse();
});

it('#548(a) with the kill-switch ON, an in-app refund DOES call the Stripe Refunds API and stamps the refund id', function () {
    config(['payments.stripe_live_refunds_enabled' => true]);

    $capturedParams = [];
    $capturedOpts = [];

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    // StripeClient exposes ->refunds through __get() -> getService(), so a plain
    // property assignment on the mock is bypassed. Stub getService instead.
    $refunds = Mockery::mock();
    $client->shouldReceive('getService')->with('refunds')->andReturn($refunds);
    // refundPayment() also reads the intent to resolve the charge currency.
    $intents = Mockery::mock();
    $intents->shouldReceive('retrieve')->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_vst_548', 'object' => 'payment_intent', 'currency' => 'jpy'])
    );
    $client->shouldReceive('getService')->with('paymentIntents')->andReturn($intents);
    $refunds->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (array $params, array $opts) use (&$capturedParams, &$capturedOpts) {
            $capturedParams = $params;
            $capturedOpts = $opts;

            return Refund::constructFrom([
                'id' => 're_vst_548',
                'object' => 'refund',
                'amount' => $params['amount'],
                'status' => 'succeeded',
            ]);
        });

    vstBindStripe($client);

    // REAL production method (Shop/OrderPaymentController@refund line 211).
    $refundRow = app(OrderPaymentService::class)->refund($this->payment, ['amount' => 1500]);

    // The Stripe network call really happened, with exact minor units (JPY = zero-decimal).
    expect($capturedParams['payment_intent'])->toBe('pi_vst548')
        ->and($capturedParams['amount'])->toBe(1500)
        ->and($capturedOpts['idempotency_key'])->toBe('refund_refund_'.$this->payment->id);

    // ...and the ledger reflects it.
    expect((float) $refundRow->amount)->toBe(-1500.0)
        ->and($refundRow->metadata['stripe_refund_id'])->toBe('re_vst_548')
        ->and($this->payment->fresh()->status)->toBe(PaymentStatusEnum::Refunded);

    expect((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('#548(a) with the kill-switch OFF (default), the refund is REFUSED — no Stripe call AND no phantom ledger row', function () {
    config(['payments.stripe_live_refunds_enabled' => false]);

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $refunds = Mockery::mock();
    $client->shouldReceive('getService')->with('refunds')->andReturn($refunds);
    // refundPayment() also reads the intent to resolve the charge currency.
    $intents = Mockery::mock();
    $intents->shouldReceive('retrieve')->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_vst_548', 'object' => 'payment_intent', 'currency' => 'jpy'])
    );
    $client->shouldReceive('getService')->with('paymentIntents')->andReturn($intents);
    $refunds->shouldNotReceive('create');
    vstBindStripe($client);

    expect(fn () => app(OrderPaymentService::class)->refund($this->payment, ['amount' => 1500]))
        ->toThrow(HttpException::class);

    // CRITICAL: the original bug was "ledger says refunded, money never moved".
    // The gate refuses BEFORE any ledger write, so the books stay honest.
    expect($this->payment->fresh()->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and(OrderPayment::where('refund_of_id', $this->payment->id)->count())->toBe(0)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(1500.0);
});

it('#548(a) a Stripe API failure rolls the ledger back — no phantom refund row', function () {
    config(['payments.stripe_live_refunds_enabled' => true]);

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    // StripeClient exposes ->refunds through __get() -> getService(), so a plain
    // property assignment on the mock is bypassed. Stub getService instead.
    $refunds = Mockery::mock();
    $client->shouldReceive('getService')->with('refunds')->andReturn($refunds);
    // refundPayment() also reads the intent to resolve the charge currency.
    $intents = Mockery::mock();
    $intents->shouldReceive('retrieve')->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_vst_548', 'object' => 'payment_intent', 'currency' => 'jpy'])
    );
    $client->shouldReceive('getService')->with('paymentIntents')->andReturn($intents);
    $refunds->shouldReceive('create')
        ->once()
        ->andThrow(new ApiConnectionException('stripe is down'));
    vstBindStripe($client);

    expect(fn () => app(OrderPaymentService::class)->refund($this->payment, ['amount' => 1500]))
        ->toThrow(ApiConnectionException::class);

    expect($this->payment->fresh()->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and(OrderPayment::where('refund_of_id', $this->payment->id)->count())->toBe(0);
});

it('#548(a) a CASH refund bypasses the kill-switch and never touches Stripe (ledger-only, unchanged)', function () {
    config(['payments.stripe_live_refunds_enabled' => false]);

    $cashMethod = PaymentMethod::factory()->create([
        'code' => 'cash',
        'organization_id' => $this->tenant['org_id'],
        'branch_id' => $this->tenant['branch']->id,
    ]);

    $cashPayment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $cashMethod->id,
        'branch_id' => $this->tenant['branch']->id,
        'brand_id' => $this->tenant['brand']->id,
        'organization_id' => $this->tenant['org_id'],
        'amount' => 500,
        'reference_no' => 'CASH-1',
    ]);

    $refundRow = app(OrderPaymentService::class)->refund($cashPayment, ['amount' => 500]);

    expect((float) $refundRow->amount)->toBe(-500.0);
});

// =============================================================================
// HALF (b) — does the webhook handle charge.refunded?
// =============================================================================

it('#548(b) a DASHBOARD refund arrives via charge.refunded and lands in the ledger', function () {
    // No Stripe client needed — the webhook path never calls out.
    vstBindStripe(Mockery::mock(StripeClient::class));

    $event = vstSignedEvent('charge.refunded', [
        'object' => 'charge',
        'id' => 'ch_vst548',
        'payment_intent' => 'pi_vst548',
        'currency' => 'jpy',
        'refunds' => ['object' => 'list', 'data' => [
            ['id' => 're_dashboard_1', 'object' => 'refund', 'amount' => 1500, 'currency' => 'jpy', 'status' => 'succeeded'],
        ]],
    ]);

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    $refundRow = OrderPayment::where('refund_of_id', $this->payment->id)->first();

    expect($refundRow)->not->toBeNull()
        ->and((float) $refundRow->amount)->toBe(-1500.0)
        ->and($refundRow->metadata['stripe_refund_id'])->toBe('re_dashboard_1')
        ->and($this->payment->fresh()->status)->toBe(PaymentStatusEnum::Refunded)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('#548(b) a replayed charge.refunded webhook is idempotent — no double credit', function () {
    vstBindStripe(Mockery::mock(StripeClient::class));

    $post = function () {
        $event = vstSignedEvent('charge.refunded', [
            'object' => 'charge',
            'id' => 'ch_vst548',
            'payment_intent' => 'pi_vst548',
            'currency' => 'jpy',
            'refunds' => ['object' => 'list', 'data' => [
                ['id' => 're_same', 'object' => 'refund', 'amount' => 1500, 'currency' => 'jpy', 'status' => 'succeeded'],
            ]],
        ]);

        return $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
            $event['payload'],
        );
    };

    $post()->assertOk();
    $post()->assertOk();

    expect(OrderPayment::where('refund_of_id', $this->payment->id)->count())->toBe(1)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('#548(b) a PARTIAL dashboard refund credits only the refunded slice', function () {
    vstBindStripe(Mockery::mock(StripeClient::class));

    $event = vstSignedEvent('charge.refunded', [
        'object' => 'charge',
        'id' => 'ch_partial',
        'payment_intent' => 'pi_vst548',
        'currency' => 'jpy',
        'refunds' => ['object' => 'list', 'data' => [
            ['id' => 're_partial', 'object' => 'refund', 'amount' => 500, 'currency' => 'jpy', 'status' => 'succeeded'],
        ]],
    ]);

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    expect((float) OrderPayment::where('refund_of_id', $this->payment->id)->first()->amount)->toBe(-500.0)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(1000.0);
});

// =============================================================================
// The two halves share one idempotency key — no double-credit across paths
// =============================================================================

it('#548 an in-app refund followed by its own charge.refunded webhook does NOT double-credit', function () {
    config(['payments.stripe_live_refunds_enabled' => true]);

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    // StripeClient exposes ->refunds through __get() -> getService(), so a plain
    // property assignment on the mock is bypassed. Stub getService instead.
    $refunds = Mockery::mock();
    $client->shouldReceive('getService')->with('refunds')->andReturn($refunds);
    // refundPayment() also reads the intent to resolve the charge currency.
    $intents = Mockery::mock();
    $intents->shouldReceive('retrieve')->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_vst_548', 'object' => 'payment_intent', 'currency' => 'jpy'])
    );
    $client->shouldReceive('getService')->with('paymentIntents')->andReturn($intents);
    $refunds->shouldReceive('create')->once()->andReturn(Refund::constructFrom([
        'id' => 're_inapp_then_hook', 'object' => 'refund', 'amount' => 1500, 'status' => 'succeeded',
    ]));
    vstBindStripe($client);

    app(OrderPaymentService::class)->refund($this->payment, ['amount' => 1500]);

    // Stripe now fires charge.refunded for the SAME refund the app just made.
    $event = vstSignedEvent('charge.refunded', [
        'object' => 'charge',
        'id' => 'ch_echo',
        'payment_intent' => 'pi_vst548',
        'currency' => 'jpy',
        'refunds' => ['object' => 'list', 'data' => [
            ['id' => 're_inapp_then_hook', 'object' => 'refund', 'amount' => 1500, 'currency' => 'jpy', 'status' => 'succeeded'],
        ]],
    ]);

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    expect(OrderPayment::where('refund_of_id', $this->payment->id)->count())->toBe(1)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});
