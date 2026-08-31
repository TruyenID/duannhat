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
        'slug' => 'null-creator-shop',
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
        'first_name' => 'Null',
        'last_name' => 'Creator',
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
        'inventory_mode' => 'track_stock',
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
        'allow_negative_sales' => true,
        'auto_approve_stock_out' => true,
    ]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

// =========================================================================
//  Bug 3: Null created_by_id should not crash OrderClosingService
// =========================================================================

it('closing an order with created_by_id=null does not throw and creates a stock_out transaction', function () {
    // Create an order with NULL created_by_id (nullable since the migration)
    $order = CustomerOrder::create([
        'order_code' => 'ORD-NULL-'.Str::random(4),
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
        'created_by_id' => null, // <-- the bug trigger
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
        'product_sku_id' => $this->sku->id,
        'quantity' => 2,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => 1000,
        'status' => 'served',
        'served_at' => now(),
    ]);

    // Checkout
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertOk();

    $order->refresh();

    // Pay via cash to trigger auto-close — must not crash
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => (float) $order->total_amount,
            'tendered_amount' => (float) $order->total_amount,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');

    // Stock-out transaction must still be created
    $tx = StockTransaction::where('reference_id', $order->id)
        ->where('type', 'stock_out')
        ->first();

    expect($tx)->not->toBeNull();
    // created_by_id on the stock transaction must not be null (fallback was applied)
    expect($tx->created_by_id)->not->toBeNull();
});
