<?php

/**
 * Plan 048 T2.5 (#1121) — customer-web echoes the policy identity it saw.
 *
 * Contract under test:
 *   - GET /customer/branches/{slug}/payment-context returns the published
 *     revision + effective customer_web Stripe option id (ids only, public),
 *     and null/null when the branch has no policy-backed option.
 *   - The *-payment-intent endpoints accept an optional policy_revision +
 *     gateway_option_id echo; malformed values are DROPPED (fail-open), never
 *     a 422 — a garbage hint must not block a card payment.
 *   - A stale echo produces a `customer_web_policy_drift` log entry while the
 *     server-resolved connection stays authoritative; a matching echo logs
 *     nothing.
 */

use App\Models\CustomerOrder;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment');

beforeEach(function () {
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy',
        'payments.stripe_customer_web_resolved_connection' => true,
    ]);
});

function plan048T25SeedPolicy(PaymentPolicyApiFixtures $fixtures): string
{
    $connection = $fixtures->seedConnection();

    DB::table('payment_gateway_connection_options')
        ->where('connection_id', $connection->id)
        ->update([
            'approved_channels' => json_encode(['pos', 'workstation', 'customer_web'], JSON_THROW_ON_ERROR),
        ]);

    $fixtures->publishInitialPolicyRevision();

    return (string) $connection->id;
}

function plan048T25Order(PaymentPolicyApiFixtures $fixtures): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $fixtures->organization->id,
        'brand_id' => $fixtures->brand->id,
        'branch_id' => $fixtures->shop->id,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => 1000.0,
        'paid_amount' => 0,
    ]);
}

it('payment-context returns the published revision + effective option id', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan048T25SeedPolicy($fixtures);

    $response = $this->getJson('/api/v1/customer/branches/'.$fixtures->shop->slug.'/payment-context');

    $response->assertOk()
        ->assertJsonPath('data.gateway_option_id', (string) $fixtures->option->id);

    expect((int) $response->json('data.policy_revision'))->toBeGreaterThanOrEqual(1);
});

it('payment-context returns nulls when the branch has no policy-backed option', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    // No seedConnection / publish — legacy global-connection era.

    $this->getJson('/api/v1/customer/branches/'.$fixtures->shop->slug.'/payment-context')
        ->assertOk()
        ->assertJsonPath('data.policy_revision', null)
        ->assertJsonPath('data.gateway_option_id', null);
});

it('payment-context 404s an unknown branch slug', function () {
    $this->getJson('/api/v1/customer/branches/no-such-branch/payment-context')
        ->assertNotFound();
});

it('full-payment-intent forwards a valid echo and drops a malformed one (fail-open)', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan048T25SeedPolicy($fixtures);
    $order = plan048T25Order($fixtures);
    $optionId = (string) Str::uuid();

    $stripe = Mockery::mock(StripePaymentService::class);
    $stripe->shouldReceive('withClientPolicyHint')->once()->with(3, $optionId)->andReturnSelf();
    $stripe->shouldReceive('createFullPaymentIntent')->once()
        ->andReturn(['client_secret' => 'cs_test', 'payment_intent_id' => 'pi_test']);
    app()->instance(StripePaymentService::class, $stripe);

    $this->postJson("/api/v1/customer/orders/{$order->id}/full-payment-intent", [
        'policy_revision' => 3,
        'gateway_option_id' => $optionId,
    ])->assertOk()->assertJsonPath('data.payment_intent_id', 'pi_test');

    // Malformed echo → sanitized away entirely (the service is never touched),
    // still 200 (never a 422).
    $stripe2 = Mockery::mock(StripePaymentService::class);
    $stripe2->shouldNotReceive('withClientPolicyHint');
    $stripe2->shouldReceive('createFullPaymentIntent')->once()
        ->andReturn(['client_secret' => 'cs_test2', 'payment_intent_id' => 'pi_test2']);
    app()->instance(StripePaymentService::class, $stripe2);

    $this->postJson("/api/v1/customer/orders/{$order->id}/full-payment-intent", [
        'policy_revision' => 'not-a-number',
        'gateway_option_id' => 'not-a-uuid',
    ])->assertOk()->assertJsonPath('data.payment_intent_id', 'pi_test2');
});

it('a stale echo logs customer_web_policy_drift while the resolved connection stays authoritative', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $connectionId = plan048T25SeedPolicy($fixtures);
    $order = plan048T25Order($fixtures);

    $channel = Mockery::spy(LoggerInterface::class);
    Log::partialMock()->shouldReceive('channel')->with('payment_orchestration')->andReturn($channel);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->withClientPolicyHint(999, (string) Str::uuid());

    $method = new ReflectionMethod($service, 'connectionForOrder');
    $connection = $method->invoke($service, $order);

    // Server authoritative: the stale echo did not change the resolution.
    expect((string) $connection->connectionId)->toBe($connectionId);

    $channel->shouldHaveReceived('info')->with('customer_web_policy_drift', Mockery::on(
        fn (array $ctx): bool => $ctx['order_id'] === (string) $order->id
            && $ctx['client_policy_revision'] === 999
            && $ctx['server_policy_revision'] >= 1,
    ));
});

it('a matching echo logs no drift', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan048T25SeedPolicy($fixtures);
    $order = plan048T25Order($fixtures);

    // Read the truth the same way the client would.
    $context = $this->getJson('/api/v1/customer/branches/'.$fixtures->shop->slug.'/payment-context')->json('data');

    $channel = Mockery::spy(LoggerInterface::class);
    Log::partialMock()->shouldReceive('channel')->with('payment_orchestration')->andReturn($channel);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->withClientPolicyHint((int) $context['policy_revision'], (string) $context['gateway_option_id']);

    (new ReflectionMethod($service, 'connectionForOrder'))->invoke($service, $order);

    $channel->shouldNotHaveReceived('info', ['customer_web_policy_drift', Mockery::any()]);
});
