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
        'slug' => 'tender-shop',
        'is_active' => true,
    ]);

    // Warehouse is required by OrderClosingService when auto-closing
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

    // Payment methods
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
    ]);

    $this->cardMethod = PaymentMethod::factory()->card()->create([
        'organization_id' => $this->orgId,
    ]);

    $this->transferMethod = PaymentMethod::factory()->transfer()->create([
        'organization_id' => $this->orgId,
    ]);
});

// =========================================================================
//  T5.2 — Split tender payments
// =========================================================================

it('cash full payment: single cash transaction auto-confirms and auto-closes the order', function () {
    $order = createTenderOrder(total: 500);

    // Checkout first
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    // Cash payment — auto-confirm, auto-close
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
            'tendered_amount' => 500,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'succeeded');

    expect($order->fresh()->status->value)->toBe('closed');
});

it('split cash + transfer: order closes after the second payment is confirmed', function () {
    $order = createTenderOrder(total: 500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    // Cash 300 — auto-confirmed
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 300,
            'tendered_amount' => 300,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'succeeded');

    // Order still open (only partially paid)
    expect($order->fresh()->status->value)->toBe('paying');

    // Transfer 200 — pending, then confirmed
    $transferResponse = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->transferMethod->id,
            'amount' => 200,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $paymentId = $transferResponse->json('data.id');

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$paymentId}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'succeeded');

    expect($order->fresh()->status->value)->toBe('closed');
});

it('3-way split: cash + card + transfer closes after all are confirmed', function () {
    // Total 600 split as 200 cash + 200 card + 200 transfer
    $order = createTenderOrder(total: 600);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    // Cash 200 — auto-confirmed
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 200,
            'tendered_amount' => 200,
        ])
        ->assertCreated();

    expect($order->fresh()->status->value)->toBe('paying');

    // Card 200 — pending, then confirmed
    $cardResponse = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cardMethod->id,
            'amount' => 200,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $cardId = $cardResponse->json('data.id');

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$cardId}/confirm")
        ->assertOk();

    expect($order->fresh()->status->value)->toBe('paying');

    // Transfer 200 — pending, then confirmed → order closes
    $transferResponse = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->transferMethod->id,
            'amount' => 200,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $transferId = $transferResponse->json('data.id');

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments/{$transferId}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'succeeded');

    expect($order->fresh()->status->value)->toBe('closed');
});

it('cash payment calculates change correctly', function () {
    $order = createTenderOrder(total: 500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
            'tendered_amount' => 600,
        ])
        ->assertCreated()
        ->assertJsonPath('data.change_amount', '100.00');
});

it('cash payment with tip: change = tendered - amount - tip', function () {
    // T5.10 — tip amount
    $order = createTenderOrder(total: 500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
            'tip_amount' => 50,
            'tendered_amount' => 600, // change = 600 - 500 - 50 = 50
        ])
        ->assertCreated()
        ->assertJsonPath('data.tip_amount', '50.00')
        ->assertJsonPath('data.change_amount', '50.00');

    // Tip is stored but NOT counted toward the order balance
    $order->refresh();
    expect((float) $order->paid_amount)->toBe(500.0);
});

it('tip is excluded from the paid_amount balance check', function () {
    $order = createTenderOrder(total: 500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    // Pay exact amount with a tip on top — still auto-closes (tip is not balance)
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
            'tip_amount' => 100,
            'tendered_amount' => 600,
        ])
        ->assertCreated();

    expect($order->fresh()->status->value)->toBe('closed');
    expect((float) $order->fresh()->paid_amount)->toBe(500.0);
    expect((float) $order->fresh()->total_tip)->toBe(100.0);
});

it('overpayment guard: amount > remaining → 422', function () {
    $order = createTenderOrder(total: 500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 600, // exceeds total of 500
            'tendered_amount' => 600,
        ])
        ->assertUnprocessable();
});

it('order transitions to paying status when first payment is created on a checkout order', function () {
    $order = createTenderOrder(total: 500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    expect($order->fresh()->status->value)->toBe('checkout');

    // Non-auto-confirm payment moves order to paying
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cardMethod->id,
            'amount' => 500,
        ])
        ->assertCreated();

    expect($order->fresh()->status->value)->toBe('paying');
});

// =========================================================================
//  Helper (scoped to this file with a unique name)
// =========================================================================

function createTenderOrder(float $total = 500): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-S'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
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
        // Pre-served — OrderClosingService refuses to close orders with
        // items still in kitchen-pending status. Tests focus on the
        // payment-flow assertion; KDS workflow is covered elsewhere.
        'status' => 'served',
        'served_at' => now(),
    ]);

    test()->table->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    return $order->load('items');
}
