<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;

beforeEach(function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['pos'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => true,
            'kiosk' => false,
            'workstation' => false,
            'customer_web' => false,
        ],
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
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    $this->operator = User::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'type' => 'cash',
        'is_active' => true,
    ]);
});

it('routes eligible pos cash create through the orchestrator when runtime flag is on', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 800,
        'paid_amount' => 0,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 800,
        'tendered_amount' => 800,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'compat-pos-cash-1',
    ]);

    expect($payment->status->value)->toBe('succeeded')
        ->and($payment->idempotency_key)->toBe('compat-pos-cash-1')
        ->and((float) $order->fresh()->paid_amount)->toBe(800.0);
});

it('keeps legacy ledger path when orchestrator runtime is disabled', function () {
    config(['payments.orchestrator_runtime.enabled' => false]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 500,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 500,
        'tendered_amount' => 500,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
    ]);

    expect($payment->status->value)->toBe('succeeded')
        ->and((float) $order->fresh()->paid_amount)->toBe(500.0);
});

it('routes eligible kiosk cash create through the orchestrator when runtime flag is on', function () {
    config([
        'payments.orchestrator_runtime.transports' => ['kiosk'],
        'payments.orchestrator_runtime.transport_switches.kiosk' => true,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 1200,
        'paid_amount' => 0,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 1200,
        'tendered_amount' => 1200,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'kiosk',
        'idempotency_key' => 'compat-kiosk-cash-1',
    ]);

    expect($payment->status->value)->toBe('succeeded')
        ->and($payment->idempotency_key)->toBe('compat-kiosk-cash-1')
        ->and((float) $order->fresh()->paid_amount)->toBe(1200.0);
});

it('persists orchestrator transport on the payment channel column for pending creates', function () {
    config(['payments.orchestrator_runtime.enabled' => false]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 500,
    ]);

    $card = PaymentMethod::factory()->card()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $card->id,
        'amount' => 500,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'kiosk',
    ]);

    // The transport lands on the server-owned `channel` column, not inside the
    // caller-owned metadata blob (#1058/#1059).
    expect($payment->status->value)->toBe('pending')
        ->and($payment->channel)->toBe('kiosk')
        ->and($payment->metadata)->toBeNull();
});

it('rejects orchestrator auto-confirm create when the payment method is inactive', function () {
    $this->cash->update(['is_active' => false]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 800,
    ]);

    expect(fn () => app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 800,
        'tendered_amount' => 800,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
    ]))->toThrow(HttpResponseException::class);
});

it('finalizes a linked payment attempt when confirming through the compat hook', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 600,
        'paid_amount' => 0,
    ]);

    $transfer = PaymentMethod::factory()->transfer()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $transfer->id,
        'amount' => 600,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
    ]);

    $attempt = PaymentAttempt::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'customer_order_id' => $order->id,
        'state' => 'prepared',
        'version' => 1,
        'amount_minor' => 600,
        'currency' => 'JPY',
    ]);

    $payment->update(['payment_attempt_id' => $attempt->id]);

    $confirmed = app(OrderPaymentService::class)->confirm($payment->fresh());

    expect($confirmed->status->value)->toBe('succeeded')
        ->and($attempt->fresh()->state->value)->toBe('succeeded');
});

it('finalizes a linked payment attempt when failing through the compat hook', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 400,
    ]);

    $transfer = PaymentMethod::factory()->transfer()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $transfer->id,
        'amount' => 400,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
    ]);

    $attempt = PaymentAttempt::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'customer_order_id' => $order->id,
        'state' => 'prepared',
        'version' => 1,
        'amount_minor' => 400,
        'currency' => 'JPY',
    ]);

    $payment->update(['payment_attempt_id' => $attempt->id]);

    $failed = app(OrderPaymentService::class)->fail($payment->fresh());

    expect($failed->status->value)->toBe('failed')
        ->and($attempt->fresh()->state->value)->toBe('failed');
});

it('prepares a customer_web stripe attempt when orchestrator runtime is enabled', function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['customer_web'],
        'payments.orchestrator_runtime.transport_switches.customer_web' => true,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 1500,
        'paid_amount' => 0,
    ]);

    $attemptId = app(OrderPaymentOrchestrationCompat::class)->prepareStripePaymentIntent(
        $order,
        'pi_compat_prepare_full',
        1500,
        'JPY',
        'full',
    );

    expect($attemptId)->not->toBeNull();

    $attempt = PaymentAttempt::query()->find($attemptId);

    expect($attempt)->not->toBeNull()
        ->and($attempt->provider_object_id)->toBe('pi_compat_prepare_full')
        ->and($attempt->channel)->toBe(PaymentChannelEnum::CustomerWeb)
        ->and($attempt->state)->toBe(PaymentAttemptStateEnum::Prepared)
        ->and((int) $attempt->amount_minor)->toBe(1500);

    // Regression (staging FK-1452 "Unable to create payment intent"): the
    // attempt's connection_option_id must reference the org-scoped
    // payment_gateway_connection_options join row — NOT the catalog
    // payment_gateway_options id. The bug wrote the catalog id, which only
    // survived the sqlite test DB because it does not enforce foreign keys;
    // on MySQL it raised a 1452 FK violation and 500'd every customer-web
    // Stripe checkout.
    expect(PaymentGatewayConnectionOption::query()->whereKey($attempt->connection_option_id)->exists())->toBeTrue()
        ->and(PaymentGatewayOption::query()->whereKey($attempt->connection_option_id)->exists())->toBeFalse();
});

it('finalizes a prepared customer_web stripe attempt on sync confirm path', function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['customer_web'],
        'payments.orchestrator_runtime.transport_switches.customer_web' => true,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'open',
        'total_amount' => 900,
        'paid_amount' => 0,
        'stripe_payment_intent_id' => 'pi_compat_finalize_full',
    ]);

    $attemptId = app(OrderPaymentOrchestrationCompat::class)->prepareStripePaymentIntent(
        $order,
        'pi_compat_finalize_full',
        900,
        'JPY',
        'full',
    );

    app(OrderPaymentService::class)->recordStripeWebhookPayment(
        $order,
        'pi_compat_finalize_full',
        900,
        'full',
        ['flow' => 'full'],
        $attemptId,
    );

    $attempt = PaymentAttempt::query()->findOrFail($attemptId);

    app(OrderPaymentOrchestrationCompat::class)->finalizeStripePayment(
        $attempt,
        PaymentIntent::constructFrom([
            'id' => 'pi_compat_finalize_full',
            'object' => 'payment_intent',
            'amount' => 900,
            'currency' => 'jpy',
            'status' => 'succeeded',
        ]),
    );

    expect($attempt->fresh()?->state)->toBe(PaymentAttemptStateEnum::Succeeded);
});

// #1059 — a client-supplied channel must NOT steer the transport routing. The
// server sets it; the client cannot override the persisted, routing-authoritative
// value by smuggling metadata.channel.
it('ignores a client-supplied metadata.channel and keeps the server transport', function () {
    config(['payments.orchestrator_runtime.enabled' => false]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 500,
    ]);
    $card = PaymentMethod::factory()->card()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $card->id,
        'amount' => 500,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
        // Attacker tries to masquerade as a different channel.
        'metadata' => ['channel' => 'workstation'],
    ]);

    // The persisted, routing-authoritative channel is the server's, and
    // resolveTransportFromPayment reads the column, not the smuggled value.
    expect($payment->channel)->toBe('pos');
    expect(app(OrderPaymentOrchestrationCompat::class)->resolveTransportFromPayment($payment))->toBe('pos');
});
