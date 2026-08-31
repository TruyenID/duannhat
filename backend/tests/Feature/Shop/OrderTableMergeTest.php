<?php

use App\Events\OrderVoided;
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
use Illuminate\Support\Facades\Event;
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
        'slug' => 'merge-shop',
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
        'first_name' => 'Table',
        'last_name' => 'Merge',
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

    $this->zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
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
//  Merge
// =========================================================================

it('merging a free table into an open order sets current_order_id and status=occupied', function () {
    $order = createMergeOrder();

    $extraTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/merge-table", [
            'table_id' => $extraTable->id,
        ])
        ->assertOk();

    $extraTable->refresh();
    expect($extraTable->status->value)->toBe('occupied');
    expect($extraTable->current_order_id)->toBe($order->id);
});

// =========================================================================
//  Unmerge
// =========================================================================

it('unmerging a table from an order sets current_order_id=null and status=free', function () {
    $order = createMergeOrder();

    $extraTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => $order->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/unmerge-table", [
            'table_id' => $extraTable->id,
        ])
        ->assertOk();

    $extraTable->refresh();
    expect($extraTable->status->value)->toBe('free');
    expect($extraTable->current_order_id)->toBeNull();
});

// =========================================================================
//  Close/void with merged tables
// =========================================================================

it('closing an order with merged tables releases ALL tables to free', function () {
    $order = createMergeOrder();

    $extraTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => $order->id,
    ]);

    // Checkout
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();

    // Pay with cash to trigger auto-close
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => (float) $order->total_amount,
            'tendered_amount' => (float) $order->total_amount,
        ])
        ->assertCreated();

    $this->table->refresh();
    $extraTable->refresh();

    // #432 — payment settles merged tables straight to `free` (was `cleaning`).
    expect($this->table->status->value)->toBe('free');
    expect($this->table->current_order_id)->toBeNull();
    expect($extraTable->status->value)->toBe('free');
    expect($extraTable->current_order_id)->toBeNull();
});

it('voiding an order with merged tables releases ALL tables to free', function () {
    $order = createMergeOrder();

    $extraTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => $order->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Test void with merged tables',
        ])
        ->assertOk();

    $this->table->refresh();
    $extraTable->refresh();

    expect($this->table->status->value)->toBe('free');
    expect($this->table->current_order_id)->toBeNull();
    expect($extraTable->status->value)->toBe('free');
    expect($extraTable->current_order_id)->toBeNull();
});

it('voiding an order broadcasts OrderVoided with the released table ids', function () {
    Event::fake([OrderVoided::class]);

    $order = createMergeOrder();

    $extraTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => $order->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Broadcast test',
        ])
        ->assertOk();

    Event::assertDispatched(OrderVoided::class, function (OrderVoided $event) use ($order, $extraTable) {
        return $event->order->id === $order->id
            && $event->broadcastOn()[0]->name === "private-branch.{$this->shop->id}.pos-events"
            && in_array((string) $this->table->id, $event->releasedTableIds, true)
            && in_array((string) $extraTable->id, $event->releasedTableIds, true);
    });
});

// =========================================================================
//  Error cases
// =========================================================================

it('merging an already occupied table returns 409', function () {
    $order = createMergeOrder();

    // Another order holds this table
    $otherOrder = CustomerOrder::create([
        'order_code' => 'ORD-OTH-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'created_by_id' => $this->manager->id,
        'customer_id' => $this->customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $occupiedTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => $otherOrder->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/merge-table", [
            'table_id' => $occupiedTable->id,
        ])
        ->assertStatus(409);
});

it('unmerging the last table from a dine-in order returns 409', function () {
    $order = createMergeOrder();

    // The order only has the primary table — unmerging it is forbidden
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/unmerge-table", [
            'table_id' => $this->table->id,
        ])
        ->assertStatus(409);
});

it('merging a table when the order is not open returns 409', function () {
    $order = createMergeOrder();

    // Move the order past open
    $order->update(['status' => 'checkout']);

    $freeTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/merge-table", [
            'table_id' => $freeTable->id,
        ])
        ->assertStatus(409);
});

// =========================================================================
//  Atomicity / transaction integrity
// =========================================================================

it('mergeTable sets current_order_id atomically — DB state is consistent after merge', function () {
    $order = createMergeOrder();

    $extraTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/merge-table", [
            'table_id' => $extraTable->id,
        ])
        ->assertOk();

    // Both status and current_order_id must be set together — no partial state
    $extraTable->refresh();
    expect($extraTable->current_order_id)->toBe($order->id);
    expect($extraTable->status->value)->toBe('occupied');

    // The order must reflect the new table
    $updatedOrder = $order->fresh()->load('tables');
    expect($updatedOrder->tables->pluck('id'))->toContain($extraTable->id);
});

it('unmergeTable clears current_order_id atomically — DB state is consistent after unmerge', function () {
    $order = createMergeOrder();

    $extraTable = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'occupied',
        'current_order_id' => $order->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/unmerge-table", [
            'table_id' => $extraTable->id,
        ])
        ->assertOk();

    // Both status and current_order_id must be cleared together — no partial state
    $extraTable->refresh();
    expect($extraTable->current_order_id)->toBeNull();
    expect($extraTable->status->value)->toBe('free');

    // The order's table list must NOT include the unmerged table
    $updatedOrder = $order->fresh()->load('tables');
    expect($updatedOrder->tables->pluck('id'))->not->toContain($extraTable->id);
});

// =========================================================================
//  Helper
// =========================================================================

function createMergeOrder(): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-MRG-'.Str::random(4),
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
        'status' => 'served',
        'served_at' => now(),
    ]);

    return $order->load('items');
}

// =========================================================================
//  Cross-tenant isolation (security #850)
// =========================================================================

it('rejects merging a table that belongs to another tenant branch', function () {
    $order = createMergeOrder();

    // A completely separate tenant with its own branch + free table.
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId,
        'console_organization_id' => $foreignOrgId,
    ]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $foreignBranch = Branch::factory()->create([
        'console_organization_id' => $foreignOrgId,
        'console_brand_id' => $foreignBrand->console_brand_id,
        'slug' => 'foreign-shop',
        'is_active' => true,
    ]);
    $foreignZone = Zone::factory()->for($foreignBranch, 'branch')->create(['organization_id' => $foreignOrgId]);
    $foreignTable = Table::factory()->for($foreignBranch, 'branch')->for($foreignZone, 'zone')->create([
        'organization_id' => $foreignOrgId,
        'status' => 'free',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/merge-table", [
            'table_id' => $foreignTable->id,
        ])
        ->assertNotFound();

    // The foreign tenant's table is untouched.
    $foreignTable->refresh();
    expect($foreignTable->status->value)->toBe('free');
    expect($foreignTable->current_order_id)->toBeNull();
});
