<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
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
 * Regression for the "Duplicate entry PAY-YYYY-NNNN" crash when creating
 * a payment. Root cause: generateCode counted rows scoped by
 * organization_id + whereYear(created_at) and produced +1, but the DB
 * unique constraint on payment_code is GLOBAL — payments from other orgs
 * or rows with fake created_at dates collide.
 *
 * Fix: MAX-based computation over the PAY-{year}- prefix across all rows
 * (including trashed), plus a retry loop on UniqueConstraintViolation.
 */

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
        'slug' => 'pay-code-shop',
        'is_active' => true,
    ]);

    Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        'auto_approve_stock_out' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
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
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

function seedOrder(int $total, string $status = 'checkout'): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-TP'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => $status,
        'subtotal' => $total,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'checkout_at' => now(),
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

it('skips a payment_code colliding with a row from a different organization', function () {
    // Another org already used PAY-{year}-0001 — the unique constraint is
    // global, so our org must skip to 0002.
    $year = date('Y');
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);

    OrderPayment::create([
        'payment_code' => "PAY-{$year}-0001",
        'customer_order_id' => (string) Str::uuid(), // dangling is fine for this test
        'payment_method_id' => $this->cashMethod->id,
        'amount' => 100,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'paid_at' => now(),
        'received_by_id' => $this->manager->id,
        'organization_id' => $otherOrgId,
        'branch_id' => (string) Str::uuid(),
        'brand_id' => (string) Str::uuid(),
    ]);

    $order = seedOrder(1000);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])
        ->assertCreated();

    expect(OrderPayment::pluck('payment_code')->all())
        ->toContain("PAY-{$year}-0001", "PAY-{$year}-0002");
});

it('skips a payment_code colliding with a row whose created_at is outside this year', function () {
    $year = date('Y');

    OrderPayment::create([
        'payment_code' => "PAY-{$year}-0001",
        'customer_order_id' => (string) Str::uuid(),
        'payment_method_id' => $this->cashMethod->id,
        'amount' => 100,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'paid_at' => '2024-06-15 10:00:00',
        'received_by_id' => $this->manager->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'created_at' => '2024-06-15 10:00:00',
        'updated_at' => '2024-06-15 10:00:00',
    ]);

    $order = seedOrder(500);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500,
            'tendered_amount' => 500,
        ])
        ->assertCreated();

    expect(OrderPayment::pluck('payment_code')->all())
        ->toContain("PAY-{$year}-0001", "PAY-{$year}-0002");
});

it('skips a payment_code colliding with a soft-deleted row', function () {
    $year = date('Y');

    $trashed = OrderPayment::create([
        'payment_code' => "PAY-{$year}-0003",
        'customer_order_id' => (string) Str::uuid(),
        'payment_method_id' => $this->cashMethod->id,
        'amount' => 100,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'paid_at' => now(),
        'received_by_id' => $this->manager->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
    ]);
    $trashed->delete(); // soft-delete — DB unique index still holds the code

    $order = seedOrder(400);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 400,
            'tendered_amount' => 400,
        ])
        ->assertCreated();

    // Fresh payment must skip past the trashed 0003 — land on 0004.
    $codes = OrderPayment::withTrashed()->pluck('payment_code')->all();
    expect($codes)->toContain("PAY-{$year}-0003", "PAY-{$year}-0004");
});
