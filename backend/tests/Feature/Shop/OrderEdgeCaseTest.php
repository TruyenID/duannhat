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
        'slug' => 'edge-case-shop',
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
        'first_name' => 'Edge',
        'last_name' => 'Case',
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
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

// =========================================================================
//  Voiding items
// =========================================================================

it('voiding the last active item auto-voids the order and frees the table (#559)', function () {
    $order = createEdgeOrder('pending');
    $item = $order->items->first();

    // Void the only item in the order — this empties the order.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Customer changed mind',
        ])
        ->assertOk();

    // #559 — an emptied Open order is auto-voided so its table is released,
    // instead of stranding the table as "occupied" until an explicit order void.
    expect($order->fresh()->status->value)->toBe('voided');
    $table = $this->table->fresh();
    expect($table->status->value)->toBe('free');
    expect($table->current_order_id)->toBeNull();
});

// =========================================================================
//  Mutation on closed order
// =========================================================================

it('any mutation attempt on a closed order returns 409', function () {
    $order = createEdgeOrder();

    // Force-close the order
    $order->update([
        'status' => 'closed',
        'closed_at' => now(),
        'paid_amount' => $order->total_amount,
    ]);

    // Try to add an item to a closed order
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertStatus(409);
});

// =========================================================================
//  Table conflicts
// =========================================================================

it('opening another order on a table that already has an open order returns 422', function () {
    // First order occupies the table
    createEdgeOrder();

    // Attempt to open a second order on the same table
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'dine_in',
            'customer_id' => $this->customer->id,
            'table_ids' => [$this->table->id],
        ])
        ->assertStatus(422);
});

it('merging a table that already has a current_order_id into a different order returns 409', function () {
    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $secondTable = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    // First order holds the second table
    $occupyingOrder = CustomerOrder::create([
        'order_code' => 'ORD-OCC-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 500,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 500,
        'paid_amount' => 0,
        'total_tip' => 0,
        'created_by_id' => $this->manager->id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $secondTable->update([
        'current_order_id' => $occupyingOrder->id,
        'status' => 'occupied',
    ]);

    // Target order (open, on primary table)
    $targetOrder = createEdgeOrder();

    // Try to merge the occupied second table into the target order
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$targetOrder->id}/merge-table", [
            'table_id' => $secondTable->id,
        ])
        ->assertStatus(409);
});

// =========================================================================
//  Cash payment — exact tender
// =========================================================================

it('cash tendered exactly equal to total amount results in change=0', function () {
    $order = createEdgeOrder();

    // Checkout
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();
    $totalAmount = (float) $order->total_amount;

    // Pay with exact cash
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $totalAmount,
            'tendered_amount' => $totalAmount,
        ])
        ->assertCreated();

    $response->assertJsonPath('data.change_amount', '0.00');
});

// =========================================================================
//  Helper
// =========================================================================

function createEdgeOrder(string $itemStatus = 'served'): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-EDGE-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'created_by_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    Table::where('id', test()->table->id)->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 2,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => 1000,
        'status' => $itemStatus,
        'served_at' => $itemStatus === 'served' ? now() : null,
    ]);

    return $order->load('items');
}
