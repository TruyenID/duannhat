<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\StripePaymentService;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * #815 — Stripe must charge in the branch's PRICED currency
 * (shop_order_settings.currency_code), never the global config currency.
 */
beforeEach(function () {
    config()->set('services.stripe.key', 'pk_test_dummy');
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');
    config()->set('services.stripe.currency', 'jpy');
    config()->set('payments.stripe_live_refunds_enabled', false);

    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->setBranchCurrency = function (string $code): void {
        ShopOrderSetting::create([
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'currency_code' => $code,
        ]);
    };

    $this->makeOrder = function (float $total, float $paid = 0, ?string $intentId = null): CustomerOrder {
        return CustomerOrder::factory()->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'status' => CustomerOrderStatusEnum::Open->value,
            'stripe_payment_intent_id' => $intentId,
        ]);
    };
});

// =============================================================================
//  Intent creation — charge currency = branch priced currency
// =============================================================================

it('F1 — creates a VND intent for a VND branch (no more ~160x jpy over-charge)', function () {
    ($this->setBranchCurrency)('VND');
    $order = ($this->makeOrder)(250000);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->withArgs(fn (array $p) => $p['currency'] === 'vnd'
            && $p['amount'] === 250000                       // VND zero-decimal → no ×100
            && $p['metadata']['order_currency'] === 'vnd')   // immutable snapshot stamped
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_vnd', 'object' => 'payment_intent', 'amount' => 250000,
            'currency' => 'vnd', 'client_secret' => 's', 'status' => 'requires_payment_method',
        ]));

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($order);

    expect($payload['currency'])->toBe('vnd')->and($payload['amount'])->toBe(250000);
});

it('F2 — creates a USD intent scaled ×100 for a USD branch', function () {
    ($this->setBranchCurrency)('USD');
    $order = ($this->makeOrder)(25.50);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->withArgs(fn (array $p) => $p['currency'] === 'usd' && $p['amount'] === 2550)
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_usd', 'object' => 'payment_intent', 'amount' => 2550,
            'currency' => 'usd', 'client_secret' => 's', 'status' => 'requires_payment_method',
        ]));

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($order);

    expect($payload['amount'])->toBe(2550);
});

it('F4 — a JPY branch is unchanged (baseline)', function () {
    ($this->setBranchCurrency)('JPY');
    $order = ($this->makeOrder)(1500);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->withArgs(fn (array $p) => $p['currency'] === 'jpy' && $p['amount'] === 1500)
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_jpy', 'object' => 'payment_intent', 'amount' => 1500,
            'currency' => 'jpy', 'client_secret' => 's', 'status' => 'requires_payment_method',
        ]));

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($order);

    expect($payload['currency'])->toBe('jpy');
});

it('resolver defaults to JPY when the branch has no ShopOrderSetting row', function () {
    $order = ($this->makeOrder)(1500); // no setBranchCurrency

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->withArgs(fn (array $p) => $p['currency'] === 'jpy')
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_def', 'object' => 'payment_intent', 'amount' => 1500,
            'currency' => 'jpy', 'client_secret' => 's', 'status' => 'requires_payment_method',
        ]));

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($order);

    expect($payload['currency'])->toBe('jpy');
});

it('F5 — drops and recreates a stored jpy intent for a VND branch (zero-decimal collision)', function () {
    ($this->setBranchCurrency)('VND');
    $order = ($this->makeOrder)(250000, 0, 'pi_legacy_jpy');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    // Stored intent is jpy with the SAME integer amount (250000) — amount-equality
    // alone would wrongly reuse it; the currency check must force a recreate.
    $stripe->paymentIntents->expects('retrieve')->with('pi_legacy_jpy')->once()
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_legacy_jpy', 'object' => 'payment_intent', 'amount' => 250000,
            'currency' => 'jpy', 'status' => 'requires_payment_method',
        ]));
    $stripe->paymentIntents->expects('cancel')->with('pi_legacy_jpy')->once();
    $stripe->paymentIntents->expects('create')->once()
        ->withArgs(fn (array $p) => $p['currency'] === 'vnd')
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_new_vnd', 'object' => 'payment_intent', 'amount' => 250000,
            'currency' => 'vnd', 'client_secret' => 's', 'status' => 'requires_payment_method',
        ]));

    (new StripePaymentService($stripe))->createFullPaymentIntent($order);

    expect($order->fresh()->stripe_payment_intent_id)->toBe('pi_new_vnd');
});

it('resolver falls back to the config currency (env) when the branch has no setting', function () {
    config()->set('services.stripe.currency', 'usd'); // env fallback
    $order = ($this->makeOrder)(25.00); // no setBranchCurrency → no ShopOrderSetting row

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->expects('create')
        ->once()
        ->withArgs(fn (array $p) => $p['currency'] === 'usd') // env fallback, not hardcoded jpy
        ->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_env', 'object' => 'payment_intent', 'amount' => 2500,
            'currency' => 'usd', 'client_secret' => 's', 'status' => 'requires_payment_method',
        ]));

    $payload = (new StripePaymentService($stripe))->createFullPaymentIntent($order);

    expect($payload['currency'])->toBe('usd');
});

// =============================================================================
//  Webhook ledger-time currency guard
// =============================================================================

it('W2 — refuses to ledger a legacy jpy intent on a VND branch', function () {
    ($this->setBranchCurrency)('VND');
    $order = ($this->makeOrder)(250000, 0, 'pi_mismatch');

    $stripe = Mockery::mock(StripeClient::class);
    // Legacy intent: charged jpy, NO order_currency metadata.
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_mismatch', 'object' => 'payment_intent', 'amount' => 250000,
        'currency' => 'jpy', 'status' => 'succeeded', 'metadata' => ['flow' => 'full'],
    ]);

    (new StripePaymentService($stripe))->markOrderPaidFromIntent($intent);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(CustomerOrderStatusEnum::Open)
        ->and((float) $fresh->paid_amount)->toBe(0.0)
        ->and(OrderPayment::where('reference_no', 'pi_mismatch')->exists())->toBeFalse();
});

it('W7 — an already-ledgered intent replayed is a no-op and is NOT refunded', function () {
    config()->set('payments.stripe_live_refunds_enabled', true); // so a refund WOULD be attempted if the guard misfired
    ($this->setBranchCurrency)('VND');
    $order = ($this->makeOrder)(250000, 250000, 'pi_booked');
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'organization_id' => $this->organization->id,
        'amount' => 250000,
        'status' => PaymentStatusEnum::Succeeded->value,
        'reference_no' => 'pi_booked', // the intent id — idempotency key
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->shouldNotReceive('create'); // idempotency wins before the guard → no refund

    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_booked', 'object' => 'payment_intent', 'amount' => 250000,
        'currency' => 'jpy', 'status' => 'succeeded', 'metadata' => ['flow' => 'full'],
    ]);

    (new StripePaymentService($stripe))->markOrderPaidFromIntent($intent);

    expect(OrderPayment::where('reference_no', 'pi_booked')->count())->toBe(1);
});

it('W8 — a post-fix intent still ledgers when the branch currency was flipped after creation (no false refund)', function () {
    config()->set('payments.stripe_live_refunds_enabled', true);
    ($this->setBranchCurrency)('VND');
    $order = ($this->makeOrder)(250000, 0, 'pi_postfix');

    // Admin flips the branch currency AFTER the vnd intent was created.
    ShopOrderSetting::where('branch_id', $this->branch->id)->update(['currency_code' => 'USD']);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->refunds = Mockery::mock();
    $stripe->refunds->shouldNotReceive('create'); // metadata-first → no false-positive refund

    // Charged vnd, carries the immutable order_currency snapshot.
    $intent = PaymentIntent::constructFrom([
        'id' => 'pi_postfix', 'object' => 'payment_intent', 'amount' => 250000,
        'currency' => 'vnd', 'status' => 'succeeded',
        'metadata' => ['flow' => 'full', 'order_currency' => 'vnd'],
    ]);

    (new StripePaymentService($stripe))->markOrderPaidFromIntent($intent);

    expect($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Closed);
});

// =============================================================================
//  Historical audit command (read-only)
// =============================================================================

it('A1 — audit flags a VND-branch order charged under a jpy config, exit 1', function () {
    ($this->setBranchCurrency)('VND');
    ($this->makeOrder)(250000, 0, 'pi_hist')->save();

    $this->artisan('stripe:audit-currency-mismatch')
        ->assertExitCode(1);
});

it('A2 — audit is clean for a JPY fleet, exit 0', function () {
    ($this->setBranchCurrency)('JPY');
    ($this->makeOrder)(1500, 0, 'pi_ok')->save();

    $this->artisan('stripe:audit-currency-mismatch')
        ->assertExitCode(0);
});
