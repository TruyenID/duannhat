<?php

use App\Events\OrderPaid;
use App\Events\OrderPaymentRecorded;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Plan 047 Gate 4 (T4.8) — settlement parity matrix.
 *
 * The plan's non-negotiable invariant #3 is that "every fully-paid order
 * passes through the same idempotent settlement boundary". This test pins the
 * observable settlement side-effect signature and asserts it is IDENTICAL
 * regardless of which payment rail settled the order:
 *
 *   - payment row → succeeded
 *   - order → closed with closed_at stamped
 *   - customer_orders.paid_amount === total
 *   - OrderPayment::netCollectedForOrder() === total  (the ledger projection)
 *   - exactly one OrderPaid, zero OrderPaymentRecorded (settlement, not partial)
 *
 * Today cash and card-terminal both settle through the canonical
 * OrderPaymentService::create() path; Stripe webhook/sync confirm settle
 * through markOrderPaidFromIntent → recordStripeWebhookPayment →
 * syncLedgerCacheAndSettleIfPaid (T4.6), with orchestrator prepare/finalize
 * when PAYMENT_ORCHESTRATOR_RUNTIME enables customer_web (T4.5).
 */
beforeEach(function () {
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy',
        'services.stripe.webhook_secret' => 'whsec_test_dummy',
        'services.stripe.currency' => 'jpy',
    ]);

    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->operator = User::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->payments = app(OrderPaymentService::class);
});

/**
 * @return array<string, mixed>
 */
function parityUnpaidOrder(string $organizationId, string $brandId, string $branchId, float $total = 1000): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $organizationId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => $total,
        'paid_amount' => 0,
    ]);
}

function parityMethod(string $state, string $organizationId, string $branchId): PaymentMethod
{
    return PaymentMethod::factory()->{$state}()->create([
        'organization_id' => $organizationId,
        'branch_id' => $branchId,
        'is_active' => true,
    ]);
}

function paritySettleStripeWebhookFull(CustomerOrder $order, string $intentId = 'pi_matrix_full'): void
{
    $order->forceFill(['stripe_payment_intent_id' => $intentId])->save();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => $intentId,
        'object' => 'payment_intent',
        'amount' => (int) $order->total_amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'full',
            'order_currency' => 'JPY',
        ],
    ]));
}

/**
 * The rails that reach settlement through the canonical create() path today.
 * Each entry is a PaymentMethodFactory state that lands straight on succeeded.
 */
dataset('settling_rails', [
    'cash' => ['cash'],
    'card terminal' => ['cardTerminal'],
]);

it('produces an identical settlement side-effect signature across rails', function (string $state) {
    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $method = parityMethod($state, $this->organizationId, $this->branch->id);

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $fresh = $order->fresh();

    expect($payment->status->value)->toBe('succeeded')
        ->and($fresh->status->value)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertNotDispatched(OrderPaymentRecorded::class);
})->with('settling_rails');

it('produces the same settlement signature for stripe webhook full payment', function () {
    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $order->forceFill(['status' => 'open'])->save();

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    paritySettleStripeWebhookFull($order, 'pi_matrix_stripe_full');

    $fresh = $order->fresh();
    $payment = OrderPayment::query()
        ->where('customer_order_id', $order->id)
        ->where('reference_no', 'pi_matrix_stripe_full')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->status->value)->toBe('succeeded')
        ->and($fresh->status->value)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertNotDispatched(OrderPaymentRecorded::class);
});

it('produces the same settlement signature for orchestrator pos cash', function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['pos'],
        'payments.orchestrator_runtime.transport_switches' => ['pos' => true],
    ]);

    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $method = parityMethod('cash', $this->organizationId, $this->branch->id);

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'parity-orchestrator-pos',
    ]);

    $fresh = $order->fresh();

    expect($payment->status->value)->toBe('succeeded')
        ->and($fresh->status->value)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertNotDispatched(OrderPaymentRecorded::class);
});

it('produces the same settlement signature for stripe synchronous confirm', function () {
    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $order->forceFill([
        'stripe_payment_intent_id' => 'pi_matrix_sync_confirm',
        'status' => 'open',
    ])->save();

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_matrix_sync_confirm')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_matrix_sync_confirm',
            'object' => 'payment_intent',
            'amount' => 1000,
            'currency' => 'jpy',
            'status' => 'succeeded',
            'metadata' => [
                'flow' => 'full',
                'order_currency' => 'JPY',
            ],
        ]));

    (new StripePaymentService($stripe))->confirmAndRecordPayment('pi_matrix_sync_confirm');

    $fresh = $order->fresh();
    $payment = OrderPayment::query()
        ->where('customer_order_id', $order->id)
        ->where('reference_no', 'pi_matrix_sync_confirm')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->status->value)->toBe('succeeded')
        ->and($fresh->status->value)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertNotDispatched(OrderPaymentRecorded::class);
});

it('produces the same settlement signature for orchestrator customer_web stripe sync confirm', function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['customer_web'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => false,
            'kiosk' => false,
            'workstation' => false,
            'customer_web' => true,
        ],
    ]);

    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $order->forceFill(['status' => 'open'])->save();

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_matrix_orchestrator_sync',
            'object' => 'payment_intent',
            'amount' => 1000,
            'currency' => 'jpy',
            'status' => 'requires_payment_method',
            'client_secret' => 'cs_test_orchestrator',
        ]));
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_matrix_orchestrator_sync')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_matrix_orchestrator_sync',
            'object' => 'payment_intent',
            'amount' => 1000,
            'currency' => 'jpy',
            'status' => 'succeeded',
            'metadata' => [
                'flow' => 'full',
                'order_currency' => 'JPY',
            ],
        ]));

    $stripeService = new StripePaymentService($stripe);
    $stripeService->createFullPaymentIntent($order->fresh());
    $stripeService->confirmAndRecordPayment('pi_matrix_orchestrator_sync');

    $fresh = $order->fresh();
    $payment = OrderPayment::query()
        ->where('customer_order_id', $order->id)
        ->where('reference_no', 'pi_matrix_orchestrator_sync')
        ->first();
    $attempt = PaymentAttempt::query()
        ->where('provider_object_id', 'pi_matrix_orchestrator_sync')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->payment_attempt_id)->not->toBeNull()
        ->and($payment->status->value)->toBe('succeeded')
        ->and($attempt)->not->toBeNull()
        ->and($attempt->channel)->toBe(PaymentChannelEnum::CustomerWeb)
        ->and($attempt->state)->toBe(PaymentAttemptStateEnum::Succeeded)
        ->and($fresh->status->value)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertNotDispatched(OrderPaymentRecorded::class);
});

it('produces the same settlement signature for orchestrator customer_web stripe split payment', function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['customer_web'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => false,
            'kiosk' => false,
            'workstation' => false,
            'customer_web' => true,
        ],
    ]);

    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $order->forceFill(['status' => 'open'])->save();

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_matrix_orchestrator_split',
        'object' => 'payment_intent',
        'amount' => 400,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $order->id,
            'order_currency' => 'JPY',
        ],
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_matrix_orchestrator_split',
            'object' => 'payment_intent',
            'amount' => 400,
            'currency' => 'jpy',
            'status' => 'requires_payment_method',
            'client_secret' => 'cs_test_split',
            'metadata' => [
                'flow' => 'split',
                'order_id' => $order->id,
                'order_currency' => 'JPY',
            ],
        ]));

    $stripeService = new StripePaymentService($stripe);
    $stripeService->createSplitPaymentIntent($order->fresh(), 400);
    $stripeService->markOrderPaidFromIntent($intent);

    $fresh = $order->fresh();
    $payment = OrderPayment::query()
        ->where('customer_order_id', $order->id)
        ->where('reference_no', 'pi_matrix_orchestrator_split')
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->payment_attempt_id)->not->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(400.0)
        ->and($fresh->status->value)->toBe('open')
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(400.0);

    Event::assertDispatchedTimes(OrderPaymentRecorded::class, 1);
    Event::assertNotDispatched(OrderPaid::class);
});

it('produces the same settlement signature for orchestrator workstation cash', function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['workstation'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => false,
            'kiosk' => false,
            'workstation' => true,
            'customer_web' => false,
        ],
    ]);

    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $method = parityMethod('cash', $this->organizationId, $this->branch->id);

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'workstation',
        'idempotency_key' => 'parity-orchestrator-ws',
    ]);

    $fresh = $order->fresh();

    expect($payment->status->value)->toBe('succeeded')
        ->and($fresh->status->value)->toBe('closed')
        ->and((float) $fresh->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('produces the same settlement signature for orchestrator kiosk cash', function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['kiosk'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => false,
            'kiosk' => true,
            'workstation' => false,
            'customer_web' => false,
        ],
    ]);

    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $method = parityMethod('cash', $this->organizationId, $this->branch->id);

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'kiosk',
        'idempotency_key' => 'parity-orchestrator-kiosk',
    ]);

    expect($payment->status->value)->toBe('succeeded')
        ->and((float) $order->fresh()->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('produces the same settlement signature for kiosk pending transfer confirm', function () {
    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 800);
    $method = parityMethod('transfer', $this->organizationId, $this->branch->id);

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 800,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'kiosk',
    ]);

    expect($payment->status->value)->toBe('pending');

    $confirmed = $this->payments->confirm($payment);

    $fresh = $order->fresh();

    expect($confirmed->status->value)->toBe('succeeded')
        ->and($fresh->status->value)->toBe('closed')
        ->and($fresh->closed_at)->not->toBeNull()
        ->and((float) $fresh->paid_amount)->toBe(800.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(800.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertNotDispatched(OrderPaymentRecorded::class);
});

it('produces the same settlement signature for workstation pending transfer confirm', function () {
    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 900);
    $method = parityMethod('transfer', $this->organizationId, $this->branch->id);

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 900,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'workstation',
    ]);

    $confirmed = $this->payments->confirm($payment);
    $fresh = $order->fresh();

    expect($confirmed->status->value)->toBe('succeeded')
        ->and($fresh->status->value)->toBe('closed')
        ->and((float) $fresh->paid_amount)->toBe(900.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(900.0);

    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('keeps paid_amount tied to the ledger projection through a partial then a settling payment', function (string $state) {
    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $method = parityMethod($state, $this->organizationId, $this->branch->id);

    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    // Partial: the order stays open and only the partial signal fires.
    $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 400,
        'tendered_amount' => 400,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    expect((float) $order->fresh()->paid_amount)->toBe(400.0)
        ->and($order->fresh()->status->value)->toBe('paying')
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(400.0);
    Event::assertDispatchedTimes(OrderPaymentRecorded::class, 1);
    Event::assertNotDispatched(OrderPaid::class);

    // Remainder: settlement fires exactly once and closes at the total.
    $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 600,
        'tendered_amount' => 600,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    expect($order->fresh()->status->value)->toBe('closed')
        ->and((float) $order->fresh()->paid_amount)->toBe(1000.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
})->with('settling_rails');

it('conserves money across a partial refund: net and cache both drop by the refund', function (string $state) {
    $order = parityUnpaidOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000);
    $method = parityMethod($state, $this->organizationId, $this->branch->id);

    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    expect(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0);

    // Refund 300 of the settled 1000: the original flips to `refunded` and a
    // -300 succeeded refund row is appended. The projection nets to 700 and the
    // cache follows it — the drift auditor's core invariant.
    $this->payments->refund($payment, ['amount' => 300]);

    expect(OrderPayment::netCollectedForOrder($order->id))->toBe(700.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(700.0);
})->with('settling_rails');
