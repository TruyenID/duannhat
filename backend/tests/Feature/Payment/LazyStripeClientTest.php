<?php

/**
 * #1232 — the Stripe SDK client must be built on FIRST USE, never at
 * construction or container-resolution time.
 *
 * Why this exists: `StripePaymentGateway` used to `new StripeClient($secret)`
 * inside its constructor and throw when `STRIPE_SECRET` was empty. It is a
 * container singleton reached transitively by anything payment-shaped —
 * `ProviderEventApplicator` → `LegacyStripeWebhookBridge` →
 * `StripePaymentService` → the gateway — so applying a *PayPay* webhook on a
 * host with no Stripe key blew up with `STRIPE_SECRET is not configured.`
 * (#1231 turned dev red exactly this way, and was papered over with a fake key
 * in phpunit.xml). A PayPay-only or cash-only shop must not need a Stripe key.
 *
 * These tests deliberately run with the secret UNSET. If someone re-introduces
 * eager construction, they fail here rather than in ten unrelated suites.
 */

use App\Services\Customer\StripePaymentService;
use App\Services\Payment\Gateway\Stripe\StripeCapabilitySet;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use App\Services\Payment\ProviderEvent\LegacyStripeWebhookBridge;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use App\Services\Payment\Settlement\Stripe\StripeSettlementApiClient;
use Stripe\StripeClient;

beforeEach(function () {
    // The whole point: no key anywhere.
    config(['services.stripe.secret' => null]);
});

it('constructs the gateway with no STRIPE_SECRET', function () {
    expect(new StripePaymentGateway)->toBeInstanceOf(StripePaymentGateway::class);
});

it('resolves the gateway singleton from the container with no STRIPE_SECRET', function () {
    expect(app(StripePaymentGateway::class))->toBeInstanceOf(StripePaymentGateway::class);
});

it('resolves the whole non-Stripe webhook chain with no STRIPE_SECRET', function () {
    // This is the #1231 failure path: a PayPay provider event is applied through
    // an applicator that eagerly wires the legacy Stripe bridge.
    expect(app(StripePaymentService::class))->toBeInstanceOf(StripePaymentService::class)
        ->and(app(LegacyStripeWebhookBridge::class))->toBeInstanceOf(LegacyStripeWebhookBridge::class)
        ->and(app(ProviderEventApplicator::class))->toBeInstanceOf(ProviderEventApplicator::class);
});

it('answers capability questions with no STRIPE_SECRET', function () {
    // A pure in-memory query must never reach for the SDK.
    $connection = (new LegacyGlobalStripeConnection)->connectionData();

    expect((new StripePaymentGateway)->capabilities($connection))
        ->toEqual(StripeCapabilitySet::forEnvironment($connection->environment));
});

it('constructs the settlement API client with no STRIPE_SECRET', function () {
    expect(new StripeSettlementApiClient)->toBeInstanceOf(StripeSettlementApiClient::class);
});

it('still fails loudly when a caller actually needs Stripe and the secret is missing', function () {
    // Requirement 2 of #1232: laziness must not become a silent no-op. The
    // original exception type and message are preserved.
    $gateway = new StripePaymentGateway;

    $client = (new ReflectionClass($gateway))->getMethod('client');

    expect(fn () => $client->invoke($gateway))
        ->toThrow(RuntimeException::class, 'STRIPE_SECRET is not configured.');
});

it('builds the client once the secret is present and caches it', function () {
    config(['services.stripe.secret' => 'sk_test_lazy_client_contract']);

    $gateway = new StripePaymentGateway;
    $client = (new ReflectionClass($gateway))->getMethod('client');

    $first = $client->invoke($gateway);

    expect($first)->toBeInstanceOf(StripeClient::class)
        ->and($client->invoke($gateway))->toBe($first);
});

it('prefers an injected client over the config secret', function () {
    $injected = new StripeClient('sk_test_injected_double');

    $gateway = new StripePaymentGateway($injected);
    $client = (new ReflectionClass($gateway))->getMethod('client');

    expect($client->invoke($gateway))->toBe($injected);
});
