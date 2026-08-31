<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Enums\TableStatusEnum;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Str;
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
        'total_amount' => 1500,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => null,
    ]);

    $this->makeSignedWebhook = function (string $type, array $dataObject): array {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $dataObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_dummy');

        return [
            'payload' => $payload,
            'header' => "t={$timestamp},v1={$signature}",
        ];
    };
});

// =========================================================================
//  POST /api/v1/customer/orders/{id}/payment-intent
// =========================================================================

it('creates a PaymentIntent and returns client_secret for a valid order', function () {
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('createOrRetrievePaymentIntent')
        ->once()
        ->andReturn([
            'client_secret' => 'pi_test_secret_123',
            'publishable_key' => 'pk_test_dummy',
            'currency' => 'jpy',
            'amount' => 1500,
            'payment_intent_id' => 'pi_test_123',
        ]);
    $this->app->instance(StripePaymentService::class, $mock);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/payment-intent");

    $response->assertOk()
        ->assertJson([
            'data' => [
                'client_secret' => 'pi_test_secret_123',
                'publishable_key' => 'pk_test_dummy',
                'currency' => 'jpy',
                'amount' => 1500,
                'payment_intent_id' => 'pi_test_123',
            ],
        ]);
});

it('returns 404 when order does not exist', function () {
    $this->postJson('/api/v1/customer/orders/00000000-0000-0000-0000-000000000099/payment-intent')
        ->assertNotFound();
});

it('returns 422 when order total_amount is zero', function () {
    $this->order->forceFill(['total_amount' => 0])->save();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/payment-intent")
        ->assertStatus(422)
        ->assertJson(['message' => 'Order amount must be greater than zero.']);
});

it('returns 500 when Stripe service throws', function () {
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('createOrRetrievePaymentIntent')
        ->once()
        ->andThrow(new RuntimeException('Stripe API down'));
    $this->app->instance(StripePaymentService::class, $mock);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/payment-intent")
        ->assertStatus(500)
        ->assertJson(['message' => 'Unable to create payment intent.']);
});

// =========================================================================
//  POST /api/v1/customer/orders/{id}/full-payment-intent
// =========================================================================

it('creates a full PaymentIntent and returns client_secret for a valid order', function () {
    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('createFullPaymentIntent')
        ->once()
        ->andReturn([
            'client_secret' => 'pi_full_secret_123',
            'publishable_key' => 'pk_test_dummy',
            'currency' => 'jpy',
            'amount' => 1500,
            'payment_intent_id' => 'pi_full_123',
        ]);
    $this->app->instance(StripePaymentService::class, $mock);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/full-payment-intent");

    $response->assertOk()
        ->assertJson([
            'data' => [
                'client_secret' => 'pi_full_secret_123',
                'publishable_key' => 'pk_test_dummy',
                'currency' => 'jpy',
                'amount' => 1500,
                'payment_intent_id' => 'pi_full_123',
            ],
        ]);
});

it('returns 404 when full-payment-intent target order does not exist', function () {
    $this->postJson('/api/v1/customer/orders/00000000-0000-0000-0000-000000000099/full-payment-intent')
        ->assertNotFound();
});

it('returns 422 when full-payment-intent target order has zero total', function () {
    $this->order->forceFill(['total_amount' => 0])->save();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/full-payment-intent")
        ->assertStatus(422)
        ->assertJson(['message' => 'Order amount must be greater than zero.']);
});

it('charges only the remaining balance when paid_amount is non-zero', function () {
    // Order has a prior split payment. Full-payment should charge remaining only.
    $this->order->forceFill(['paid_amount' => 600])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->withArgs(function (array $params) {
            // total=1500, paid=600 → remaining=900
            return $params['amount'] === 900
                && $params['metadata']['flow'] === 'full';
        })
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_full_new',
            'object' => 'payment_intent',
            'amount' => 900,
            'currency' => 'jpy',
            'client_secret' => 'pi_full_new_secret',
            'status' => 'requires_payment_method',
        ]));

    $service = new StripePaymentService($stripe);
    $payload = $service->createFullPaymentIntent($this->order);

    expect($payload['amount'])->toBe(900);
    expect($payload['payment_intent_id'])->toBe('pi_full_new');
});

it('cancels a stale pending intent before creating a new full PaymentIntent', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_stale'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_stale')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_stale',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
        ]));
    $stripe->paymentIntents->expects('cancel')->with('pi_stale')->once();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_fresh',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'client_secret' => 'pi_fresh_secret',
            'status' => 'requires_payment_method',
        ]));

    $service = new StripePaymentService($stripe);
    $service->createFullPaymentIntent($this->order);

    expect($this->order->fresh()->stripe_payment_intent_id)->toBe('pi_fresh');
});

it('reuses a matching requires_payment_method intent on retry (#2741 ORD-2026-0244)', function () {
    // Guest clicked Pay five times on one bill: same remaining, same currency,
    // PI still Incomplete with no payment_method. Minting a second intent
    // would leave two Dashboard Incomplete rows; reuse is the contract.
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_reuse'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_reuse')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_reuse',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'client_secret' => 'pi_reuse_secret',
            'status' => 'requires_payment_method',
        ]));
    $stripe->paymentIntents->expects('cancel')->never();
    $stripe->paymentIntents->expects('create')->never();

    $service = new StripePaymentService($stripe);
    $payload = $service->createFullPaymentIntent($this->order);

    expect($payload['payment_intent_id'])->toBe('pi_reuse');
    expect($payload['client_secret'])->toBe('pi_reuse_secret');
    expect($payload['amount'])->toBe(1500);
    expect($this->order->fresh()->stripe_payment_intent_id)->toBe('pi_reuse');
});

it('reuses a requires_action intent (3DS in flight) when amount and currency still match', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_3ds'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_3ds')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_3ds',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'client_secret' => 'pi_3ds_secret',
            'status' => 'requires_action',
        ]));
    $stripe->paymentIntents->expects('cancel')->never();
    $stripe->paymentIntents->expects('create')->never();

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($this->order);

    expect($payload['payment_intent_id'])->toBe('pi_3ds');
});

it('cancels a processing intent instead of reusing it on a new full-pay click', function () {
    // processing is cancelable but NOT in createFullPaymentIntent's reuse list
    // (konbini/bank-transfer in flight). A second Pay must not attach a card
    // to a voucher that is already collecting.
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_processing'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_processing')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_processing',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'status' => 'processing',
        ]));
    $stripe->paymentIntents->expects('cancel')->with('pi_processing')->once();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_fresh_after_processing',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'client_secret' => 'pi_fresh_after_processing_secret',
            'status' => 'requires_payment_method',
        ]));

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($this->order);

    expect($payload['payment_intent_id'])->toBe('pi_fresh_after_processing');
});

it('cancels an Incomplete intent when the remaining amount changed (coupon / split)', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_old_amount'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_old_amount')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_old_amount',
            'object' => 'payment_intent',
            'amount' => 1250,
            'currency' => 'jpy',
            'status' => 'requires_payment_method',
        ]));
    $stripe->paymentIntents->expects('cancel')->with('pi_old_amount')->once();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_new_amount',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'client_secret' => 'pi_new_amount_secret',
            'status' => 'requires_payment_method',
        ]));

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($this->order);

    expect($payload['payment_intent_id'])->toBe('pi_new_amount');
    expect($payload['amount'])->toBe(1500);
});

// =========================================================================
//  POST /api/v1/customer/orders/{id}/confirm-payment
// =========================================================================

it('releases the dine-in table to free + clears current_order_id after full pay', function () {
    $table = Table::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'status' => TableStatusEnum::Occupied->value,
        'current_order_id' => $this->order->id,
        'is_active' => true,
    ]);
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_table_flip'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_table_flip')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_table_flip',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'status' => 'succeeded',
        ]));
    $this->app->instance(StripePaymentService::class, new StripePaymentService($stripe));

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_table_flip',
    ])->assertOk();

    $table->refresh();
    // #432 — payment settles the table straight to `free` (was `cleaning`).
    expect($table->status->value ?? $table->status)->toBe(TableStatusEnum::Free->value);
    expect($table->current_order_id)->toBeNull();
});

it('confirms a succeeded intent and marks the order paid + closed without a webhook', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_sync_ok'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_sync_ok')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_sync_ok',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'status' => 'succeeded',
        ]));
    $this->app->instance(StripePaymentService::class, new StripePaymentService($stripe));

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_sync_ok',
    ])
        ->assertOk()
        ->assertJson(['data' => ['is_fully_paid' => true]]);

    $this->order->refresh();
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect((float) $this->order->paid_amount)->toBe((float) $this->order->total_amount);
    expect(OrderPayment::where('reference_no', 'pi_sync_ok')->count())->toBe(1);
});

it('returns 422 when the intent has not actually succeeded', function () {
    // #2741 ORD-2026-0237 — confirm-payment cannot attach a card. The PI is
    // still requires_payment_method; this endpoint must 422 and leave the
    // order open with no order_payments row.
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_sync_pending'])->save();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_sync_pending')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_sync_pending',
            'object' => 'payment_intent',
            'amount' => 1500,
            'currency' => 'jpy',
            'status' => 'requires_payment_method',
        ]));
    $this->app->instance(StripePaymentService::class, new StripePaymentService($stripe));

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_sync_pending',
    ])->assertStatus(422)->assertJson(['message' => 'Unable to confirm payment.']);

    expect($this->order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
    expect(OrderPayment::where('reference_no', 'pi_sync_pending')->count())->toBe(0);
});

it('returns 404 when confirming against a missing order', function () {
    $this->postJson('/api/v1/customer/orders/00000000-0000-0000-0000-000000000099/confirm-payment', [
        'payment_intent_id' => 'pi_x',
    ])->assertNotFound();
});

it('returns 422 when payment_intent_id is missing', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_intent_id');
});

it('returns 422 when the confirmed intent belongs to a different order', function () {
    // The intent resolves to ANOTHER order — the route order must not be touched.
    $otherOrder = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'total_amount' => 999,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => 'pi_other_order',
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')
        ->with('pi_other_order')
        ->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_other_order',
            'object' => 'payment_intent',
            'amount' => 999,
            'currency' => 'jpy',
            'status' => 'succeeded',
        ]));
    $this->app->instance(StripePaymentService::class, new StripePaymentService($stripe));

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_other_order',
    ])->assertStatus(422)->assertJson(['message' => 'Payment does not match this order.']);

    expect($this->order->fresh()->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
});

it('is idempotent with the webhook — sync confirm then replayed webhook records one payment', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_sync_then_hook'])->save();

    $intentData = [
        'id' => 'pi_sync_then_hook',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('retrieve')->with('pi_sync_then_hook')->once()
        ->andReturn(PaymentIntent::constructFrom($intentData));
    $service = new StripePaymentService($stripe);
    $this->app->instance(StripePaymentService::class, $service);

    // 1) Synchronous confirm from the client.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/confirm-payment", [
        'payment_intent_id' => 'pi_sync_then_hook',
    ])->assertOk();

    // 2) Webhook arrives later for the same intent — must be a no-op.
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom($intentData));

    expect(OrderPayment::where('reference_no', 'pi_sync_then_hook')->count())->toBe(1);
    expect((float) $this->order->fresh()->paid_amount)->toBe((float) $this->order->total_amount);
});

// =========================================================================
//  POST /api/v1/customer/stripe/webhook
// =========================================================================

it('returns 400 when the webhook signature is invalid', function () {
    $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => 'bad-sig', 'CONTENT_TYPE' => 'application/json'],
        '{}',
    )
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid signature.']);
});

it('flips a matching order to closed on payment_intent.succeeded', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_test_webhook_1'])->save();

    $event = ($this->makeSignedWebhook)('payment_intent.succeeded', [
        'id' => 'pi_test_webhook_1',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )
        ->assertOk()
        ->assertJson(['received' => true]);

    $this->order->refresh();
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect((float) $this->order->paid_amount)->toBe((float) $this->order->total_amount);
    expect($this->order->closed_at)->not->toBeNull();
});

it('is idempotent — a replayed webhook keeps the order closed without double-paying', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_test_replay'])->save();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_test_replay',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    $service->markOrderPaidFromIntent($intent);
    $service->markOrderPaidFromIntent($intent);

    $this->order->refresh();
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect((float) $this->order->paid_amount)->toBe((float) $this->order->total_amount);

    // The webhook must record exactly ONE OrderPayment row even when replayed,
    // so the admin reconciliation view doesn't show the same payment twice.
    expect(OrderPayment::where('customer_order_id', $this->order->id)->where('reference_no', 'pi_test_replay')->count())->toBe(1);
});

it('records an OrderPayment with method=stripe + paid_at + total amount on success', function () {
    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_test_record'])->save();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_test_record',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    $service->markOrderPaidFromIntent($intent);

    $payment = OrderPayment::where('customer_order_id', $this->order->id)
        ->where('reference_no', 'pi_test_record')
        ->with('paymentMethod')
        ->first();

    expect($payment)->not->toBeNull();
    expect($payment->paymentMethod->code)->toBe('stripe');
    expect((float) $payment->amount)->toBe((float) $this->order->total_amount);
    expect($payment->status instanceof PaymentStatusEnum ? $payment->status->value : $payment->status)
        ->toBe(PaymentStatusEnum::Succeeded->value);
    expect($payment->paid_at)->not->toBeNull();
    // #2863 — NULL, không phải UUID toàn số 0. Webhook không có người thu và
    // cũng không có thiết bị của quán; một sentinel trông như UUID hợp lệ chỉ
    // làm người đọc tin là có. Xem `PaymentActorAttributionTest`.
    expect($payment->received_by_id)->toBeNull();
});

it('reuses the existing stripe PaymentMethod row across multiple successful intents', function () {
    // Pre-seed a different brand's order to ensure firstOrCreate keys on
    // (code='stripe', branch_id=null) globally — we don't want a separate
    // PaymentMethod row per brand cluttering reports.
    $brand2 = Brand::factory()->create();
    $branch2 = Branch::factory()->create();
    $order2 = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $brand2->id,
        'branch_id' => $branch2->id,
        'total_amount' => 2000,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => 'pi_brand2',
    ]);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => 'pi_test_share_1',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]));

    $this->order->forceFill(['stripe_payment_intent_id' => 'pi_test_share_1'])->save();
    // Re-load and re-run on the first order so the payment is tied to it.
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => 'pi_test_share_1',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]));

    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => 'pi_brand2',
        'object' => 'payment_intent',
        'amount' => 2000,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]));

    expect(PaymentMethod::where('code', 'stripe')->count())->toBe(1);
});

it('acknowledges webhooks for intents without a matching order', function () {
    $event = ($this->makeSignedWebhook)('payment_intent.succeeded', [
        'id' => 'pi_unknown',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )
        ->assertOk()
        ->assertJson(['received' => true]);
});

it('ignores non-succeeded event types', function () {
    $event = ($this->makeSignedWebhook)('payment_intent.created', [
        'id' => 'pi_x',
        'object' => 'payment_intent',
    ]);

    $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    )
        ->assertOk()
        ->assertJson(['received' => true]);
});

// =========================================================================
//  GET /api/v1/customer/stripe/config
// =========================================================================

it('returns only the publishable key from server config (#815 — no global currency)', function () {
    config()->set('services.stripe.key', 'pk_test_from_server');

    $this->getJson('/api/v1/customer/stripe/config')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'publishable_key' => 'pk_test_from_server',
            ],
        ]);
});
