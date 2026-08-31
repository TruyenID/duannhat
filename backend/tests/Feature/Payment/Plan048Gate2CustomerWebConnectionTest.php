<?php

/**
 * Plan-048 Gate 2 (#1081) — customer-web Stripe INTENT calls run under the
 * policy-resolved brand connection, not the legacy global platform one.
 *
 * The orchestration identity (attempt/ledger) already resolved through the
 * policy; these tests pin the missing half: the actual Stripe API call now
 * carries the resolved connection, so StripeConnectScope applies the brand's
 * `stripe_account` and the money lands in the RIGHT merchant.
 */

use App\Models\CustomerOrder;
use App\Models\ShopOrderSetting;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

function gate2FakeIntent(): PaymentIntent
{
    return PaymentIntent::constructFrom([
        'id' => 'pi_gate2_'.uniqid(),
        'client_secret' => 'pi_gate2_secret',
        'currency' => 'jpy',
        'amount' => 1000,
        'status' => 'requires_payment_method',
    ]);
}

function gate2Order(PaymentPolicyApiFixtures $fx): CustomerOrder
{
    ShopOrderSetting::factory()->create([
        'organization_id' => $fx->organization->id,
        'branch_id' => $fx->shop->id,
        'currency_code' => 'JPY',
    ]);

    return CustomerOrder::factory()->create([
        'organization_id' => $fx->organization->id,
        'brand_id' => $fx->brand->id,
        'branch_id' => $fx->shop->id,
        'total_amount' => 3000,
        'paid_amount' => 0,
        'status' => 'open',
    ]);
}

beforeEach(function () {
    config()->set('services.stripe.key', 'pk_test_dummy');
    config()->set('services.stripe.secret', 'sk_test_dummy');
});

it('creates the split intent under the policy-resolved brand connection', function () {
    $fx = new PaymentPolicyApiFixtures;
    $fx->bind();
    $connection = $fx->seedConnection(['merchant_account_id' => 'acct_gate2_pilot']);
    // The fixture approves pos/workstation only — open the customer_web lane.
    DB::table('payment_gateway_connection_options')
        ->where('connection_id', $connection->id)
        ->update(['approved_channels' => json_encode(['pos', 'workstation', 'customer_web'])]);
    $fx->publishInitialPolicyRevision();

    $order = gate2Order($fx);

    // The strongest possible assertion: the RAW Stripe call carries the
    // brand's Connect scope — money lands in acct_gate2_pilot, not platform.
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->expects('create')
        ->withArgs(function (array $params, array $opts = []) {
            return ($opts['stripe_account'] ?? null) === 'acct_gate2_pilot';
        })
        ->andReturn(gate2FakeIntent());

    $service = new StripePaymentService($client);
    $payload = $service->createSplitPaymentIntent($order, 1000.0);

    expect($payload['payment_intent_id'])->toStartWith('pi_gate2_');
});

it('falls back to the legacy connection when the branch has no policy-backed Stripe option', function () {
    $fx = new PaymentPolicyApiFixtures;
    $fx->bind();
    // No connection, no published revision — the pre-onboarding fleet state.

    $order = gate2Order($fx);

    // Legacy = platform scope: the raw create carries NO stripe_account.
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->expects('create')
        ->withArgs(fn (array $params, array $opts = []) => ! array_key_exists('stripe_account', $opts))
        ->andReturn(gate2FakeIntent());

    (new StripePaymentService($client))->createSplitPaymentIntent($order, 1000.0);
});

it('pins every call back to legacy when the rollback flag is off', function () {
    config()->set('payments.stripe_customer_web_resolved_connection', false);

    $fx = new PaymentPolicyApiFixtures;
    $fx->bind();
    $connection = $fx->seedConnection(['merchant_account_id' => 'acct_gate2_pilot']);
    DB::table('payment_gateway_connection_options')
        ->where('connection_id', $connection->id)
        ->update(['approved_channels' => json_encode(['pos', 'workstation', 'customer_web'])]);
    $fx->publishInitialPolicyRevision();

    $order = gate2Order($fx);

    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = Mockery::mock();
    $client->paymentIntents->expects('create')
        ->withArgs(fn (array $params, array $opts = []) => ! array_key_exists('stripe_account', $opts))
        ->andReturn(gate2FakeIntent());

    (new StripePaymentService($client))->createSplitPaymentIntent($order, 1000.0);
});
