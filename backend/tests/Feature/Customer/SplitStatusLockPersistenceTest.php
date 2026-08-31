<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;

/**
 * Issue #406 — Dine-in Chia đều lock persistence across:
 *   - hard lock (post-payment, payment metadata authoritative)
 *   - soft lock (#406, customer_orders.split_mode + split_people_count
 *     stamped via /split-mode BEFORE any payment lands)
 *   - cross-method consistency (counter-pay payment auto-stamps the same
 *     metadata that the Stripe path already does)
 *
 * Refresh-persistence is implicit: every assertion below talks to the
 * /split-status endpoint, which is the single source-of-truth the FE
 * consumes on mount. If /split-status returns the right shape, F5 lands
 * on the right state regardless of localStorage.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'total_amount' => 3000,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Checkout->value,
    ]);
});

it('returns is_first_payment=true and no lock signals when the order is untouched', function () {
    $res = $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status");

    $res->assertOk()
        ->assertJsonPath('data.is_first_payment', true)
        ->assertJsonPath('data.paid_count', 0)
        ->assertJsonPath('data.split_count', null)
        ->assertJsonPath('data.amount_per_person', null)
        ->assertJsonPath('data.tentative_split_count', null)
        ->assertJsonPath('data.split_mode', null);
});

it('surfaces tentative_split_count when first payer has chosen headcount but not paid yet', function () {
    // Simulate POST /split-mode { split_mode: by_people, split_count: 3 }
    // — this is what customer-web's proactive useEffect now does (#406 FE
    // patch) the moment the user picks Chia đều + a headcount, regardless
    // of which payment method they're heading toward.
    $this->order->update([
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    $res = $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status");

    $res->assertOk()
        ->assertJsonPath('data.is_first_payment', true)
        ->assertJsonPath('data.paid_count', 0)
        ->assertJsonPath('data.split_count', null)
        ->assertJsonPath('data.tentative_split_count', 3)
        ->assertJsonPath('data.split_mode', 'even');
});

it('does not surface tentative_split_count when by_people but no headcount chosen', function () {
    // Customer tapped "Chia đều" without committing to a number (the
    // /split-mode endpoint allows by_people with a null count). No count →
    // nothing for other tabs to preview, so tentative stays null even
    // though split_mode is echoed back.
    $this->order->update([
        'split_mode' => 'even',
        'split_people_count' => null,
    ]);

    $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status")
        ->assertOk()
        ->assertJsonPath('data.tentative_split_count', null)
        ->assertJsonPath('data.split_mode', 'even');
});

it('does not surface tentative_split_count when split_mode is by_items (only by_people locks count)', function () {
    $this->order->update([
        'split_mode' => 'by_items',
        'split_people_count' => 3,
    ]);

    $res = $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status");

    $res->assertOk()
        ->assertJsonPath('data.tentative_split_count', null)
        ->assertJsonPath('data.split_mode', 'by_items');
});

it('hard-locks via payment metadata after the first succeeded payment (Stripe path shape)', function () {
    // Mimic what StripePaymentService::markOrderPaidFromIntent persists.
    OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 1000,
        'status' => PaymentStatusEnum::Succeeded->value,
        'paid_at' => now()->subSeconds(10),
        'metadata' => [
            'flow' => 'split',
            'split_count' => 3,
            'amount_per_person' => 1000,
        ],
    ]);

    $this->order->update(['paid_amount' => 1000]);

    $res = $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status");

    $res->assertOk()
        ->assertJsonPath('data.is_first_payment', false)
        ->assertJsonPath('data.paid_count', 1)
        ->assertJsonPath('data.split_count', 3)
        ->assertJsonPath('data.amount_per_person', 1000);
});

it('counter-pay path auto-stamps split_count + amount_per_person from customer_orders fields', function () {
    // First payer has locked headcount via /split-mode.
    $this->order->update([
        'split_mode' => 'even',
        'split_people_count' => 4,
    ]);

    // Counter-pay (kiosk / pos) creates an OrderPayment via OrderPaymentService
    // WITHOUT passing split_count/amount_per_person in metadata — this is the
    // historical behaviour. Service must auto-fill from the order so that
    // /split-status reads the same hard-lock shape as the Stripe path produces.
    $method = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'is_auto_confirm' => true,
        'requires_tendered' => false,
    ]);

    $service = app(OrderPaymentService::class);
    $service->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'amount' => 750.0,
        'received_by_id' => '00000000-0000-0000-0000-000000000000',
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        // metadata intentionally omitted — service must derive
    ]);

    $payment = OrderPayment::where('customer_order_id', $this->order->id)->first();
    expect($payment->metadata)->toBeArray();
    expect($payment->metadata['split_count'])->toBe(4);
    // JSON cast round-trip: PHP stores 750.0, JSON serialises as `750`,
    // model cast reads back as int. Compare loosely on numeric value.
    expect((float) $payment->metadata['amount_per_person'])->toBe(750.0);
    expect($payment->metadata['split_mode'])->toBe('even');

    // /split-status now hard-locks (no longer tentative).
    $res = $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status");
    $res->assertJsonPath('data.split_count', 4)
        ->assertJsonPath('data.amount_per_person', 750)
        ->assertJsonPath('data.paid_count', 1);
});

it('never overwrites caller-provided split metadata when auto-stamping', function () {
    $this->order->update([
        'split_mode' => 'even',
        'split_people_count' => 4,
    ]);

    $method = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'is_auto_confirm' => true,
        'requires_tendered' => false,
    ]);

    $service = app(OrderPaymentService::class);
    $service->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'amount' => 600.0,
        'received_by_id' => '00000000-0000-0000-0000-000000000000',
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'metadata' => [
            // Caller supplied non-canonical values — service must keep them.
            'split_count' => 99,
            'amount_per_person' => 4242,
            'custom_label' => 'first-payer',
        ],
    ]);

    $payment = OrderPayment::where('customer_order_id', $this->order->id)->first();
    expect($payment->metadata['split_count'])->toBe(99);
    expect($payment->metadata['amount_per_person'])->toBe(4242);
    expect($payment->metadata['custom_label'])->toBe('first-payer');
});

it('does not auto-stamp split metadata when order is not in by_people split mode', function () {
    // No split state on order — service should leave metadata untouched even
    // for a payment that happens to be partial.
    $method = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'is_auto_confirm' => true,
        'requires_tendered' => false,
    ]);

    $service = app(OrderPaymentService::class);
    $service->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'amount' => 500.0,
        'received_by_id' => '00000000-0000-0000-0000-000000000000',
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $payment = OrderPayment::where('customer_order_id', $this->order->id)->first();
    expect($payment->metadata)->toBeNull();
});

it('paid_count tracks succeeded payments across mixed methods so subsequent guests see Khách N/total', function () {
    $this->order->update([
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    $method = PaymentMethod::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'is_auto_confirm' => true,
        'requires_tendered' => false,
    ]);

    $service = app(OrderPaymentService::class);

    // First payer pays online (Stripe path shape).
    OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 1000,
        'status' => PaymentStatusEnum::Succeeded->value,
        'paid_at' => now()->subSeconds(30),
        'metadata' => [
            'flow' => 'split',
            'split_count' => 3,
            'amount_per_person' => 1000,
        ],
    ]);

    // Second payer pays via kiosk QR (counter-pay path through service).
    $service->create([
        'customer_order_id' => $this->order->id,
        'payment_method_id' => $method->id,
        'amount' => 1000.0,
        'received_by_id' => '00000000-0000-0000-0000-000000000000',
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $res = $this->getJson("/api/v1/customer/orders/{$this->order->id}/split-status");
    $res->assertJsonPath('data.paid_count', 2)
        ->assertJsonPath('data.split_count', 3)            // earliest payment wins as authoritative
        ->assertJsonPath('data.amount_per_person', 1000);
});
