<?php

/**
 * CLAIM D1 — a `succeeded` PaymentIntent is ORPHANED when a new intent
 * overwrites `orders.stripe_payment_intent_id`.
 *
 * createFullPaymentIntent() neither reuses nor cancels a `succeeded` intent,
 * so it creates a fresh intent and force-writes the column. The full-flow
 * webhook used to resolve the order ONLY by the pointer — the FIRST
 * customer's real charge landed on no order.
 *
 * FIXED by #1125 option B (2026-07-27): markOrderPaidFromIntent falls back to
 * the immutable `metadata.order_id` snapshot when the pointer has moved on,
 * so late money always finds its order and the usual guards (idempotency,
 * currency, overpayment) decide its fate. These tests are PINS now.
 *
 * Real HTTP routes + real services; only \Stripe\StripeClient is mocked.
 */

require_once __DIR__.'/vst_helpers.php';

use App\Models\OrderPayment;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Mockery\MockInterface;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

beforeEach(function () {
    vstConfigureStripe(currency: 'jpy');
    $this->tenant = vstTenant();
});

it('D1 PIN: intent B overwrites the column, then A\'s webhook still ledgers via metadata.order_id', function () {
    $order = vstOrder($this->tenant, total: 1000);

    // ---- Customer A opens the bill → intent A -------------------------------
    $created = [];
    $canceled = [];

    /** @var StripeClient&MockInterface $client */
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();

    $client->paymentIntents->shouldReceive('create')
        ->andReturnUsing(function (array $params) use (&$created) {
            $id = 'pi_'.chr(65 + count($created)); // pi_A, pi_B, ...
            $created[] = $id;

            return PaymentIntent::constructFrom(vstIntentObject(
                $id, (int) $params['amount'], (string) $params['currency'],
                'requires_payment_method', (array) ($params['metadata'] ?? []),
            ));
        });

    // Stripe's view of pi_A AFTER customer A actually paid: succeeded.
    $client->paymentIntents->shouldReceive('retrieve')
        ->with('pi_A')
        ->andReturn(PaymentIntent::constructFrom(vstIntentObject(
            'pi_A', 1000, 'jpy', 'succeeded',
            ['order_id' => $order->id, 'flow' => 'full'],
        )));

    $client->paymentIntents->shouldReceive('cancel')
        ->andReturnUsing(function (string $id) use (&$canceled) {
            $canceled[] = $id;

            return PaymentIntent::constructFrom(vstIntentObject($id, 1000, 'jpy', 'canceled', []));
        });

    vstBindStripe($client);

    $this->postJson("/api/v1/customer/orders/{$order->id}/full-payment-intent")->assertOk();

    expect($created)->toBe(['pi_A'])
        ->and($order->fresh()->stripe_payment_intent_id)->toBe('pi_A');

    // Customer A's card is CHARGED at Stripe (pi_A = succeeded). The webhook is
    // delayed / the tab was closed — nothing has hit our backend yet.

    // ---- Customer B scans the same QR → hits full-payment-intent again -------
    $this->postJson("/api/v1/customer/orders/{$order->id}/full-payment-intent")->assertOk();

    // The succeeded pi_A was NEITHER reused NOR canceled — a new intent was
    // minted and the column overwritten. THIS is the orphaning step.
    expect($created)->toBe(['pi_A', 'pi_B'])
        ->and($canceled)->toBe([]) // succeeded is in neither branch
        ->and($order->fresh()->stripe_payment_intent_id)->toBe('pi_B');

    // ---- pi_A's webhook finally lands ---------------------------------------
    $event = vstSignedEvent('payment_intent.succeeded', vstIntentObject(
        'pi_A', 1000, 'jpy', 'succeeded',
        ['order_id' => $order->id, 'order_code' => (string) $order->order_code, 'flow' => 'full'],
    ));

    // Stripe still gets its 200 — the failure is SILENT.
    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    $order->refresh();

    // #1125 option B — the metadata.order_id fallback finds the order: the
    // ¥1,000 that left customer A's card is ON the books and the order closed.
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->paid_amount)->toBe(1000.0)
        ->and($order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);

    // Nothing needed refunding — the charge was legitimate first money.
    expect(OrderPayment::whereNotNull('refund_of_id')->count())->toBe(0);
});

it('D1 the SPLIT flow is immune — it resolves by metadata.order_id', function () {
    $order = vstOrder($this->tenant, total: 1000);

    vstBindStripe(Mockery::mock(StripeClient::class));

    // A split intent whose id is NOT on orders.stripe_payment_intent_id at all.
    $event = vstSignedEvent('payment_intent.succeeded', vstIntentObject(
        'pi_split_orphanproof', 1000, 'jpy', 'succeeded',
        ['order_id' => $order->id, 'flow' => 'split'],
    ));

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    $order->refresh();

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->paid_amount)->toBe(1000.0)
        ->and($order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
});

it('D1 PIN: the SAME payload with flow=full now ledgers too — resolver parity with split', function () {
    $order = vstOrder($this->tenant, total: 1000);
    // Column points at a DIFFERENT (newer) intent — exactly the orphan state.
    $order->forceFill(['stripe_payment_intent_id' => 'pi_newer'])->save();

    vstBindStripe(Mockery::mock(StripeClient::class));

    $event = vstSignedEvent('payment_intent.succeeded', vstIntentObject(
        'pi_older_succeeded', 1000, 'jpy', 'succeeded',
        ['order_id' => $order->id, 'flow' => 'full'],
    ));

    $this->call('POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )->assertOk();

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->fresh()->paid_amount)->toBe(1000.0);
});
