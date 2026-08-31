<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
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
 * Plan-021 — split-bill audit metadata persistence on POST /payments.
 * The column is informational: server must accept the payload, validate
 * shape only, store it as-is, and let receipts/audit re-project the JSON
 * on read.
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
        'slug' => 'meta-shop',
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

function seedMetadataOrder(int $total): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-META-'.Str::random(4),
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

it('F1 — equal-mode metadata is persisted on each payment row', function () {
    $order = seedMetadataOrder(2000);

    $first = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'even',
                'bill_index' => 0,
                'total_bills' => 2,
            ],
        ])
        ->assertCreated();

    $second = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'even',
                'bill_index' => 1,
                'total_bills' => 2,
            ],
        ])
        ->assertCreated();

    $row1 = OrderPayment::find($first->json('data.id'));
    $row2 = OrderPayment::find($second->json('data.id'));

    expect($row1->metadata)->toBe([
        'split_mode' => 'even',
        'bill_index' => 0,
        'total_bills' => 2,
    ]);
    expect($row2->metadata)->toBe([
        'split_mode' => 'even',
        'bill_index' => 1,
        'total_bills' => 2,
    ]);
});

it('F2 — by-items-mode metadata round-trips through the array cast', function () {
    $order = seedMetadataOrder(3000);
    /** @var CustomerOrderItem $item */
    $item = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'label' => 'Người 1',
                'item_allocations' => [
                    ['item_id' => $item->id, 'units' => 1],
                ],
            ],
        ])
        ->assertCreated();

    $row = OrderPayment::find($response->json('data.id'));

    expect($row->metadata)->toMatchArray([
        'split_mode' => 'by_items',
        'bill_index' => 0,
        'label' => 'Người 1',
    ]);
    expect($row->metadata['item_allocations'])->toBe([
        ['item_id' => $item->id, 'units' => 1],
    ]);
});

it('F2b — by-amount-mode metadata round-trips through the array cast', function () {
    $order = seedMetadataOrder(3000);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 3000,
            'tendered_amount' => 3000,
            'metadata' => [
                'split_mode' => 'by_amount',
                'bill_index' => 0,
                'total_bills' => 2,
                'label' => 'Người 1',
                'amount' => 3000,
            ],
        ])
        ->assertCreated();

    $row = OrderPayment::find($response->json('data.id'));

    expect($row->metadata)->toMatchArray([
        'split_mode' => 'by_amount',
        'bill_index' => 0,
        'total_bills' => 2,
        'label' => 'Người 1',
    ]);
    expect((float) $row->metadata['amount'])->toBe(3000.0);
});

it('F3 — metadata.split_mode must be in [equal,by_items,by_amount]', function () {
    $order = seedMetadataOrder(1000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'invalid',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.split_mode']);
});

it('F4 — metadata.item_allocations.item_id must exist on customer_order_items', function () {
    $order = seedMetadataOrder(1000);
    $bogusId = (string) Str::uuid();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [
                    ['item_id' => $bogusId, 'units' => 1],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.item_allocations.0.item_id']);
});

it('F5 — metadata is nullable; omitting it stores NULL and keeps backward compat', function () {
    $order = seedMetadataOrder(1000);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])
        ->assertCreated();

    $row = OrderPayment::find($response->json('data.id'));

    expect($row->metadata)->toBeNull();
});

it('F6 — N split-bill payments summing to total still auto-close the order', function () {
    $order = seedMetadataOrder(3000);

    foreach ([0, 1, 2] as $i) {
        $this->actingAs($this->manager)
            ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
                'payment_method_id' => $this->cashMethod->id,
                'amount' => 1000,
                'tendered_amount' => 1000,
                'metadata' => [
                    'split_mode' => 'even',
                    'bill_index' => $i,
                    'total_bills' => 3,
                ],
            ])
            ->assertCreated();
    }

    $order->refresh();

    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(3000.0);
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(3);
});
