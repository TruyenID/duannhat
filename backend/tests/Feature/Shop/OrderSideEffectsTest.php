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
use App\Models\StockTransaction;
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
        'slug' => 'side-effects-shop',
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
        'first_name' => 'Side',
        'last_name' => 'Effects',
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
        // Side-effect tests exercise the stock_out_transaction flow which
        // OrderClosingService gates on inventory_mode='track_stock'.
        'inventory_mode' => 'track_stock',
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    // Warehouse is required for stock-out on order close.
    // is_active MUST be explicit — WarehouseFactory defaults it to a random
    // fake()->boolean(), and getDefaultWarehouse() only reuses an ACTIVE
    // branch warehouse. Without this, half the runs fell through to the lazy
    // DEFAULT warehouse, whose allow_negative_sales default masked the intent.
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        // Allow oversell so stock-out tests don't need to pre-seed StockLevel.
        'allow_negative_sales' => true,
        'auto_approve_stock_out' => true,
    ]);

    // Cash payment method (auto-confirm, requires tendered) to trigger auto-close
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

// =========================================================================
//  Stock-out after close
// =========================================================================

it('closing an order with 3 items (2 served, 1 voided) creates a stock_out with 2 items only', function () {
    $order = createSideEffectOrder();

    // Void one item
    $itemToVoid = $order->items->first();
    $itemToVoid->update([
        'status' => 'voided',
        'voided_at' => now(),
        'void_reason' => 'Test void',
    ]);

    // Checkout the order
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();
    $order->update(['total_amount' => 1000]);

    // Pay via cash to trigger auto-close
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');

    $stockTransaction = StockTransaction::where('reference_id', $order->id)
        ->where('type', 'stock_out')
        ->first();

    expect($stockTransaction)->not->toBeNull();
    expect($stockTransaction->items()->count())->toBe(2);
});

it('closed order creates a stock_transaction with type=stock_out, sub_type=sales, reference_type=customer_order', function () {
    $order = createSideEffectOrder();

    // Checkout
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();

    // Pay via cash to trigger auto-close
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => (float) $order->total_amount,
            'tendered_amount' => (float) $order->total_amount,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');

    $tx = StockTransaction::where('reference_id', $order->id)->first();

    expect($tx)->not->toBeNull();
    expect($tx->type->value)->toBe('stock_out');
    expect($tx->sub_type->value)->toBe('sales');
    expect($tx->reference_type)->toBe('customer_order');
});

it('closed order has stock_out_transaction_id set on the order record', function () {
    $order = createSideEffectOrder();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => (float) $order->total_amount,
            'tendered_amount' => (float) $order->total_amount,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect($order->stock_out_transaction_id)->not->toBeNull();
});

// =========================================================================
//  Table status side effects
// =========================================================================

it('open dine_in order sets table status=occupied and current_order_id=order.id', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'dine_in',
            'customer_id' => $this->customer->id,
            'table_ids' => [$this->table->id],
        ])
        ->assertCreated();

    $this->table->refresh();
    expect($this->table->status->value)->toBe('occupied');
    expect($this->table->current_order_id)->not->toBeNull();
});

it('closed dine_in order releases ALL tables to status=free with current_order_id=null', function () {
    $order = createSideEffectOrder();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => (float) $order->total_amount,
            'tendered_amount' => (float) $order->total_amount,
        ])
        ->assertCreated();

    $this->table->refresh();
    // #432 — payment settles the table straight to `free` (was `cleaning`).
    expect($this->table->status->value)->toBe('free');
    expect($this->table->current_order_id)->toBeNull();
});

it('voided dine_in order releases ALL tables to status=free with current_order_id=null', function () {
    $order = createSideEffectOrder();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Test void',
        ])
        ->assertOk();

    $this->table->refresh();
    expect($this->table->status->value)->toBe('free');
    expect($this->table->current_order_id)->toBeNull();
});

// =========================================================================
//  Helper
// =========================================================================

function createSideEffectOrder(): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-SE-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 1500,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1500,
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

    // 3 items: 2 will remain active, 1 will be voided in individual tests
    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => 500,
        'status' => 'served',
        'served_at' => now(),
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => 500,
        'status' => 'served',
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => 500,
        'status' => 'served',
    ]);

    return $order->load('items');
}

// =========================================================================
//  Warehouse auto-provisioning on close (plan-007 regression)
// =========================================================================

it('close auto-creates a default warehouse when the branch has none', function () {
    // Remove the warehouse seeded in beforeEach. Before the fix, close()
    // would abort(500, 'No warehouse found for branch'). After: it
    // lazily creates a minimal default warehouse.
    Warehouse::query()->delete();

    // Use a made_to_order SKU so the close does not require pre-seeded stock:
    // the lazily-created DEFAULT warehouse now honours the schema default
    // (allow_negative_sales=false, plan-024 org-admin-only opt-in), so a
    // track_stock sale into an empty warehouse would (correctly) 422. This
    // test targets the warehouse auto-creation path, not the shortage guard.
    $mtoProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->sku->product->product_type_id,
    ]);
    $mtoSku = ProductSku::factory()->create([
        'product_id' => $mtoProduct->id,
        'is_active' => true,
        'inventory_mode' => 'made_to_order',
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-SE-'.Str::random(4),
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
    Table::where('id', $this->table->id)->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);
    $order->items()->create([
        'product_sku_id' => $mtoSku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => 500,
        'status' => 'served',
        'served_at' => now(),
    ]);

    // Checkout so the order is ready for payment
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();

    // Pay via cash to trigger auto-close (which in turn needs a warehouse)
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => (float) $order->total_amount,
            'tendered_amount' => (float) $order->total_amount,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');

    // Exactly one default warehouse was lazily created for this branch,
    // and it honours the safe schema default (guard NOT silently disabled).
    $warehouses = Warehouse::where('branch_id', $this->shop->id)->get();
    expect($warehouses)->toHaveCount(1);
    // #2532 — mã kho lazily-created sinh theo chi nhánh (`DEFAULT-<branch>`)
    // vì `code` unique theo TỔ CHỨC: hằng `DEFAULT` dùng chung làm chi nhánh
    // thứ hai của org vỡ unique và mất trắng lượt trừ kho.
    expect($warehouses->first()->code)->toStartWith('DEFAULT-');
    expect((bool) $warehouses->first()->is_active)->toBeTrue();
    expect((bool) $warehouses->first()->allow_negative_sales)->toBeFalse();
});
