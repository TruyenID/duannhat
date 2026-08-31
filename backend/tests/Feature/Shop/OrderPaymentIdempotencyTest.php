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
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/*
 * Idempotency-key behavior on POST /payments — guarantees that a retry
 * after a network glitch returns the existing payment instead of
 * creating a duplicate, which used to manifest as "Payment amount
 * exceeds the outstanding order balance" the next time staff tried to
 * settle the rest of a split bill.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'idem-shop',
        'is_active' => true,
    ]);
    Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        'auto_approve_stock_out' => true,
    ]);
    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
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
        'selling_price' => 3000,
        'is_active' => true,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

function seedIdemCheckoutOrder(int $total): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-IDEM-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => $total,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
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
        'subtotal' => $total,
        'status' => 'served',
        'served_at' => now(),
        'tax_rate' => 0,
    ]);

    return $order;
}

it('returns the existing payment on retry with the same idempotency_key — no duplicate row', function () {
    $order = seedIdemCheckoutOrder(2000);
    $key = (string) Str::uuid();

    $first = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'idempotency_key' => $key,
        ])
        ->assertCreated();

    $firstPaymentId = $first->json('data.id');

    $second = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'idempotency_key' => $key,
        ]);

    // Same payment row returned — not a 422, not a new id.
    expect($second->json('data.id'))->toBe($firstPaymentId);
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);

    // Order paid_amount reflects only one collection.
    $order->refresh();
    expect((float) $order->paid_amount)->toBe(1000.0);
});

it('different idempotency_keys create separate payments — split-bill happy path', function () {
    $order = seedIdemCheckoutOrder(2000);
    $key1 = (string) Str::uuid();
    $key2 = (string) Str::uuid();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'idempotency_key' => $key1,
        ])
        ->assertCreated();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'idempotency_key' => $key2,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(2000.0);
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(2);
});

it('omitting idempotency_key keeps the legacy behavior — multiple identical posts still create separate rows', function () {
    $order = seedIdemCheckoutOrder(2000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
            'tendered_amount' => 500,
        ])
        ->assertCreated();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
            'tendered_amount' => 500,
        ])
        ->assertCreated();

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(2);
});

it('idempotency lookup is scoped to the order — same key on a different order does not leak', function () {
    $orderA = seedIdemCheckoutOrder(1000);
    $orderB = seedIdemCheckoutOrder(1000);
    $key = (string) Str::uuid();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$orderA->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'idempotency_key' => $key,
        ])
        ->assertCreated();

    // Same key on order B — under our scoped lookup, the service must NOT
    // return order A's payment. The DB unique constraint on the key column
    // is global so the second attempt is rejected (500/422 acceptable —
    // the important guarantee is that order B doesn't receive a payment
    // attributed to a different order). Rather than locking in either
    // outcome, we just assert that order B never ends up reflecting the
    // conflicting payment.
    try {
        $this->actingAs($this->manager)
            ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$orderB->id}/payments", [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 1000,
                'tendered_amount' => 1000,
                'idempotency_key' => $key,
            ]);
    } catch (Throwable) {
        // Swallow — DB-level rejection is acceptable for a UUID collision.
    }

    // Per plan-010 — idempotency keys are unique PER ORDER, so the same
    // key on a different order is a legitimate new payment. The important
    // guarantee verified above is that order B did NOT inherit order A's
    // payment via cross-order key lookup; the new payment belongs to B.
    $paymentA = OrderPayment::where('customer_order_id', $orderA->id)
        ->where('idempotency_key', $key)
        ->first();
    $paymentB = OrderPayment::where('customer_order_id', $orderB->id)
        ->where('idempotency_key', $key)
        ->first();
    expect($paymentA)->not->toBeNull();
    expect($paymentB)->not->toBeNull();
    expect($paymentA->id)->not->toBe($paymentB->id);
});

it('rejects with split_bill_total_drift when expected_total_amount disagrees with the order', function () {
    $order = seedIdemCheckoutOrder(2000);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'expected_total_amount' => 2500, // mismatch — order is actually 2000
        ])
        ->assertUnprocessable();

    expect($response->json('code'))->toBe('split_bill_total_drift');
    expect($response->json('expected_total_amount'))->toBe('2500.00');
    expect($response->json('actual_total_amount'))->toBe('2000.00');

    // No payment should have been recorded.
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(0);
});

it('accepts payment when expected_total_amount matches', function () {
    $order = seedIdemCheckoutOrder(2000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'expected_total_amount' => 2000,
        ])
        ->assertCreated();
});

it('omitting expected_total_amount keeps the legacy unchecked behavior', function () {
    $order = seedIdemCheckoutOrder(2000);

    // No expected_total_amount sent → drift guard skipped, normal flow.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])
        ->assertCreated();
});

it('replay after success still succeeds and does not block follow-up split-bill rows — regression for ORD-2026-2436', function () {
    // Reproduce the original failure mode: row 1 lands twice (same key,
    // simulating client-side retry after a network glitch). Row 2 must
    // still be acceptable — the orphan-pending bug surfaced as 422 on
    // row 2 because the duplicate was counted toward existing paid total.
    $order = seedIdemCheckoutOrder(5050);
    $row1Key = (string) Str::uuid();
    $row2Key = (string) Str::uuid();

    // Row 1 — first attempt
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2525,
            'tendered_amount' => 2525,
            'idempotency_key' => $row1Key,
        ])
        ->assertCreated();

    // Row 1 — retry (same key) — must return existing, not duplicate
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2525,
            'tendered_amount' => 2525,
            'idempotency_key' => $row1Key,
        ])
        ->assertCreated();

    // Row 2 — different key, must succeed without "exceeds balance" error
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2525,
            'tendered_amount' => 2525,
            'idempotency_key' => $row2Key,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(5050.0);
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(2);
});
