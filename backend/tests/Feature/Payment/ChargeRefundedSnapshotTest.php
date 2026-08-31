<?php

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use Illuminate\Support\Str;

/**
 * Plan 047 Gate 3 — T3.5. The orchestrator refund-reconcile path read
 * `redacted_payload['refund_id']`, a key that was NEVER populated, so the branch
 * was dead code. attachWebhookSnapshot now surfaces the provider refund ids as
 * `refund_ids` (ids are not PII) and the applicator reconciles the matching
 * PaymentRefund's STATE. The legacy bridge still owns the ledger reversal and is
 * idempotent by stripe_refund_id, so both paths running cannot double-refund.
 */
beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
        'services.stripe.currency' => 'jpy',
    ]);

    $this->makeStripeEvent = function (string $type, array $dataObject, string $secret = 'whsec_test_secret_xyz'): array {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $dataObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [
            'payload' => $payload,
            'header' => "t={$timestamp},v1={$signature}",
            'event_id' => json_decode($payload, true)['id'],
        ];
    };

    $this->postWebhook = fn (string $payload, string $signature) => $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $this->chargeRefunded = fn (string $pi, array $refundIds, int $amount = 1000): array => [
        'object' => 'charge',
        'id' => 'ch_'.Str::random(10),
        'payment_intent' => $pi,
        'amount' => $amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'refunds' => [
            'object' => 'list',
            'data' => array_map(fn (string $id) => [
                'id' => $id,
                'object' => 'refund',
                'amount' => $amount,
                'currency' => 'jpy',
                'status' => 'succeeded',
                'payment_intent' => $pi,
            ], $refundIds),
        ],
    ];
});

it('surfaces provider refund ids as refund_ids on the inbox snapshot', function () {
    CustomerOrder::factory()->create([
        'stripe_payment_intent_id' => 'pi_snap',
        'status' => CustomerOrderStatusEnum::Open->value,
    ]);

    $event = ($this->makeStripeEvent)('charge.refunded', ($this->chargeRefunded)('pi_snap', ['re_1', 're_2']));
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->firstOrFail();

    expect($inbox->redacted_payload['refund_ids'] ?? null)->toBe(['re_1', 're_2'])
        // The charge snapshot is stripped of PII but keeps the refund ids too.
        ->and($inbox->redacted_payload['charge_snapshot']['refunds']['data'][0]['id'])->toBe('re_1');
});

it('does not double-refund when the same charge.refunded webhook is delivered twice', function () {
    $order = CustomerOrder::factory()->create([
        'stripe_payment_intent_id' => 'pi_dbl',
        'total_amount' => 1000,
        'paid_amount' => 1000,
        'status' => CustomerOrderStatusEnum::Closed->value,
    ]);

    // The original captured sale the refund reverses.
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'amount' => 1000,
        'status' => PaymentStatusEnum::Succeeded->value,
        'reference_no' => 'pi_dbl',
        'paid_at' => now(),
    ]);

    $charge = ($this->chargeRefunded)('pi_dbl', ['re_dbl']);

    // Two independent Stripe deliveries of the SAME refund (re_dbl).
    $e1 = ($this->makeStripeEvent)('charge.refunded', $charge);
    ($this->postWebhook)($e1['payload'], $e1['header'])->assertOk();
    $e2 = ($this->makeStripeEvent)('charge.refunded', $charge);
    ($this->postWebhook)($e2['payload'], $e2['header'])->assertOk();

    // Exactly ONE negative refund row — the legacy bridge is idempotent by
    // stripe_refund_id, and the orchestrator reconcile is state-only (no ledger).
    $refundRows = OrderPayment::query()
        ->where('reference_no', 'pi_dbl')
        ->where('amount', '<', 0)
        ->count();

    expect($refundRows)->toBe(1);
});

it('reads refund_ids and the legacy single refund_id, unique and merged', function () {
    $applicator = app(ProviderEventApplicator::class);
    $method = new ReflectionMethod($applicator, 'refundIdsFromEvent');
    $method->setAccessible(true);

    $event = new PaymentProviderEvent;
    $event->redacted_payload = ['refund_ids' => ['re_a', 're_b'], 'refund_id' => 're_b'];

    expect($method->invoke($applicator, $event))->toBe(['re_a', 're_b']);

    $legacyOnly = new PaymentProviderEvent;
    $legacyOnly->redacted_payload = ['refund_id' => 're_legacy'];
    expect($method->invoke($applicator, $legacyOnly))->toBe(['re_legacy']);

    $none = new PaymentProviderEvent;
    $none->redacted_payload = ['charge_snapshot' => ['id' => 'ch_x']];
    expect($method->invoke($applicator, $none))->toBe([]);
});
