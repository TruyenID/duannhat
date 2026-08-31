<?php

/**
 * Plan 048 Gate 2 (T2.1/T2.2/T2.3/T2.6/T2.7) — customer-web Stripe on a real
 * policy-backed connection.
 *
 * Acceptance rows P048-B (plans/plan-048/TESTS.md):
 *   B1 takeaway Stripe: intent → confirm → single ledger row + order closed + attempt succeeded
 *   B2 takeaway counter: zero OrderPayment until POS records the tender
 *   B4 resolved connection id on the payment row matches policy (not a synthetic bootstrap row)
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\ProductSku;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Customer\StripePaymentService;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment');

beforeEach(function () {
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy',
        'services.stripe.webhook_secret' => 'whsec_test_dummy',
        'services.stripe.currency' => 'jpy',
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['pos', 'customer_web'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => true,
            'customer_web' => true,
        ],
    ]);
});

/** Seed a policy-backed Stripe connection whose option approves customer_web. */
function plan048SeedCustomerWebPolicy(PaymentPolicyApiFixtures $fixtures): string
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

function plan048TakeawayOrder(PaymentPolicyApiFixtures $fixtures, float $total = 1000.0): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $fixtures->organization->id,
        'brand_id' => $fixtures->brand->id,
        'branch_id' => $fixtures->shop->id,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => $total,
        'paid_amount' => 0,
    ]);
}

it('B4: prepare resolves the policy-backed connection, not the bootstrap synthetic row', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $connectionId = plan048SeedCustomerWebPolicy($fixtures);

    $order = plan048TakeawayOrder($fixtures);

    $attemptId = app(OrderPaymentOrchestrationCompat::class)
        ->prepareStripePaymentAttempt($order, 'pi_plan048_policy', 1000, 'JPY');

    expect($attemptId)->not->toBeNull();

    $attempt = PaymentAttempt::query()->findOrFail($attemptId);

    expect((string) $attempt->connection_id)->toBe($connectionId)
        ->and((string) $attempt->connection?->merchant_account_id)
        ->not->toStartWith('orchestrator:customer-web:');
});

it('falls back to the bootstrap connection when the branch has no policy-backed Stripe option', function () {
    $organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $organizationId,
        'console_organization_id' => $organizationId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $organizationId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $organizationId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $organizationId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => 800,
        'paid_amount' => 0,
    ]);

    $attemptId = app(OrderPaymentOrchestrationCompat::class)
        ->prepareStripePaymentAttempt($order, 'pi_plan048_fallback', 800, 'JPY');

    $attempt = PaymentAttempt::query()->findOrFail($attemptId);

    expect((string) $attempt->connection?->merchant_account_id)
        ->toStartWith('orchestrator:customer-web:');
});

it('B1: takeaway Stripe settles once with gateway identity stamped on the single ledger row', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    $connectionId = plan048SeedCustomerWebPolicy($fixtures);

    $order = plan048TakeawayOrder($fixtures, 1000.0);
    $order->forceFill(['stripe_payment_intent_id' => 'pi_plan048_b1'])->save();

    $attemptId = app(OrderPaymentOrchestrationCompat::class)
        ->prepareStripePaymentAttempt($order, 'pi_plan048_b1', 1000, 'JPY');

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => 'pi_plan048_b1',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => ['flow' => 'full', 'order_currency' => 'JPY'],
    ]));

    $payments = OrderPayment::query()->where('customer_order_id', $order->id)->get();
    $attempt = PaymentAttempt::query()->findOrFail($attemptId);
    $order->refresh();

    expect($payments)->toHaveCount(1)
        ->and((string) $payments->first()->payment_attempt_id)->toBe((string) $attemptId)
        ->and((string) $payments->first()->gateway_connection_id)->toBe($connectionId)
        ->and((string) $payments->first()->gateway_option_id)->toBe((string) $fixtures->option->id)
        ->and($order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value)
        ->and((float) $order->paid_amount)->toBe(1000.0)
        ->and($attempt->fresh()->state->value)->toBe('succeeded');
});

it('B1 idempotency: replaying the same intent does not write a second ledger row', function () {
    $fixtures = new PaymentPolicyApiFixtures;
    $fixtures->bind();
    plan048SeedCustomerWebPolicy($fixtures);

    $order = plan048TakeawayOrder($fixtures, 1000.0);
    $order->forceFill(['stripe_payment_intent_id' => 'pi_plan048_replay'])->save();

    app(OrderPaymentOrchestrationCompat::class)
        ->prepareStripePaymentAttempt($order, 'pi_plan048_replay', 1000, 'JPY');

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_plan048_replay',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => ['flow' => 'full', 'order_currency' => 'JPY'],
    ]);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->markOrderPaidFromIntent($intent);
    $service->markOrderPaidFromIntent($intent);

    expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1);
});

it('B2: counter-pay takeaway creates no payment row until the POS tender lands', function () {
    Mail::fake();

    $organization = Organization::factory()->create([
        'console_organization_id' => '00000000-bbbb-4bbb-bbbb-000000000048',
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
        'locale' => 'vi-VN',
    ]);
    $sku = ProductSku::factory()->create();

    $response = $this->postJson("/api/v1/customer/branches/{$branch->slug}/orders", [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
        'customer_takeaway_name' => 'Plan 048 counter',
        'customer_takeaway_phone' => '+84336909454',
        'payment_method' => 'counter',
    ])->assertCreated();

    $order = CustomerOrder::query()->findOrFail($response->json('data.id'));

    // Invariant #5: no fake payment row at customer submit — money at POS only.
    expect(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(0)
        ->and((float) $order->paid_amount)->toBe(0.0);

    // POS collects later: staff moves the order to checkout and records cash.
    $order->forceFill(['status' => 'checkout'])->save();
    $operator = User::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
    ]);
    $cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $organization->id,
        'branch_id' => $branch->id,
        'is_active' => true,
    ]);

    $total = (float) $order->fresh()->total_amount;
    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $cash->id,
        'amount' => $total,
        'tendered_amount' => $total,
        'received_by_id' => $operator->id,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'plan048-b2-counter',
    ]);

    expect($payment->status->value)->toBe('succeeded')
        ->and(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->fresh()->paid_amount)->toBe($total);
});
