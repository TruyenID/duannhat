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
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/*
 * Wire-contract test — the `metadata` field must appear on the
 * OrderPaymentResource JSON payload so POS can derive per-item paid
 * units when reopening the split-bill modal. Model-side round-trip is
 * covered by OrderPaymentMetadataTest; here we assert the resource
 * serialiser actually emits the column.
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
        'slug' => 'resource-meta-shop',
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

function seedResourceMetaOrder(int $total): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-RES-'.Str::random(4),
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
        'quantity' => 2,
        'unit_price' => $total / 2,
        'original_unit_price' => $total / 2,
        'subtotal' => $total,
        'status' => 'served',
        'served_at' => now(),
        'tax_rate' => 0,
    ]);

    return $order;
}

it('POST /payments response includes metadata on the wire', function () {
    $order = seedResourceMetaOrder(6000);
    $item = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'total_bills' => 2,
                'label' => 'Người 1',
                'item_allocations' => [
                    ['item_id' => $item->id, 'units' => 1],
                ],
            ],
        ])
        ->assertCreated();

    $response
        ->assertJsonPath('data.metadata.split_mode', 'by_items')
        ->assertJsonPath('data.metadata.bill_index', 0)
        ->assertJsonPath('data.metadata.label', 'Người 1')
        ->assertJsonPath('data.metadata.item_allocations.0.item_id', $item->id)
        ->assertJsonPath('data.metadata.item_allocations.0.units', 1);
});

it('GET /orders/{id} embedded payments carry metadata', function () {
    $order = seedResourceMetaOrder(6000);
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'total_bills' => 2,
                'label' => 'Người 1',
                'item_allocations' => [
                    ['item_id' => $item->id, 'units' => 1],
                ],
            ],
        ])
        ->assertCreated();

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}")
        ->assertOk();

    $response
        ->assertJsonPath('data.payments.0.metadata.split_mode', 'by_items')
        ->assertJsonPath('data.payments.0.metadata.item_allocations.0.item_id', $item->id);
});

it('POST /payments without metadata returns null on the wire', function () {
    $order = seedResourceMetaOrder(2000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.metadata', null);
});
