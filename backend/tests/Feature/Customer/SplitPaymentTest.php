<?php

use App\Events\OrderPaid;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

beforeEach(function () {
    config()->set('services.stripe.key', 'pk_test_dummy');
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');
    config()->set('services.stripe.currency', 'jpy');

    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'total_amount' => 3000,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => null,
    ]);

    // Mock Stripe service for all controller-level tests to avoid real API calls.
    // Tests that directly test the service construct their own mock.
    $this->stripeMock = Mockery::mock(StripePaymentService::class);
    $this->app->instance(StripePaymentService::class, $this->stripeMock);
});

// =========================================================================
//  POST /api/v1/customer/orders/{id}/split-payment-intent  (controller)
// =========================================================================

it('creates a split PaymentIntent for a valid partial amount', function () {
    $this->stripeMock->expects('createSplitPaymentIntentUnderLock')
        ->once()
        ->andReturn([
            'client_secret' => 'pi_split_secret_123',
            'publishable_key' => 'pk_test_dummy',
            'currency' => 'jpy',
            'amount' => 1000,
            'payment_intent_id' => 'pi_split_123',
        ]);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 1000,
    ])
        ->assertOk()
        ->assertJson([
            'data' => [
                'client_secret' => 'pi_split_secret_123',
                'amount' => 1000,
                'payment_intent_id' => 'pi_split_123',
            ],
        ]);
});

// #555 M10 — the per-attempt client key must reach the Stripe service so a
// retry after a poll timeout returns the SAME PaymentIntent (Stripe-level
// dedupe) instead of minting a second real charge.
it('threads the idempotency_key through to the split intent service', function () {
    $this->stripeMock->expects('createSplitPaymentIntentUnderLock')
        ->once()
        ->withArgs(fn ($orderId, $amount, $splitCount, $splitType, $itemAllocations, $idempotencyKey) => $idempotencyKey === 'attempt-abc-123')
        ->andReturn([
            'client_secret' => 'pi_split_secret_123',
            'publishable_key' => 'pk_test_dummy',
            'currency' => 'jpy',
            'amount' => 1000,
            'payment_intent_id' => 'pi_split_123',
        ]);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 1000,
        'idempotency_key' => 'attempt-abc-123',
    ])->assertOk();
});

it('rejects an over-long idempotency_key', function () {
    $this->stripeMock->shouldNotReceive('createSplitPaymentIntentUnderLock');

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 1000,
        'idempotency_key' => str_repeat('x', 65),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['idempotency_key']);
});

/**
 * #1666 — the remaining-balance guard moved from the controller into
 * `createSplitPaymentIntentUnderLock`, so this drives the REAL service. Kept at
 * the service level ON PURPOSE: the controller-level version would have to mock
 * the very method that holds the guard, and a mocked guard proves nothing.
 */
it('rejects split payment when amount exceeds remaining balance', function () {
    $this->order->forceFill(['paid_amount' => 2500])->save();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    expect(fn () => $service->createSplitPaymentIntentUnderLock((string) $this->order->id, 1000.0))
        ->toThrow(ValidationException::class);

    // Không có ý định gọi Stripe khi guard đã chặn.
    expect($this->order->fresh()->paid_amount)->toEqual(2500);
});

it('rejects split payment when amount is zero', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 0,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('rejects split payment when amount is negative', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => -100,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('rejects split payment when order is closed', function () {
    $this->order->forceFill(['status' => CustomerOrderStatusEnum::Closed->value])->save();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 1000,
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Order is already closed or voided.']);
});

it('rejects split payment when order is voided', function () {
    $this->order->forceFill(['status' => CustomerOrderStatusEnum::Voided->value])->save();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 1000,
    ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Order is already closed or voided.']);
});

it('returns 404 when split-payment-intent target order does not exist', function () {
    $this->postJson('/api/v1/customer/orders/00000000-0000-0000-0000-000000000099/split-payment-intent', [
        'amount' => 1000,
    ])
        ->assertNotFound();
});

it('rejects full-payment-intent when order is closed', function () {
    $this->order->forceFill(['status' => CustomerOrderStatusEnum::Closed->value])->save();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/full-payment-intent")
        ->assertStatus(422)
        ->assertJson(['message' => 'Order is already closed or voided.']);
});

// =========================================================================
//  Split webhook flow — StripePaymentService::handleSplitPaymentWebhook
// =========================================================================

it('increments paid_amount on split webhook without closing order', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_split_webhook_1',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    $service->markOrderPaidFromIntent($intent);

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(1000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
    expect($this->order->closed_at)->toBeNull();
});

it('closes order when split payments sum to total_amount', function () {
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $this->order->id,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 2000,
        'reference_no' => 'pi_split_prior',
    ]);
    $this->order->forceFill(['paid_amount' => 2000])->save();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_split_final',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    $service->markOrderPaidFromIntent($intent);

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(3000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect($this->order->closed_at)->not->toBeNull();
});

it('broadcasts OrderPaid only when the final split slice closes the order', function () {
    Event::fake([OrderPaid::class]);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $slice = fn (string $id, int $amount) => PaymentIntent::constructFrom([
        'id' => $id,
        'object' => 'payment_intent',
        'amount' => $amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    // First slice leaves a balance → no "payment success" yet.
    $service->markOrderPaidFromIntent($slice('pi_split_a', 2000));
    Event::assertNotDispatched(OrderPaid::class);

    // Final slice lands the order at fully-paid → broadcast exactly once.
    $service->markOrderPaidFromIntent($slice('pi_split_b', 1000));
    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e) => $e->order->id === $this->order->id);
});

it('records an OrderPayment row with the split amount', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_split_record',
        'object' => 'payment_intent',
        'amount' => 1200,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    $service->markOrderPaidFromIntent($intent);

    $payment = OrderPayment::where('customer_order_id', $this->order->id)
        ->where('reference_no', 'pi_split_record')
        ->first();

    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(1200.0);
    expect($payment->status instanceof PaymentStatusEnum ? $payment->status->value : $payment->status)
        ->toBe(PaymentStatusEnum::Succeeded->value);
});

it('expands by_items compact allocations from intent metadata into OrderPayment.metadata', function () {
    // createSplitPaymentIntent stamps allocations as a COMPACT pair string
    // `[["<item_id>", units], ...]` (Stripe metadata is string-only, 500-char
    // cap). recordStripePayment must expand them back to the {item_id, units}
    // shape formatOrder() aggregates into each item's paid_quantity — without
    // this round-trip, online "chia theo món" payments record only an amount
    // and the paid items never disable in the customer-web bill.
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_split_by_items',
        'object' => 'payment_intent',
        'amount' => 1200,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'split_mode' => 'by_items',
            'item_allocations' => json_encode([['item-aaa', 2], ['item-bbb', 1]]),
        ],
    ]);

    $service->markOrderPaidFromIntent($intent);

    $payment = OrderPayment::where('reference_no', 'pi_split_by_items')->first();

    expect($payment)->not->toBeNull();
    $meta = $payment->metadata;
    expect($meta['split_mode'])->toBe('by_items');
    expect($meta['item_allocations'])->toBe([
        ['item_id' => 'item-aaa', 'units' => 2],
        ['item_id' => 'item-bbb', 'units' => 1],
    ]);
});

it('drops malformed by_items allocations instead of poisoning OrderPayment.metadata', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_split_bad_alloc',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'split_mode' => 'by_items',
            'item_allocations' => 'not-json',
        ],
    ]);

    $service->markOrderPaidFromIntent($intent);

    $payment = OrderPayment::where('reference_no', 'pi_split_bad_alloc')->first();
    expect($payment)->not->toBeNull();
    $meta = $payment->metadata ?? [];
    expect($meta['item_allocations'] ?? null)->toBeNull();
    expect($meta['split_mode'] ?? null)->toBeNull();
});

it('forwards item_allocations from the controller to the Stripe service', function () {
    $this->stripeMock->expects('createSplitPaymentIntentUnderLock')
        ->once()
        ->withArgs(function ($orderId, $amount, $splitCount, $splitType, $itemAllocations) {
            return $splitType === 'by_items'
                && $itemAllocations === [
                    ['item_id' => 'it-1', 'units' => 2],
                    ['item_id' => 'it-2', 'units' => 1],
                ];
        })
        ->andReturn([
            'client_secret' => 'pi_x',
            'publishable_key' => 'pk_test_dummy',
            'currency' => 'jpy',
            'amount' => 1200,
            'payment_intent_id' => 'pi_x',
        ]);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 1200,
        'split_type' => 'by_items',
        'item_allocations' => [
            ['item_id' => 'it-1', 'units' => 2],
            ['item_id' => 'it-2', 'units' => 1],
        ],
    ])->assertOk();
});

it('is idempotent for split webhooks — replayed event does not double-count', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_split_replay',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    $service->markOrderPaidFromIntent($intent);
    $service->markOrderPaidFromIntent($intent); // replay

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(1000.0);
    expect(OrderPayment::where('reference_no', 'pi_split_replay')->count())->toBe(1);
});

it('handles multiple split payments correctly', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent1 = PaymentIntent::constructFrom([
        'id' => 'pi_split_a',
        'object' => 'payment_intent',
        'amount' => 1000,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    $intent2 = PaymentIntent::constructFrom([
        'id' => 'pi_split_b',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    $service->markOrderPaidFromIntent($intent1);
    $service->markOrderPaidFromIntent($intent2);

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(2500.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
    expect(OrderPayment::where('customer_order_id', $this->order->id)->count())->toBe(2);
});

// =========================================================================
//  formatOrder includes split bill fields
// =========================================================================

it('order show endpoint returns remaining and payment fields', function () {
    $this->order->forceFill(['paid_amount' => 1000])->save();

    $this->getJson("/api/v1/customer/orders/{$this->order->id}")
        ->assertOk()
        ->assertJsonPath('data.total', 3000)
        ->assertJsonPath('data.paid', 1000)
        ->assertJsonPath('data.remaining', 2000)
        ->assertJsonPath('data.is_fully_paid', false)
        ->assertJsonPath('data.payment_count', 0)
        ->assertJsonPath('data.payments', []);
});

// =========================================================================
//  Split-even flow (chia đều): first payment locks split_count for all
// =========================================================================

it('first payment in split-even mode records split_count in metadata', function () {
    $stripeMock = Mockery::mock(StripeClient::class);
    $stripeMock->paymentIntents = Mockery::mock();
    $stripeMock->paymentIntents->expects('create')
        ->once()
        ->withArgs(function (array $params) {
            // Check metadata has split_count + split_type + amount_per_person
            // #1292 — `(string) $amount` on an int 750 is '750', not '750.0'.
            // The old expectation was written before the code landed and was
            // never run (the test was skipped), so nothing caught the guess.
            return $params['metadata']['split_count'] === '4'
                && $params['metadata']['split_type'] === 'even'
                && $params['metadata']['amount_per_person'] === '750';
        })
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_split_even_1',
            'object' => 'payment_intent',
            'amount' => 750,
            'currency' => 'jpy',
            'client_secret' => 'pi_split_even_1_secret',
            'status' => 'requires_payment_method',
        ]));

    $service = new StripePaymentService($stripeMock);
    $this->app->instance(StripePaymentService::class, $service);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-payment-intent", [
        'amount' => 750,
        'split_count' => 4,
        'split_type' => 'even',
    ])
        ->assertOk()
        ->assertJsonPath('data.payment_intent_id', 'pi_split_even_1');
});

it('split-status API returns correct count + progress after first payment', function () {
    // Giả lập payment 1 đã chọn 4 người và đã thanh toán
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $intent1 = PaymentIntent::constructFrom([
        'id' => 'pi_split_even_status_1',
        'object' => 'payment_intent',
        'amount' => 750,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'split_count' => '4',
            'split_type' => 'even',
            'amount_per_person' => '750.0',
        ],
    ]);
    $service->markOrderPaidFromIntent($intent1);

    // Gọi /split-status
    $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status")
        ->assertOk()
        ->assertJsonPath('data.split_count', 4)
        // #1292 — the controller DOES cast to float; json_encode writes a
        // float 750.0 as `750`, so the decoded value is an int. Asserting
        // 750.0 compares strictly against that and fails on the type alone.
        ->assertJsonPath('data.amount_per_person', 750)
        ->assertJsonPath('data.paid_count', 1)
        ->assertJsonPath('data.is_first_payment', false); // Không phải người đầu nữa
});

it('split-status returns is_first_payment=true when no payments yet', function () {
    $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status")
        ->assertOk()
        ->assertJsonPath('data.split_count', null)
        ->assertJsonPath('data.amount_per_person', null)
        ->assertJsonPath('data.paid_count', 0)
        ->assertJsonPath('data.is_first_payment', true);
});

it('split-status returns 404 for missing order', function () {
    $this->getJson('/api/v1/customer/orders/00000000-0000-0000-0000-000000000099/split-status')
        ->assertNotFound();
});

it('order show returns is_fully_paid=true when fully paid', function () {
    $this->order->forceFill(['paid_amount' => 3000])->save();

    $this->getJson("/api/v1/customer/orders/{$this->order->id}")
        ->assertOk()
        ->assertJsonPath('data.remaining', 0)
        ->assertJsonPath('data.is_fully_paid', true);
});
