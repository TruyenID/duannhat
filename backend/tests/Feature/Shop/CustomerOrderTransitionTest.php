<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
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
        'slug' => 'transition-shop',
        'is_active' => true,
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
});

// =========================================================================
//  T5.5 — Invalid state transitions
// =========================================================================

it('returns 409 when checkout is called on an already-checkout order', function () {
    $order = createTransitionOrder();
    $order->update(['status' => 'checkout', 'checkout_at' => now()]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertStatus(409);
});

it('returns 409 when void is called on a closed order', function () {
    $order = createTransitionOrder();
    $order->update([
        'status' => 'closed',
        'checkout_at' => now()->subMinutes(10),
        'closed_at' => now(),
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Mistake',
        ])
        ->assertStatus(409);
});

it('returns 409 when checkout is called on a voided order', function () {
    $order = createTransitionOrder();
    $order->update([
        'status' => 'voided',
        'voided_at' => now(),
        'void_reason' => 'Customer left',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertStatus(409);
});

it('returns 409 when creating a payment on an open order (must checkout first)', function () {
    $order = createTransitionOrder();

    // Order is still open — payments require checkout or paying status
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2400,
            'tendered_amount' => 2400,
        ])
        ->assertStatus(409);
});

it('returns 409 when adding an item to a checkout order', function () {
    $order = createTransitionOrder();
    $order->update(['status' => 'checkout', 'checkout_at' => now()]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertStatus(409);
});

it('returns 409 when adding an item to a paying order', function () {
    $order = createTransitionOrder();
    $order->update(['status' => 'paying', 'checkout_at' => now()]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertStatus(409);
});

it('returns 409 when voiding a non-pending item', function () {
    $order = createTransitionOrder();
    $item = $order->items->first();

    // Advance item to preparing
    $item->update(['status' => 'preparing']);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Kitchen error',
        ])
        ->assertStatus(409);
});

it('allows free transitions between active item statuses (pending → served)', function () {
    // PR #129 relaxed the item-status state machine. The previous rule was
    // strict (pending → preparing → ready → served, no skipping), which made
    // POS staff backtrack through every step on a slow night. The new rule
    // allows any active → active transition; only `voided` is gated, because
    // voiding goes through the dedicated `/items/{id}/void` endpoint.
    $order = createTransitionOrder();
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}", [
            'status' => 'served',
        ])
        ->assertOk();

    expect($item->fresh()->status->value)->toBe('served');
});

// =========================================================================
//  Confirm (accept) — pending|confirmed → open. The POS "Tiếp nhận đơn"
//  button rides this endpoint so a counter-pay takeaway (customer-web →
//  `confirmed`) can enter the regular checkout pipeline.
// =========================================================================

it('confirm flips a confirmed counter-pay takeaway to open', function () {
    $order = createTransitionOrder();
    $order->update(['status' => 'confirmed', 'order_type' => 'takeaway']);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/confirm")
        ->assertOk();

    expect($order->fresh()->status->value)->toBe('open');
});

it('confirm flips a pending takeaway to open', function () {
    $order = createTransitionOrder();
    $order->update(['status' => 'pending', 'order_type' => 'takeaway']);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/confirm")
        ->assertOk();

    expect($order->fresh()->status->value)->toBe('open');
});

it('confirm returns 409 on an already-open order', function () {
    $order = createTransitionOrder(); // status open

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/confirm")
        ->assertStatus(409);
});

it('confirm is also reachable through the POS namespace (cloud fallback path)', function () {
    $order = createTransitionOrder();
    $order->update(['status' => 'confirmed', 'order_type' => 'takeaway']);

    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/confirm")
        ->assertOk();

    expect($order->fresh()->status->value)->toBe('open');
});

it('a confirmed order reaches checkout through confirm — the full accept-then-pay path', function () {
    $order = createTransitionOrder();
    $order->update(['status' => 'confirmed', 'order_type' => 'takeaway']);

    // Checkout straight from `confirmed` is rejected (state machine).
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertStatus(409);

    // Accept first…
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/confirm")
        ->assertOk();

    // …then the regular checkout pipeline opens up.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    expect($order->fresh()->status->value)->toBe('checkout');
});

// =========================================================================
//  Helper (scoped to this file with a unique name)
// =========================================================================

function createTransitionOrder(): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-T'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 2400,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 2400,
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
        'quantity' => 2,
        'unit_price' => 1200,
        'original_unit_price' => 1200,
        'subtotal' => 2400,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);

    test()->table->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    return $order->load('items');
}
