<?php

/**
 * #1108 — transition fallback: a Connect-scoped retrieve that 404s with
 * resource_missing retries platform-scoped (customer-web intents are still
 * created on the platform account until the T7.3 credential cutover).
 */

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\GatewayRequestContext;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use Illuminate\Support\Str;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

uses()->group('payment');

function plan048ConnectConnectionData(): GatewayConnectionData
{
    return new GatewayConnectionData(
        (string) Str::uuid(),
        PaymentGatewayProviderCodeEnum::Stripe,
        PaymentGatewayEnvironmentEnum::Test,
        'acct_plan048_fallback',
        1,
    );
}

function plan048RetrieveCommand(GatewayConnectionData $connection): RetrievePaymentCommand
{
    return new RetrievePaymentCommand(
        $connection,
        new GatewayRequestContext((string) Str::uuid(), (string) Str::uuid(), 'corr-1108'),
        new ProviderObjectReference('pi_platform_created'),
    );
}

it('retries platform-scoped when the Connect-scoped retrieve hits resource_missing', function () {
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_platform_created',
        'object' => 'payment_intent',
        'amount' => 500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->expects('retrieve')
        ->withArgs(fn ($id, $params, $opts) => $id === 'pi_platform_created'
            && ($opts['stripe_account'] ?? null) === 'acct_plan048_fallback')
        ->andThrow(InvalidRequestException::factory(
            'No such payment_intent', 404, null, null, null, 'resource_missing',
        ));
    $client->paymentIntents->expects('retrieve')
        ->with('pi_platform_created', [])
        ->andReturn($intent);

    $gateway = new StripePaymentGateway($client);

    $result = $gateway->retrievePayment(plan048RetrieveCommand(plan048ConnectConnectionData()));

    expect($result->payment?->value)->toBe('pi_platform_created');
});

it('does not swallow non-resource_missing scoped failures', function () {
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->expects('retrieve')
        ->andThrow(InvalidRequestException::factory(
            'Not allowed', 403, null, null, null, 'account_invalid',
        ));

    $gateway = new StripePaymentGateway($client);

    expect(fn () => $gateway->retrievePayment(plan048RetrieveCommand(plan048ConnectConnectionData())))
        ->toThrow(InvalidRequestException::class);
});
