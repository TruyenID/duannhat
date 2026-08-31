<?php

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Plan 047 Gate 3 — T3.4. The raw Stripe passthrough helpers must scope every
 * request to its OWNING connection so a Connect merchant's money can never
 * silently land on the platform account. The connection argument is required
 * (a caller can't forget it), and the adapter applies StripeConnectScope:
 *   - legacy global-platform connection → NO stripe_account (platform).
 *   - Connect merchant (acct_*)         → stripe_account = acct_*.
 */
function scopeGateway(StripeClient $client): StripePaymentGateway
{
    return new StripePaymentGateway($client);
}

function legacyConn(): GatewayConnectionData
{
    return (new LegacyGlobalStripeConnection)->connectionData();
}

function connectConn(string $account = 'acct_MERCHANT123'): GatewayConnectionData
{
    return new GatewayConnectionData(
        '11111111-1111-4111-8111-111111111111',
        PaymentGatewayProviderCodeEnum::Stripe,
        PaymentGatewayEnvironmentEnum::Test,
        $account,
        1,
    );
}

it('creates a PaymentIntent on the platform account for the legacy connection (no stripe_account)', function () {
    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents
        ->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return PaymentIntent::constructFrom(['id' => 'pi_1', 'object' => 'payment_intent']);
        });

    scopeGateway($client)->createPaymentIntent(['amount' => 1000, 'currency' => 'usd'], legacyConn());

    // Only params — no request-options arg carrying stripe_account.
    expect($captured)->toHaveCount(1)
        ->and($captured[0])->toBe(['amount' => 1000, 'currency' => 'usd']);
});

it('creates a PaymentIntent scoped to the merchant account for a Connect connection', function () {
    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents
        ->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return PaymentIntent::constructFrom(['id' => 'pi_2', 'object' => 'payment_intent']);
        });

    scopeGateway($client)->createPaymentIntent(['amount' => 1000, 'currency' => 'usd'], connectConn());

    expect($captured)->toHaveCount(2)
        ->and($captured[1])->toBe(['stripe_account' => 'acct_MERCHANT123']);
});

it('scopes retrieve/update/cancel PaymentIntent to the merchant account for a Connect connection', function () {
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();

    $retrieveOpts = $updateOpts = $cancelOpts = null;
    $client->paymentIntents->shouldReceive('retrieve')->once()
        ->andReturnUsing(function (...$a) use (&$retrieveOpts) {
            $retrieveOpts = $a[2] ?? null;

            return PaymentIntent::constructFrom(['id' => 'pi_r']);
        });
    $client->paymentIntents->shouldReceive('update')->once()
        ->andReturnUsing(function (...$a) use (&$updateOpts) {
            $updateOpts = $a[2] ?? null;

            return PaymentIntent::constructFrom(['id' => 'pi_u']);
        });
    $client->paymentIntents->shouldReceive('cancel')->once()
        ->andReturnUsing(function (...$a) use (&$cancelOpts) {
            $cancelOpts = $a[2] ?? null;

            return PaymentIntent::constructFrom(['id' => 'pi_c']);
        });

    $gateway = scopeGateway($client);
    $conn = connectConn();
    $gateway->retrievePaymentIntent('pi_r', $conn);
    $gateway->updatePaymentIntent('pi_u', ['amount' => 5], $conn);
    $gateway->cancelPaymentIntent('pi_c', $conn);

    expect($retrieveOpts)->toBe(['stripe_account' => 'acct_MERCHANT123'])
        ->and($updateOpts)->toBe(['stripe_account' => 'acct_MERCHANT123'])
        ->and($cancelOpts)->toBe(['stripe_account' => 'acct_MERCHANT123']);
});

it('retrieves a PaymentIntent on the platform account for the legacy connection (single arg)', function () {
    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->shouldReceive('retrieve')->once()
        ->andReturnUsing(function (...$a) use (&$captured) {
            $captured = $a;

            return PaymentIntent::constructFrom(['id' => 'pi_leg']);
        });

    scopeGateway($client)->retrievePaymentIntent('pi_leg', legacyConn());

    expect($captured)->toHaveCount(1)->and($captured[0])->toBe('pi_leg');
});

it('merges the merchant stripe_account into refund request options (idempotency key preserved)', function () {
    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->refunds = Mockery::mock();
    $client->refunds->shouldReceive('create')->once()
        ->andReturnUsing(function (array $params, array $opts) use (&$captured) {
            $captured = $opts;

            return Refund::constructFrom(['id' => 're_1', 'object' => 'refund']);
        });

    scopeGateway($client)->createRefund(
        ['payment_intent' => 'pi_x', 'amount' => 400],
        connectConn(),
        ['idempotency_key' => 'refund_abc'],
    );

    expect($captured)->toBe([
        'idempotency_key' => 'refund_abc',
        'stripe_account' => 'acct_MERCHANT123',
    ]);
});

it('leaves refund options untouched (platform) for the legacy connection', function () {
    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->refunds = Mockery::mock();
    $client->refunds->shouldReceive('create')->once()
        ->andReturnUsing(function (array $params, array $opts) use (&$captured) {
            $captured = $opts;

            return Refund::constructFrom(['id' => 're_2', 'object' => 'refund']);
        });

    scopeGateway($client)->createRefund(
        ['payment_intent' => 'pi_y', 'amount' => 400],
        legacyConn(),
        ['idempotency_key' => 'refund_def'],
    );

    expect($captured)->toBe(['idempotency_key' => 'refund_def']);
});

it('retrieves a Charge scoped to the merchant account for a Connect connection', function () {
    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->charges = Mockery::mock();
    $client->charges->shouldReceive('retrieve')->once()
        ->andReturnUsing(function (...$a) use (&$captured) {
            $captured = $a;

            return Charge::constructFrom(['id' => 'ch_1']);
        });

    scopeGateway($client)->retrieveCharge('ch_1', connectConn());

    expect($captured[2] ?? null)->toBe(['stripe_account' => 'acct_MERCHANT123']);
});
