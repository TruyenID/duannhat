<?php

use App\Services\Payment\ProviderEvent\ProviderEventIntakeService;

/**
 * Plan 047 Gate 3 — the provider-event webhook snapshot must not store
 * cardholder PII at rest.
 *
 * attachWebhookSnapshot cached the RAW Stripe object into `redacted_payload`,
 * including billing_details (name/email/phone/address) and
 * payment_method_details (card brand/last4/fingerprint). The snapshot is only
 * a cache for LegacyStripeWebhookBridge to rebuild the object and skip an API
 * call — it reads ids/amounts/currency/status/metadata/refunds, never PII — so
 * those keys are stripped before persistence.
 */
function stripPii(array $object): array
{
    $service = app(ProviderEventIntakeService::class);
    $method = new ReflectionMethod($service, 'stripCardholderPii');
    $method->setAccessible(true);

    return $method->invoke($service, $object);
}

it('strips cardholder PII from a charge snapshot while keeping functional fields', function () {
    $charge = [
        'id' => 'ch_123',
        'amount' => 1000,
        'currency' => 'usd',
        'status' => 'succeeded',
        'payment_intent' => 'pi_123',
        'receipt_email' => 'jane@example.com',
        'billing_details' => ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '+8190...'],
        'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'fingerprint' => 'fp_x']],
        'shipping' => ['name' => 'Jane Doe', 'address' => ['line1' => '1 St']],
        'refunds' => [
            'data' => [
                [
                    'id' => 're_1',
                    'amount' => 400,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                    'payment_method_details' => ['card' => ['last4' => '4242']],
                ],
            ],
        ],
    ];

    $out = stripPii($charge);

    // PII gone — top level and nested.
    expect($out)->not->toHaveKey('billing_details')
        ->and($out)->not->toHaveKey('payment_method_details')
        ->and($out)->not->toHaveKey('receipt_email')
        ->and($out)->not->toHaveKey('shipping')
        ->and($out['refunds']['data'][0])->not->toHaveKey('payment_method_details');

    // Functional fields the bridge needs are preserved.
    expect($out['id'])->toBe('ch_123')
        ->and($out['amount'])->toBe(1000)
        ->and($out['currency'])->toBe('usd')
        ->and($out['status'])->toBe('succeeded')
        ->and($out['payment_intent'])->toBe('pi_123')
        ->and($out['refunds']['data'][0]['id'])->toBe('re_1')
        ->and($out['refunds']['data'][0]['amount'])->toBe(400);
});

it('keeps a payment-intent snapshot usable (id/amount/currency/metadata) without PII', function () {
    $intent = [
        'id' => 'pi_777',
        'amount' => 2500,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => ['order_id' => 'ord_abc'],
        'receipt_email' => 'bob@example.com',
        'charges' => [
            'data' => [
                ['id' => 'ch_9', 'billing_details' => ['email' => 'bob@example.com']],
            ],
        ],
    ];

    $out = stripPii($intent);

    expect($out)->not->toHaveKey('receipt_email')
        ->and($out['charges']['data'][0])->not->toHaveKey('billing_details')
        ->and($out['id'])->toBe('pi_777')
        ->and($out['amount'])->toBe(2500)
        ->and($out['metadata']['order_id'])->toBe('ord_abc');
});
