<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Table;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'refund-shop',
        'is_active' => true,
    ]);

    // Warehouse is required by OrderClosingService
    Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        'auto_approve_stock_out' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
    ]);

    $this->cardMethod = PaymentMethod::factory()->card()->create([
        'organization_id' => $this->orgId,
    ]);
});

// =========================================================================
//  T5.8 — Payment refunds
// =========================================================================

it('full refund: creates a negative record and marks original payment as refunded', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertCreated();

    // Refund record has a negative amount
    expect((float) $response->json('data.amount'))->toBe(-500.0);
    expect($response->json('data.refund_of_id'))->toBe($payment->id);
    expect($response->json('data.status'))->toBe('succeeded');

    // Original payment is now refunded
    expect($payment->fresh()->status->value)->toBe('refunded');
});

it('partial refund: only the specified amount is negated', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund", [
            'amount' => 200,
        ])
        ->assertCreated();

    expect((float) $response->json('data.amount'))->toBe(-200.0);
    expect($response->json('data.refund_of_id'))->toBe($payment->id);
});

it('returns 422 when refund amount exceeds the original payment', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund", [
            'amount' => 600,
        ])
        ->assertUnprocessable();
});

it('returns 409 when refunding a non-succeeded payment', function () {
    $order = createRefundOrder(total: 500);

    // Checkout and create a card payment (pending — not yet confirmed)
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $paymentResponse = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cardMethod->id,
            'amount' => 500,
        ])
        ->assertCreated();

    $paymentId = $paymentResponse->json('data.id');

    // Try to refund a pending payment — must fail
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$paymentId}/refund")
        ->assertStatus(409);
});

it('returns 409 when attempting a double refund on an already-refunded payment', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);

    // First refund succeeds
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertCreated();

    // Second refund must fail — payment is now refunded, not succeeded
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertStatus(409);
});

it('replays a committed refund by idempotency key without a second ledger effect', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    $url = "/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund";
    $body = ['amount' => 200, 'idempotency_key' => 'refund-response-timeout-1'];

    $first = $this->actingAs($this->manager)->postJson($url, $body)->assertCreated();
    $second = $this->actingAs($this->manager)->postJson($url, $body)->assertSuccessful();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(OrderPayment::query()->where('refund_of_id', $payment->id)->count())->toBe(1);
    expect((float) $order->fresh()->paid_amount)->toBe(300.0);
});

it('uses the Idempotency-Key header as authority and replays the original refund result', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    $url = "/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund";

    $first = $this->actingAs($this->manager)
        ->withHeader('Idempotency-Key', 'refund-header-timeout-1')
        ->postJson($url, [
            'amount' => 200,
            'idempotency_key' => 'body-key-must-not-win',
        ])
        ->assertCreated();

    // A retry may be reconstructed with a different body after the original
    // response vanished. The stable transport header identifies the committed
    // result; it must not create a second or larger reversal.
    $second = $this->actingAs($this->manager)
        ->withHeader('Idempotency-Key', 'refund-header-timeout-1')
        ->postJson($url, ['amount' => 300])
        ->assertSuccessful();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(OrderPayment::query()->where('refund_of_id', $payment->id)->count())->toBe(1);
    expect(OrderPayment::query()->where('refund_of_id', $payment->id)->value('idempotency_key'))
        ->toBe('refund-header-timeout-1');
    expect((float) $order->fresh()->paid_amount)->toBe(300.0);
});

it('rejects an oversized refund idempotency key before writing the ledger', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);

    $this->actingAs($this->manager)
        ->withHeader('Idempotency-Key', str_repeat('x', 65))
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');

    expect(OrderPayment::query()->where('refund_of_id', $payment->id)->count())->toBe(0);
    expect($payment->fresh()->status->value)->toBe('succeeded');
    expect((float) $order->fresh()->paid_amount)->toBe(500.0);
});

it('returns conflict when a refund key already belongs to another payment operation', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    $payment->forceFill(['idempotency_key' => 'payment-operation-key'])->save();

    $this->actingAs($this->manager)
        ->withHeader('Idempotency-Key', 'payment-operation-key')
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertConflict();

    expect(OrderPayment::query()->where('refund_of_id', $payment->id)->count())->toBe(0);
    expect($payment->fresh()->status->value)->toBe('succeeded');
    expect((float) $order->fresh()->paid_amount)->toBe(500.0);
});

// Issue #528 — paid_amount must equal collected − refunded. A refund flips
// the original to `refunded` AND inserts a negative `succeeded` offset row;
// the cache must count both statuses or it double-subtracts the refund and
// drives paid_amount negative.
it('keeps paid_amount = collected − refunded on a PARTIAL refund (#528)', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 1000);

    expect((float) $order->fresh()->paid_amount)->toBe(1000.0);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund", [
            'amount' => 400,
        ])
        ->assertCreated();

    expect((float) $order->fresh()->paid_amount)->toBe(600.0);
});

it('sets paid_amount = 0 on a FULL refund (#528)', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 1000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertCreated();

    expect((float) $order->fresh()->paid_amount)->toBe(0.0);
});

it('refund note is stored on the refund record', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund", [
            'note' => 'Customer dissatisfied',
        ])
        ->assertCreated();

    expect($response->json('data.note'))->toBe('Customer dissatisfied');
});

// =========================================================================
//  #2657 (layer 3 of #2580) — Cloud refuses to refund drawer money that a
//  shop workstation is still counting.
//
//  pos-web reaches this endpoint only by accident: apiFetch retries a LAN
//  network error once against Cloud, so a LAN hiccup mid-refund books the
//  refund here, against Cloud's open shift, while the workstation that serves
//  the 精算 screen never hears about it. Refunds do not sync DOWN by design,
//  so the fix is to refuse and send staff back to the shop POS.
//
//  Provenance is the server-owned `channel` column; that the workstation route
//  really stamps it is proven end-to-end by
//  tests/Feature/Payment/PaymentChannelProvenanceTest.php (#2612), so these
//  tests stamp the resulting row shape directly and focus on the guard.
// =========================================================================

it('refuses with 409 REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT when the workstation shift that took the money is still open (#2657)', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    $session = refundTestShift('open');
    stampRefundTestOrigin($payment, 'workstation', $session->id);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertStatus(409)
        ->assertJsonPath('code', 'REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT')
        ->assertJsonPath('payment_id', $payment->id)
        ->assertJsonPath('till_session_id', $session->id);

    // The refusal must land BEFORE the ledger write, not alongside it: no
    // negative row, and the original still spendable on the shop POS.
    expect(OrderPayment::where('refund_of_id', $payment->id)->count())->toBe(0);
    expect($payment->fresh()->status->value)->toBe('succeeded');
    expect((float) $order->fresh()->paid_amount)->toBe(500.0);
});

it('also refuses while that shift is CLOSING — the drawer is being counted right then (#2657)', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    $session = refundTestShift('closing');
    stampRefundTestOrigin($payment, 'workstation', $session->id);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertStatus(409)
        ->assertJsonPath('code', 'REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT');

    expect(OrderPayment::where('refund_of_id', $payment->id)->count())->toBe(0);
});

// Scope guard 1 — Cloud-origin money never touched a drawer, so an open shift
// at the branch is irrelevant to it. Blocking these would break online refunds
// for every shop that happens to have a cashier on duty.
it('still refunds a Cloud-origin payment while a shift is open (#2657)', function (string $channel) {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    $session = refundTestShift('open');
    stampRefundTestOrigin($payment, $channel, $session->id);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertCreated();

    expect($payment->fresh()->status->value)->toBe('refunded');
})->with(['customer_web', 'kiosk', 'self_regi', 'pos', 'server_to_server']);

// Scope guard 2 — once the shift reaches a terminal state its Z-report is
// done; there is no open reconciliation left for a Cloud refund to diverge
// from, so the refusal must lift.
it('still refunds a workstation payment once its shift is terminal (#2657)', function (string $status) {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    $session = refundTestShift($status);
    stampRefundTestOrigin($payment, 'workstation', $session->id);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertCreated();

    expect($payment->fresh()->status->value)->toBe('refunded');
})->with(['settled', 'abandoned', 'expired']);

// Scope guard 3 — a workstation payment with no shift attribution (gap
// payment) was never counted into any drawer, so nothing is open to protect.
it('still refunds a workstation payment that was never attributed to a drawer (#2657)', function () {
    [$order, $payment] = createClosedOrderWithPayment(amount: 500);
    stampRefundTestOrigin($payment, 'workstation', null);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$payment->id}/refund")
        ->assertCreated();

    expect($payment->fresh()->status->value)->toBe('refunded');
});

// =========================================================================
//  Helpers (scoped to this file with unique names)
// =========================================================================

/**
 * A cashier shift at this test's branch, in the requested lifecycle state.
 */
function refundTestShift(string $status): TillSession
{
    $till = Till::factory()->create([
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    $session = TillSession::factory()->create([
        'status' => $status,
        'till_id' => $till->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    $till->update(['current_session_id' => $session->id]);

    return $session;
}

/**
 * Stamp the two columns the #2657 guard reads: the server-owned provenance
 * `channel` and the shift the money was attributed to.
 */
function stampRefundTestOrigin(OrderPayment $payment, string $channel, ?string $tillSessionId): void
{
    $payment->forceFill([
        'channel' => $channel,
        'till_session_id' => $tillSessionId,
    ])->save();
}

/**
 * Create a refund-test open order.
 */
function createRefundOrder(float $total = 500): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-R'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => $total,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'created_by_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => $total,
        'original_unit_price' => $total,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => $total,
        'status' => 'served',
        'served_at' => now(),
    ]);

    test()->table->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    return $order->load('items');
}

/**
 * Create a fully closed order with a single succeeded cash payment.
 *
 * @return array{0: CustomerOrder, 1: OrderPayment}
 */
function createClosedOrderWithPayment(float $amount = 500): array
{
    $order = createRefundOrder(total: $amount);

    // Checkout
    $checkoutResponse = test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/checkout");

    $checkoutResponse->assertOk();

    // Full cash payment — auto-confirms and auto-closes
    $paymentResponse = test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/payments", [
            'payment_method_id' => test()->cashMethod->id,
            'amount' => $amount,
            'tendered_amount' => $amount,
        ]);

    $paymentResponse->assertCreated();

    $paymentId = $paymentResponse->json('data.id');
    $payment = OrderPayment::findOrFail($paymentId);
    $order->refresh();

    return [$order, $payment];
}
