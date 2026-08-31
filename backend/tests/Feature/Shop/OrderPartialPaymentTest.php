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
 * Plan 007 — staff allowed to accept partial cash (tendered < remaining).
 * Order must stay in `paying` status with paid_amount recording what
 * was collected; remaining_amount on the next read = total - paid.
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
        'slug' => 'partial-shop',
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

function seedCheckoutOrder(int $total): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-PT-'.Str::random(4),
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

it('accepts partial cash — order stays paying, paid_amount reflects what was collected', function () {
    $order = seedCheckoutOrder(3000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('paying');
    expect((float) $order->paid_amount)->toBe(2000.0);
    expect((float) $order->total_amount)->toBe(3000.0);
});

it('customer outstanding surfaces the partially-paid order on next visit', function () {
    $order = seedCheckoutOrder(3000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
        ])
        ->assertCreated();

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/customers/{$this->customer->id}/outstanding")
        ->assertOk();

    $codes = collect($response->json('data'))->pluck('order_code')->all();
    expect($codes)->toContain($order->order_code);
    expect($response->json('total_owed'))->toBe('1000.00');
});

it('a second partial payment brings total paid to full and closes the order', function () {
    $order = seedCheckoutOrder(3000);

    // Partial 1: 2000 of 3000
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('paying');

    // Partial 2: remaining 1000
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])
        ->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(3000.0);
    expect($order->closed_at)->not->toBeNull();
});

it('still rejects tendered less than the amount being charged (backend invariant)', function () {
    $order = seedCheckoutOrder(3000);

    // tendered 1500 < amount 2000 — backend throws InvalidArgumentException
    // which Laravel surfaces as 500. The status code is not ideal (should
    // be 422) but that's a pre-existing quirk of OrderPaymentService;
    // what matters for plan-007 is that the payment row is NOT written
    // when tendered < amount. FE sends tendered >= amount per row, so
    // this path only fires when the FE contract is violated.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 1500,
        ])
        ->assertStatus(500);

    $order->refresh();
    expect($order->status->value)->toBe('checkout');
    expect((float) $order->paid_amount)->toBe(0.0);
});

it('clamps a sub-unit overpay to the outstanding and closes the order (integer-currency workstation rounding)', function () {
    // Fractional-currency (USD/EUR) total: 1550 + 155 tax + 77.50 service.
    $order = CustomerOrder::create([
        'order_code' => 'ORD-PT-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 1550, 'discount_amount' => 0, 'service_charge' => 77.50, 'tax_amount' => 155,
        'total_amount' => 1782.50, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $this->customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 1, 'unit_price' => 1550, 'original_unit_price' => 1550, 'subtotal' => 1550,
        'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);

    // First installment.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1271, 'tendered_amount' => 1271,
        ])->assertCreated();

    // Second installment: an integer-currency workstation sends 512, but the
    // outstanding is 511.50. The 0.50 overpay is clamped and the order settles.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 512, 'tendered_amount' => 512,
        ])->assertCreated();

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(1782.5);
});

it('still rejects an overpay larger than the rounding tolerance', function () {
    $order = seedCheckoutOrder(1000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1002, 'tendered_amount' => 1002,
        ])->assertStatus(422);

    $order->refresh();
    expect((float) $order->paid_amount)->toBe(0.0);
});
