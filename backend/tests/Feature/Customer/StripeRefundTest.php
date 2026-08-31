<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Str;
use Stripe\Charge;
use Stripe\Refund;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * #548 — Stripe refund wired end-to-end.
 *
 * Covers BOTH directions the issue flags:
 *   chiều A — in-app refund of a Stripe card payment now calls the Refunds
 *             API (money reversed) AND writes the ledger row.
 *   chiều B — a dashboard-initiated refund arrives via the charge.refunded
 *             webhook and syncs the ledger.
 * Plus the idempotency guard that keeps the two paths from double-counting.
 */
beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
        'services.stripe.currency' => 'jpy',
        // BLOCKER 2 — these tests exercise the live-refund money path, so the
        // kill-switch must be ON. Its default-OFF behaviour is covered in
        // RefundGateTest.
        'payments.stripe_live_refunds_enabled' => true,
    ]);

    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->customer = Customer::factory()->selfRegistered()->create();

    $this->order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 1500,
        'paid_amount' => 1500,
        'status' => 'closed',
    ]);

    $this->stripeMethod = PaymentMethod::factory()->create([
        'code' => 'stripe',
        'organization_id' => $this->orgId,
        'branch_id' => null,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    // A succeeded Stripe payment recorded against the order (reference_no is
    // the pi_ PaymentIntent id, exactly as recordStripePayment() stamps it).
    $this->stripePayment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->stripeMethod->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'amount' => 1500,
        'reference_no' => 'pi_test_548',
    ]);

    // Signed webhook helpers (mirrors StripeWebhookTest).
    $this->makeStripeEvent = function (string $type, array $dataObject, string $secret = 'whsec_test_secret_xyz'): array {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $dataObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return ['payload' => $payload, 'header' => "t={$timestamp},v1={$signature}"];
    };
    $this->postWebhook = fn (string $payload, string $signature) => $this->call(
        'POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $this->chargeRefundedEvent = function (string $paymentIntent, array $refunds, string $currency = 'jpy'): array {
        return ($this->makeStripeEvent)('charge.refunded', [
            'object' => 'charge',
            'id' => 'ch_'.Str::random(16),
            'payment_intent' => $paymentIntent,
            'currency' => $currency,
            'refunds' => ['object' => 'list', 'data' => $refunds],
        ]);
    };
});

// =============================================================================
// StripePaymentService::refundPayment — money math + idempotency key
// =============================================================================

it('calls the Stripe Refunds API with EXACT minor units for a zero-decimal currency', function () {
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->expects('create')
        ->once()
        ->withArgs(function (array $params, array $opts) {
            // JPY is zero-decimal: 1500 major → 1500 minor (NO ×100).
            return $params['payment_intent'] === 'pi_test_548'
                && $params['amount'] === 1500
                && str_starts_with($opts['idempotency_key'], 'refund_');
        })
        ->andReturn(Refund::constructFrom(['id' => 're_1', 'object' => 'refund', 'amount' => 1500, 'status' => 'succeeded']));

    $service = new StripePaymentService($stripe);
    // #815 — refundPayment now takes the charged currency explicitly (callers that
    // hold the intent pass its currency; here we exercise the zero-decimal path).
    $refund = $service->refundPayment('pi_test_548', 1500.0, (string) Str::uuid(), 'jpy');

    expect($refund->id)->toBe('re_1');
});

it('scales a two-decimal currency by 100 for the Stripe refund amount', function () {
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->expects('create')
        ->once()
        ->withArgs(fn (array $params, array $opts) => $params['amount'] === 1299) // 12.99 → 1299
        ->andReturn(Refund::constructFrom(['id' => 're_usd', 'object' => 'refund']));

    // #815 — currency passed explicitly (was config('services.stripe.currency')).
    (new StripePaymentService($stripe))->refundPayment('pi_x', 12.99, 'k1', 'usd');
});

it('rejects a non-positive refund amount before hitting Stripe', function () {
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->shouldNotReceive('create');

    // #815 — pass currency so the guard trips before any Stripe round-trip.
    expect(fn () => (new StripePaymentService($stripe))->refundPayment('pi_x', 0.0, 'k', 'jpy'))
        ->toThrow(RuntimeException::class);
});

// =============================================================================
// StripePaymentService::extractRefunds — webhook charge parsing (pure)
// =============================================================================

it('normalizes charge.refunds into ledger-ready rows with EXACT amounts', function () {
    $charge = Charge::constructFrom([
        'object' => 'charge',
        'payment_intent' => 'pi_test_548',
        'currency' => 'jpy',
        'refunds' => ['object' => 'list', 'data' => [
            ['id' => 're_a', 'object' => 'refund', 'amount' => 1500, 'currency' => 'jpy', 'status' => 'succeeded'],
        ]],
    ]);

    $rows = (new StripePaymentService(Mockery::mock(StripeClient::class)))->extractRefunds($charge);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['payment_intent'])->toBe('pi_test_548')
        ->and($rows[0]['stripe_refund_id'])->toBe('re_a')
        ->and($rows[0]['amount'])->toBe(1500.0);
});

it('skips non-succeeded refunds when extracting from a charge', function () {
    $charge = Charge::constructFrom([
        'object' => 'charge',
        'payment_intent' => 'pi_x',
        'currency' => 'jpy',
        'refunds' => ['object' => 'list', 'data' => [
            ['id' => 're_pending', 'object' => 'refund', 'amount' => 500, 'currency' => 'jpy', 'status' => 'pending'],
        ]],
    ]);

    expect((new StripePaymentService(Mockery::mock(StripeClient::class)))->extractRefunds($charge))->toBe([]);
});

// =============================================================================
// OrderPaymentService::refund — in-app path (chiều A)
// =============================================================================

it('refunds a Stripe payment by calling the Refunds API and stamping the refund id', function () {
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('refundPayment')
        ->once()
        ->withArgs(fn (string $pi, float $amount, string $key) => $pi === 'pi_test_548' && $amount === 1500.0)
        ->andReturn(Refund::constructFrom(['id' => 're_inapp', 'object' => 'refund', 'amount' => 1500]));
    $this->app->instance(StripePaymentService::class, $mock);

    $refund = app(OrderPaymentService::class)->refund($this->stripePayment, []);

    expect((float) $refund->amount)->toBe(-1500.0)
        ->and($refund->metadata['stripe_refund_id'])->toBe('re_inapp')
        ->and($refund->refund_of_id)->toBe($this->stripePayment->id);

    // Original flips to refunded; ledger nets to zero.
    expect($this->stripePayment->fresh()->status)->toBe(PaymentStatusEnum::Refunded)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('does NOT call Stripe when refunding a cash payment', function () {
    $cashPayment = OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $this->cashMethod->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'amount' => 1500,
        'reference_no' => null,
    ]);

    // Any resolution of StripePaymentService would blow up this mock.
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->shouldNotReceive('refundPayment');
    $this->app->instance(StripePaymentService::class, $mock);

    $refund = app(OrderPaymentService::class)->refund($cashPayment, []);

    expect((float) $refund->amount)->toBe(-1500.0)
        ->and($refund->metadata)->toBeNull();
});

it('rolls back the ledger when the Stripe refund call fails — no phantom refunded row', function () {
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('refundPayment')->once()->andThrow(new RuntimeException('Stripe down'));
    $this->app->instance(StripePaymentService::class, $mock);

    expect(fn () => app(OrderPaymentService::class)->refund($this->stripePayment, []))
        ->toThrow(RuntimeException::class);

    // Original untouched, no negative row written.
    expect($this->stripePayment->fresh()->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and(OrderPayment::where('refund_of_id', $this->stripePayment->id)->count())->toBe(0)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(1500.0);
});

it('a second in-app refund of an already-refunded Stripe payment 409s without calling Stripe again', function () {
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('refundPayment')->once()
        ->andReturn(Refund::constructFrom(['id' => 're_once', 'object' => 'refund', 'amount' => 1500]));
    $this->app->instance(StripePaymentService::class, $mock);

    app(OrderPaymentService::class)->refund($this->stripePayment, []);

    // Second attempt: status is now 'refunded' → 409, Stripe NOT called twice.
    expect(fn () => app(OrderPaymentService::class)->refund($this->stripePayment->fresh(), []))
        ->toThrow(HttpException::class);
});

// =============================================================================
// charge.refunded webhook — dashboard sync (chiều B) + idempotency
// =============================================================================

it('syncs a dashboard-initiated refund into the ledger on charge.refunded', function () {
    $event = ($this->chargeRefundedEvent)('pi_test_548', [
        ['id' => 're_dash', 'object' => 'refund', 'amount' => 1500, 'currency' => 'jpy', 'status' => 'succeeded'],
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    $refund = OrderPayment::where('refund_of_id', $this->stripePayment->id)->first();
    expect($refund)->not->toBeNull()
        ->and((float) $refund->amount)->toBe(-1500.0)
        ->and($refund->metadata['stripe_refund_id'])->toBe('re_dash');

    expect($this->stripePayment->fresh()->status)->toBe(PaymentStatusEnum::Refunded)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('is idempotent — replaying charge.refunded writes exactly one ledger row', function () {
    $event = ($this->chargeRefundedEvent)('pi_test_548', [
        ['id' => 're_replay', 'object' => 'refund', 'amount' => 1500, 'currency' => 'jpy', 'status' => 'succeeded'],
    ]);

    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(OrderPayment::where('refund_of_id', $this->stripePayment->id)->count())->toBe(1)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('does not double-count when the in-app refund is followed by its charge.refunded webhook', function () {
    // chiều A first: in-app refund stamps stripe_refund_id = re_shared.
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('refundPayment')->once()
        ->andReturn(Refund::constructFrom(['id' => 're_shared', 'object' => 'refund', 'amount' => 1500]));
    $this->app->instance(StripePaymentService::class, $mock);
    app(OrderPaymentService::class)->refund($this->stripePayment, []);

    // Now Stripe fires charge.refunded for the SAME refund id → must no-op.
    $this->app->forgetInstance(StripePaymentService::class); // webhook uses the real (pure) parser
    $event = ($this->chargeRefundedEvent)('pi_test_548', [
        ['id' => 're_shared', 'object' => 'refund', 'amount' => 1500, 'currency' => 'jpy', 'status' => 'succeeded'],
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(OrderPayment::where('refund_of_id', $this->stripePayment->id)->count())->toBe(1)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('caps a webhook refund at the still-refundable balance', function () {
    // Prior partial in-app refund of 1000 already recorded.
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('refundPayment')->once()
        ->andReturn(Refund::constructFrom(['id' => 're_partial', 'object' => 'refund', 'amount' => 1000]));
    $this->app->instance(StripePaymentService::class, $mock);
    app(OrderPaymentService::class)->refund($this->stripePayment, ['amount' => 1000]);
    $this->app->forgetInstance(StripePaymentService::class);

    // Dashboard then refunds the remaining 500 (distinct refund id).
    $event = ($this->chargeRefundedEvent)('pi_test_548', [
        ['id' => 're_remainder', 'object' => 'refund', 'amount' => 500, 'currency' => 'jpy', 'status' => 'succeeded'],
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    $refunded = (float) OrderPayment::where('refund_of_id', $this->stripePayment->id)->sum('amount');
    expect($refunded)->toBe(-1500.0) // -1000 + -500, never past the original 1500
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0);
});

it('no-ops charge.refunded when no original payment matches the intent', function () {
    $before = OrderPayment::count();

    $event = ($this->chargeRefundedEvent)('pi_unknown_intent', [
        ['id' => 're_orphan', 'object' => 'refund', 'amount' => 100, 'currency' => 'jpy', 'status' => 'succeeded'],
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(OrderPayment::count())->toBe($before);
});

// =============================================================================
// BLOCKER — double real refund guards
// =============================================================================

it('passes a STABLE idempotency key derived from the payment id — a retry reuses the same key, never a fresh uuid', function () {
    // The blocker: a random Str::uuid() key per call means that when a
    // post-charge step rolls back and the caller/queue retries the refund, the
    // second call fires a SECOND real card refund. A key derived from the
    // payment id makes the retry hit Stripe's same-refund dedupe instead.
    $keys = [];
    $call = 0;

    $mock = Mockery::mock(StripePaymentService::class);
    $mock->shouldReceive('refundPayment')
        ->twice()
        ->withArgs(function (string $pi, float $amount, string $key) use (&$keys) {
            $keys[] = $key;

            return $pi === 'pi_test_548' && $amount === 1500.0;
        })
        ->andReturnUsing(function () use (&$call) {
            $call++;
            // First attempt: a post-charge step fails → the whole refund()
            // transaction rolls back, leaving the original still 'succeeded'
            // so the caller is allowed to retry.
            if ($call === 1) {
                throw new RuntimeException('post-charge rollback');
            }

            return Refund::constructFrom(['id' => 're_retry', 'object' => 'refund', 'amount' => 1500]);
        });
    $this->app->instance(StripePaymentService::class, $mock);

    $service = app(OrderPaymentService::class);

    // Attempt 1 rolls back after the (mocked) charge call.
    expect(fn () => $service->refund($this->stripePayment, []))->toThrow(RuntimeException::class);
    expect($this->stripePayment->fresh()->status)->toBe(PaymentStatusEnum::Succeeded);

    // Attempt 2 (the retry) succeeds and lands exactly one ledger row.
    $service->refund($this->stripePayment->fresh(), []);

    // The key must be derived from the payment id AND identical across both
    // calls — NOT a random uuid that would double-charge the card.
    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe('refund_'.$this->stripePayment->id)
        ->and($keys[1])->toBe($keys[0])
        ->and(OrderPayment::where('refund_of_id', $this->stripePayment->id)->count())->toBe(1);
});

it('syncStripeRefund is idempotent by stripe_refund_id — two calls create exactly one negative ledger row', function () {
    $service = app(OrderPaymentService::class);

    $first = $service->syncStripeRefund('pi_test_548', 're_dup', 1500.0);
    $second = $service->syncStripeRefund('pi_test_548', 're_dup', 1500.0);

    // Second call returns the SAME row, never a second negative credit.
    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($second->id)->toBe($first->id)
        ->and(OrderPayment::where('refund_of_id', $this->stripePayment->id)->count())->toBe(1)
        ->and(OrderPayment::where('metadata->stripe_refund_id', 're_dup')->count())->toBe(1)
        ->and((float) $this->order->fresh()->paid_amount)->toBe(0.0)
        ->and($this->stripePayment->fresh()->status)->toBe(PaymentStatusEnum::Refunded);
});
