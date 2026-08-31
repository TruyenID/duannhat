<?php

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Exceptions\WebhookVerificationFailed;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Tests\Support\Payment\PaymentGatewayFixtures;

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
    ]);
});

it('verifies a legacy stripe webhook and maps a normalized event', function () {
    $payload = json_encode([
        'id' => 'evt_gateway_1',
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'created' => time(),
        'data' => ['object' => ['id' => 'pi_gateway_1', 'object' => 'payment_intent', 'status' => 'succeeded']],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_secret_xyz');

    $gateway = new StripePaymentGateway(new StripeClient(['api_key' => 'sk_test_dummy']));
    $connection = app(LegacyGlobalStripeConnection::class)->connectionData();

    $verified = $gateway->verifyWebhook(new VerifyWebhookCommand(
        $connection,
        $payload,
        ['Stripe-Signature' => "t={$timestamp},v1={$signature}"],
        'stripe-gateway-test:1',
    ));

    expect($verified->providerEventId)->toBe('evt_gateway_1')
        ->and($verified->eventType)->toBe('payment_intent.succeeded')
        ->and($verified->payment?->value)->toBe('pi_gateway_1');
});

it('rejects invalid webhook signatures', function () {
    $gateway = new StripePaymentGateway(new StripeClient(['api_key' => 'sk_test_dummy']));
    $connection = app(LegacyGlobalStripeConnection::class)->connectionData();

    $gateway->verifyWebhook(new VerifyWebhookCommand(
        $connection,
        json_encode(['id' => 'evt_bad', 'object' => 'event', 'type' => 'payment_intent.created', 'data' => ['object' => []]]),
        ['Stripe-Signature' => 'bad-signature'],
        'stripe-gateway-test:2',
    ));
})->throws(WebhookVerificationFailed::class);

it('exposes stripe capabilities for the configured environment', function () {
    $gateway = new StripePaymentGateway(new StripeClient(['api_key' => 'sk_test_dummy']));

    $capabilities = $gateway->capabilities(PaymentGatewayFixtures::connection());

    expect($capabilities->provider)->toBe(PaymentGatewayProviderCodeEnum::Stripe)
        ->and($capabilities->environment)->toBe(PaymentGatewayEnvironmentEnum::Test);
});

it('resolves legacy global stripe environment from secret key shape', function () {
    config(['services.stripe.secret' => 'sk_live_example']);

    $connection = app(LegacyGlobalStripeConnection::class)->connectionData();

    expect($connection->environment)->toBe(PaymentGatewayEnvironmentEnum::Live)
        ->and($connection->merchantAccountReference)->toBe(LegacyGlobalStripeConnection::MERCHANT_REFERENCE)
        ->and(Str::isUuid($connection->connectionId))->toBeTrue();
});
