<?php

/**
 * Plan 048 Gate 4 (T4.1–T4.3) — takeaway + mixed tender matrix.
 *
 *   T4.1 creation-status matrix: prep_before_payment × counter/stripe
 *   T4.2 KDS visibility unchanged: confirmed counter-pay takeaway is
 *        invisible to the kitchen until it opens (plan-035 rule)
 *   T4.3 split-bill + takeaway Stripe regression on the orchestrator path
 *        (plan-020/021 behaviour, transport switches explicitly ON)
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Facades\Mail;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

uses()->group('payment');

beforeEach(function () {
    Mail::fake();
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy',
        'services.stripe.webhook_secret' => 'whsec_test_dummy',
        'services.stripe.currency' => 'jpy',
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['pos', 'kiosk', 'workstation', 'customer_web'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => true,
            'kiosk' => true,
            'workstation' => true,
            'customer_web' => true,
        ],
    ]);

    $this->organization = Organization::factory()->create([
        'console_organization_id' => '00000000-cccc-4ccc-cccc-000000000048',
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'locale' => 'vi-VN',
    ]);
    $this->sku = ProductSku::factory()->create();

    $this->placeTakeaway = function (string $paymentMethod) {
        return $this->postJson("/api/v1/customer/branches/{$this->branch->slug}/orders", [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
            'customer_takeaway_name' => 'Matrix 048',
            'customer_takeaway_phone' => '+84336909454',
            'payment_method' => $paymentMethod,
        ]);
    };
});

// ---------------------------------------------------------------------------
// T4.1 — creation-status matrix
// ---------------------------------------------------------------------------

// NOTE (found by this matrix): CustomerOrderStoreRequest only allows
// payment_method in {counter, transfer, call_staff, qr_pay, card} — the
// service's prepaid branch (`in_array(payment_method, ['online','stripe'])` →
// always-open) is UNREACHABLE from the public endpoint. Online Stripe orders
// therefore create as `card` and follow the same review-step rule as counter;
// payment still closes the order via the intent/webhook path (T4.3, B1).
dataset('takeaway_status_matrix', [
    'counter × prep_before_payment default (true) → confirmed (FE review step)' => ['counter', null, 'confirmed'],
    'counter × prep_before_payment=false → open (kitchen starts pre-pay)' => ['counter', false, 'open'],
    'card × prep_before_payment default (true) → confirmed' => ['card', null, 'confirmed'],
    'card × prep_before_payment=false → open' => ['card', false, 'open'],
]);

it('T4.1: lands the documented creation status per payment_method × prep_before_payment', function (
    string $paymentMethod,
    ?bool $prepOverride,
    string $expectedStatus,
) {
    if ($prepOverride !== null) {
        ShopOrderSetting::create([
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'prep_before_payment' => $prepOverride,
        ]);
    }

    $response = ($this->placeTakeaway)($paymentMethod)->assertCreated();

    expect($response->json('data.status'))->toBe($expectedStatus)
        // Money invariant regardless of cell: creation writes no payment row.
        ->and(OrderPayment::query()->where('customer_order_id', $response->json('data.id'))->count())->toBe(0);
})->with('takeaway_status_matrix');

// ---------------------------------------------------------------------------
// T4.2 — KDS visibility (plan-035 rule unchanged)
// ---------------------------------------------------------------------------

it('T4.2: a confirmed counter-pay takeaway stays invisible to KDS until it opens', function () {
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'device_token' => 'tok_kds_plan048',
    ]);

    $orderId = ($this->placeTakeaway)('counter')->assertCreated()->json('data.id');

    $visibleIds = fn (): array => collect(
        $this->withHeader('Authorization', 'Bearer tok_kds_plan048')
            ->getJson('/api/v1/kds/orders')
            ->assertOk()
            ->json('data'),
    )->pluck('id')->all();

    expect($visibleIds())->not->toContain($orderId);

    CustomerOrder::query()->findOrFail($orderId)
        ->forceFill(['status' => CustomerOrderStatusEnum::Open->value, 'opened_at' => now()])
        ->save();

    expect($visibleIds())->toContain($orderId);
});

// ---------------------------------------------------------------------------
// T4.3 — split-bill + takeaway Stripe on the orchestrator path
// ---------------------------------------------------------------------------

it('T4.3: takeaway Stripe split slices accumulate and close exactly once', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $slice = fn (string $intentId, int $amount): PaymentIntent => PaymentIntent::constructFrom([
        'id' => $intentId,
        'object' => 'payment_intent',
        'amount' => $amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
        ],
    ]);

    $service->markOrderPaidFromIntent($slice('pi_048_split_1', 600));
    $order->refresh();

    expect((float) $order->paid_amount)->toBe(600.0)
        ->and($order->status->value)->toBe(CustomerOrderStatusEnum::Open->value)
        ->and($order->closed_at)->toBeNull();

    $service->markOrderPaidFromIntent($slice('pi_048_split_2', 400));
    // Replay of the final slice must not double-count (plan-021 idempotency).
    $service->markOrderPaidFromIntent($slice('pi_048_split_2', 400));
    $order->refresh();

    expect((float) $order->paid_amount)->toBe(1000.0)
        ->and($order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value)
        ->and($order->closed_at)->not->toBeNull()
        ->and(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(2);
});
