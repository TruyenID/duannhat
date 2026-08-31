<?php

/**
 * CLAIM D3 / #1125 — async payment methods (Konbini, bank transfer).
 *
 * History: automatic_payment_methods originally opened async methods the
 * pipeline could not settle (option A closed the door card-only on
 * 2026-07-27). This file is now the PIN SUITE for option B (real async
 * support, shipped 2026-07-27):
 *
 *   - flag OFF (default): every intent-creation site stays card-only
 *   - flag ON: Stripe dynamic payment methods, redirects excluded, konbini
 *     voucher expiry bounded
 *   - confirm on a processing / voucher intent → awaiting_async_payment +
 *     PENDING ledger row (never 422, never counted as money)
 *   - webhook lifecycle: processing → pending row; late succeeded → the
 *     pending row FLIPS to succeeded and the order settles; payment_failed /
 *     canceled → row fails and the order returns to payable
 *   - reaper: an overdue order releases its live async intent at Stripe
 *     BEFORE expiring; an intent that already succeeded blocks expiry
 *
 * Real HTTP routes + real signed webhooks; only \Stripe\StripeClient is mocked.
 */

require_once __DIR__.'/vst_helpers.php';

use App\Jobs\CancelOverdueTakeawayOrders;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Mockery\MockInterface;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

beforeEach(function () {
    vstConfigureStripe(currency: 'jpy');
    $this->tenant = vstTenant();
    $this->order = vstOrder($this->tenant, total: 3000);
});

/** Intent object with a next_action block (konbini voucher etc.). */
function vstAsyncIntentObject(string $id, int $amount, string $status, array $metadata, ?array $nextAction = null): array
{
    $object = vstIntentObject($id, $amount, 'jpy', $status, $metadata);
    if ($nextAction !== null) {
        $object['next_action'] = $nextAction;
    }

    return $object;
}

function vstPostSignedWebhook(object $ctx, array $event)
{
    return $ctx->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    );
}

// =============================================================================
// 1. Intent-creation posture — card-only by default, dynamic when enabled.
// =============================================================================

it('D3 flag OFF (default): every intent-creation site stays card-only', function () {
    $captured = [];

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('create')
        ->andReturnUsing(function (array $params) use (&$captured) {
            $captured[] = $params;

            return PaymentIntent::constructFrom(vstIntentObject(
                'pi_apm_'.count($captured), (int) $params['amount'], (string) $params['currency'],
                'requires_payment_method', (array) ($params['metadata'] ?? []),
            ));
        });
    vstBindStripe($client);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/payment-intent")->assertOk();
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", ['amount' => 1000])->assertOk();

    $fresh = vstOrder($this->tenant, total: 3000);
    $this->postJson("/api/v1/customer/orders/{$fresh->id}/full-payment-intent")->assertOk();

    expect($captured)->toHaveCount(3);

    foreach ($captured as $params) {
        expect($params['payment_method_types'])->toBe(['card'])
            ->and($params)->not->toHaveKey('automatic_payment_methods');
    }
});

it('D3 flag ON: sites use dynamic payment methods with redirects excluded and bounded konbini expiry', function () {
    config(['payments.async_payment_methods.enabled' => true, 'payments.async_payment_methods.konbini_expires_after_days' => 2]);

    $captured = [];

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('create')
        ->andReturnUsing(function (array $params) use (&$captured) {
            $captured[] = $params;

            return PaymentIntent::constructFrom(vstIntentObject(
                'pi_dyn_'.count($captured), (int) $params['amount'], (string) $params['currency'],
                'requires_payment_method', (array) ($params['metadata'] ?? []),
            ));
        });
    vstBindStripe($client);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/full-payment-intent")->assertOk();

    expect($captured)->toHaveCount(1)
        ->and($captured[0])->not->toHaveKey('payment_method_types')
        ->and($captured[0]['automatic_payment_methods'])->toBe(['enabled' => true, 'allow_redirects' => 'never'])
        ->and($captured[0]['payment_method_options']['konbini']['expires_after_days'])->toBe(2);
});

// =============================================================================
// 2. Synchronous confirm — async pending is a STATE, not an error.
// =============================================================================

it('D3 confirm on a processing (konbini) intent → awaiting_async_payment + pending row, order stays open', function () {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('retrieve')
        ->with('pi_konbini')
        ->andReturn(PaymentIntent::constructFrom(vstAsyncIntentObject(
            'pi_konbini', 3000, 'processing',
            ['order_id' => $this->order->id, 'flow' => 'full'],
        )));
    vstBindStripe($client);

    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_konbini'])->save();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_konbini',
    ])->assertOk()
        ->assertJsonPath('data.payment_state', 'awaiting_async_payment')
        ->assertJsonPath('data.is_fully_paid', false);

    $row = OrderPayment::where('customer_order_id', $this->order->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->status->value)->toBe('pending')
        ->and($row->reference_no)->toBe('pi_konbini')
        ->and((float) $row->amount)->toBe(3000.0)
        ->and((array) $row->metadata)->toHaveKey('async_pending')
        // Pending money is NOT collected money.
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0)
        ->and($this->order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
});

it('D3 confirm on a requires_action KONBINI VOUCHER tracks awaiting; plain 3DS requires_action still 422s', function () {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('retrieve')->with('pi_voucher')
        ->andReturn(PaymentIntent::constructFrom(vstAsyncIntentObject(
            'pi_voucher', 3000, 'requires_action',
            ['order_id' => $this->order->id, 'flow' => 'full'],
            ['type' => 'konbini_display_details', 'konbini_display_details' => ['expires_at' => time() + 86400]],
        )));
    $client->paymentIntents->shouldReceive('retrieve')->with('pi_3ds')
        ->andReturn(PaymentIntent::constructFrom(vstAsyncIntentObject(
            'pi_3ds', 3000, 'requires_action',
            ['order_id' => $this->order->id, 'flow' => 'full'],
            ['type' => 'use_stripe_sdk'],
        )));
    vstBindStripe($client);

    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_voucher'])->save();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_voucher',
    ])->assertOk()->assertJsonPath('data.payment_state', 'awaiting_async_payment');

    $row = OrderPayment::where('reference_no', 'pi_voucher')->first();
    expect($row->status->value)->toBe('pending')
        ->and((array) $row->metadata)->toHaveKey('async_expires_at');

    // 3DS is an interactive action the payer still owes — not async money.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_3ds',
    ])->assertStatus(422);
});

// =============================================================================
// 3. Webhook lifecycle.
// =============================================================================

it('D3 payment_intent.processing webhook records the pending row', function () {
    vstBindStripe(Mockery::mock(StripeClient::class));

    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_konbini'])->save();

    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.processing', vstAsyncIntentObject(
        'pi_konbini', 3000, 'processing',
        ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    $row = OrderPayment::where('customer_order_id', $this->order->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->status->value)->toBe('pending')
        ->and($this->order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
});

it('D3 the late payment_intent.succeeded FLIPS the pending row and settles the order (no second row)', function () {
    vstBindStripe(Mockery::mock(StripeClient::class));
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_konbini'])->save();

    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.processing', vstAsyncIntentObject(
        'pi_konbini', 3000, 'processing', ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    // Two days later the guest pays at the FamilyMart.
    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.succeeded', vstAsyncIntentObject(
        'pi_konbini', 3000, 'succeeded', ['order_id' => $this->order->id, 'flow' => 'full', 'order_currency' => 'jpy'],
    )))->assertOk();

    $rows = OrderPayment::where('customer_order_id', $this->order->id)->get();
    $fresh = $this->order->fresh();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status->value)->toBe('succeeded')
        ->and($rows->first()->paid_at)->not->toBeNull()
        ->and((array) $rows->first()->metadata)->toHaveKey('async_settled')
        ->and((float) $fresh->paid_amount)->toBe(3000.0)
        ->and($fresh->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
});

it('D3 payment_intent.payment_failed fails the pending row and releases the order back to payable', function () {
    vstBindStripe(Mockery::mock(StripeClient::class));
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_konbini'])->save();

    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.processing', vstAsyncIntentObject(
        'pi_konbini', 3000, 'processing', ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.payment_failed', vstAsyncIntentObject(
        'pi_konbini', 3000, 'requires_payment_method', ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    $row = OrderPayment::where('reference_no', 'pi_konbini')->first();
    $fresh = $this->order->fresh();

    expect($row->status->value)->toBe('failed')
        ->and((array) $row->metadata)->toHaveKey('async_failure_reason')
        // The dead intent no longer squats on the order — a fresh pay works.
        ->and($fresh->stripe_payment_intent_id)->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(0.0);
});

it('D3 payment_intent.canceled (voucher expired) fails the pending row identically', function () {
    vstBindStripe(Mockery::mock(StripeClient::class));
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_konbini'])->save();

    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.processing', vstAsyncIntentObject(
        'pi_konbini', 3000, 'processing', ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.canceled', vstAsyncIntentObject(
        'pi_konbini', 3000, 'canceled', ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    expect(OrderPayment::where('reference_no', 'pi_konbini')->first()->status->value)->toBe('failed')
        ->and($this->order->fresh()->stripe_payment_intent_id)->toBeNull();
});

it('D3 a failed/canceled event with no pending row is a harmless no-op', function () {
    vstBindStripe(Mockery::mock(StripeClient::class));

    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.payment_failed', vstAsyncIntentObject(
        'pi_ghost', 3000, 'requires_payment_method', ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    expect(OrderPayment::count())->toBe(0);
});

// =============================================================================
// 4. D3+D1 tail — konbini paid AFTER the order minted a new intent: the money
//    still lands (metadata.order_id fallback + pending row flip).
// =============================================================================

it('D3+D1 a konbini paid at the store after a new intent was minted is still ledgered', function () {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('retrieve')->with('pi_konbini')
        ->andReturn(PaymentIntent::constructFrom(vstAsyncIntentObject(
            'pi_konbini', 3000, 'processing', ['order_id' => $this->order->id, 'flow' => 'full'],
        )));
    $client->paymentIntents->shouldReceive('cancel')->with('pi_konbini')
        ->andReturn(PaymentIntent::constructFrom(vstIntentObject('pi_konbini', 3000, 'jpy', 'canceled', [])));
    $client->paymentIntents->shouldReceive('create')
        ->andReturnUsing(fn (array $p) => PaymentIntent::constructFrom(vstIntentObject(
            'pi_card_new', (int) $p['amount'], (string) $p['currency'], 'requires_payment_method', (array) $p['metadata'],
        )));
    vstBindStripe($client);

    // Voucher printed + tracked.
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_konbini'])->save();
    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.processing', vstAsyncIntentObject(
        'pi_konbini', 3000, 'processing', ['order_id' => $this->order->id, 'flow' => 'full'],
    )))->assertOk();

    // Guest gives up waiting → mints a card intent; the old one is canceled at Stripe.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/full-payment-intent")->assertOk();
    expect($this->order->fresh()->stripe_payment_intent_id)->toBe('pi_card_new');

    // …but they DID pay the voucher before the cancel propagated.
    vstPostSignedWebhook($this, vstSignedEvent('payment_intent.succeeded', vstAsyncIntentObject(
        'pi_konbini', 3000, 'succeeded', ['order_id' => $this->order->id, 'flow' => 'full', 'order_currency' => 'jpy'],
    )))->assertOk();

    $fresh = $this->order->fresh();

    expect((float) $fresh->paid_amount)->toBe(3000.0)
        ->and(OrderPayment::where('reference_no', 'pi_konbini')->first()->status->value)->toBe('succeeded');
});

// =============================================================================
// 5. Reaper — expiry releases the intent money-safely.
// =============================================================================

function vstOverdueAsyncOrder(object $ctx): CustomerOrder
{
    $order = vstOrder($ctx->tenant, total: 3000);
    $order->forceFill([
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Pending->value,
        'payment_due_at' => now()->subMinutes(10),
        'stripe_payment_intent_id' => 'pi_konbini_reap',
    ])->save();

    return $order;
}

it('D3 reaper cancels the live async intent at Stripe, fails the row, then expires the order', function () {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('retrieve')->with('pi_konbini_reap')
        ->andReturn(PaymentIntent::constructFrom(vstAsyncIntentObject(
            'pi_konbini_reap', 3000, 'processing', ['flow' => 'full'],
        )));
    $client->paymentIntents->shouldReceive('cancel')->with('pi_konbini_reap')->once()
        ->andReturn(PaymentIntent::constructFrom(vstIntentObject('pi_konbini_reap', 3000, 'jpy', 'canceled', [])));
    $service = vstBindStripe($client);

    $order = vstOverdueAsyncOrder($this);
    $service->trackAsyncPendingFromIntent(PaymentIntent::constructFrom(vstAsyncIntentObject(
        'pi_konbini_reap', 3000, 'processing', ['order_id' => $order->id, 'flow' => 'full'],
    )));

    (new CancelOverdueTakeawayOrders)->handle();

    $fresh = $order->fresh();

    expect(OrderPayment::where('reference_no', 'pi_konbini_reap')->first()->status->value)->toBe('failed')
        ->and($fresh->auto_cancelled_at)->not->toBeNull();
});

it('D3 reaper REFUSES to expire an order whose async intent already succeeded', function () {
    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('retrieve')->with('pi_konbini_reap')
        ->andReturn(PaymentIntent::constructFrom(vstAsyncIntentObject(
            'pi_konbini_reap', 3000, 'succeeded', ['flow' => 'full'],
        )));
    $client->paymentIntents->shouldReceive('cancel')->never();
    $service = vstBindStripe($client);

    $order = vstOverdueAsyncOrder($this);
    $service->trackAsyncPendingFromIntent(PaymentIntent::constructFrom(vstAsyncIntentObject(
        'pi_konbini_reap', 3000, 'processing', ['order_id' => $order->id, 'flow' => 'full'],
    )));

    (new CancelOverdueTakeawayOrders)->handle();

    $fresh = $order->fresh();

    // Not expired — the money arrived; the succeeded webhook will settle it.
    expect($fresh->auto_cancelled_at)->toBeNull()
        ->and(OrderPayment::where('reference_no', 'pi_konbini_reap')->first()->status->value)->toBe('pending');
});
