<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
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
        'slug' => 'validation-shop',
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
});

// =========================================================================
//  T5.3 — Validation rules
// =========================================================================

it('returns 422 when the requested table is already occupied', function () {
    // Occupy the table with an existing order
    $existingOrder = CustomerOrder::factory()->open()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'created_by_id' => $this->manager->id,
    ]);
    $this->table->update([
        'current_order_id' => $existingOrder->id,
        'status' => 'occupied',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'dine_in',
            'table_ids' => [$this->table->id],
        ])
        ->assertUnprocessable();
});

it('returns 422 when void order is submitted without void_reason', function () {
    $order = createOpenOrderForValidation();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [])
        ->assertUnprocessable();
});

it('returns 422 when void order is submitted with empty void_reason', function () {
    $order = createOpenOrderForValidation();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => '',
        ])
        ->assertUnprocessable();
});

it('returns 422 when checkout is called on an order where all items are voided', function () {
    $order = createOpenOrderForValidation();
    $item = $order->items->first();

    // Void the only item
    $item->update([
        'status' => 'voided',
        'voided_at' => now(),
        'void_reason' => 'Customer changed mind',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertUnprocessable();
});

// =========================================================================
//  Helper (scoped to this file with a unique name)
// =========================================================================

function createOpenOrderForValidation(): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-V'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 1500,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1500,
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
        'unit_price' => 1500,
        'original_unit_price' => 1500,
        'subtotal' => 1500,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);

    test()->table->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    return $order->load('items');
}
