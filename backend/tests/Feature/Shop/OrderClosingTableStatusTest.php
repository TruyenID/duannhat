<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * #491 — the table status a paid table returns to is a branch policy:
 * `free` (default) or `cleaning`, resolved shop ?? HQ brand default ?? free.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'tsap-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id]);
    $this->sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true, 'inventory_mode' => 'made_to_order']);

    $zone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId]);
    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create(['organization_id' => $this->orgId]);

    Warehouse::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
        'allow_negative_sales' => true, 'auto_approve_stock_out' => true,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
});

/** Create an occupied-table dine-in order, then checkout + pay to auto-close it. */
function closePaidDineInOrder($test): void
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(5),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 1000, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 1000, 'paid_amount' => 0, 'total_tip' => 0,
        'created_by_id' => null,
        'branch_id' => $test->shop->id,
        'brand_id' => $test->brand->id,
        'organization_id' => $test->orgId,
    ]);
    Table::where('id', $test->table->id)->update(['current_order_id' => $order->id, 'status' => 'occupied']);
    $order->items()->create([
        'product_sku_id' => $test->sku->id, 'quantity' => 2, 'unit_price' => 500, 'original_unit_price' => 500,
        'tax_rate' => 0, // #2188 — dòng phải mang snapshot; NULL bị engine loại
        'subtotal' => 1000, 'status' => 'served', 'served_at' => now(),
    ]);

    $test->actingAs($test->manager)
        ->postJson("/api/v1/shops/{$test->shop->slug}/orders/{$order->id}/checkout")->assertOk();
    $test->actingAs($test->manager)
        ->postJson("/api/v1/shops/{$test->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $test->cashMethod->id,
            'amount' => 1000.0,
            'tendered_amount' => 1000.0,
        ])->assertSuccessful();
}

it('defaults a paid table back to free (no setting)', function () {
    closePaidDineInOrder($this);
    expect(Table::find($this->table->id)->status->value)->toBe('free');
});

it('leaves a paid table in cleaning when the SHOP opts in', function () {
    ShopOrderSetting::create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'table_status_after_payment' => 'cleaning',
    ]);

    closePaidDineInOrder($this);
    expect(Table::find($this->table->id)->status->value)->toBe('cleaning');
});

it('inherits the HQ brand default (cleaning) when the shop value is NULL', function () {
    BrandOrderPolicy::create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_table_status_after_payment' => 'cleaning',
    ]);
    // Shop row exists but leaves the value NULL → must inherit HQ.
    ShopOrderSetting::create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'table_status_after_payment' => null,
    ]);

    closePaidDineInOrder($this);
    expect(Table::find($this->table->id)->status->value)->toBe('cleaning');
});

it('shop override beats the HQ default', function () {
    BrandOrderPolicy::create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_table_status_after_payment' => 'cleaning',
    ]);
    ShopOrderSetting::create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'table_status_after_payment' => 'free', // explicit override
    ]);

    closePaidDineInOrder($this);
    expect(Table::find($this->table->id)->status->value)->toBe('free');
});
