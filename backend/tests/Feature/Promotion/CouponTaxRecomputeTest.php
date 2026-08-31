<?php

/**
 * #555 M14 — applying a coupon used to re-sum the order total from the STORED
 * tax_amount, which is the PRE-coupon tax:
 *
 *     total = subtotal - discount + service_charge + tax_amount
 *
 * Tax is levied on the post-discount base, so a fixed-200 coupon on a 1000 @10%
 * order persisted 900 while the pricing engine says 880. Nothing downstream
 * re-prices, so 900 was what the cashier collected — a systematic overcharge on
 * every couponed order.
 *
 * The coupon path must now re-price through the one engine.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Services\Customer\OrderPricingCalculator;
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
        'slug' => 'coupon-tax-shop',
        'is_active' => true,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'JPY',
    ]);

    $managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $this->order = CustomerOrder::first();
});

/** A real priced line — the engine derives the order's money from lines, not from a stored subtotal. */
function ctrLine(CustomerOrder $order, float $unitPrice, float $taxRate): void
{
    CustomerOrderItem::create([
        'customer_order_id' => $order->id,
        'product_sku_id' => ProductSku::factory()->create()->id,
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'original_unit_price' => $unitPrice,
        'subtotal' => $unitPrice,
        'status' => 'served',
        'tax_rate' => $taxRate,
        'tax_amount' => 0,
    ]);
}

function ctrFixedCoupon(string $code, float $value): Coupon
{
    return Coupon::factory()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'code' => $code,
        'discount_type' => 'fixed',
        'discount_value' => $value,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);
}

function ctrApply(string $code): void
{
    test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug.'/orders/'.test()->order->id.'/apply-coupon', [
            'code' => $code,
        ])
        ->assertOk();
}

it('re-derives tax against the post-discount base when a coupon is applied', function () {
    ctrLine($this->order, 1000.0, 10.0);
    ctrFixedCoupon('M14FIX', 200.0);

    ctrApply('M14FIX');

    $this->order->refresh();

    // Taxable base 1000 - 200 = 800 → tax 80 → total 880. NOT the pre-coupon 100 / 900.
    expect((float) $this->order->discount_amount)->toBe(200.0)
        ->and((float) $this->order->tax_amount)->toBe(80.0)
        ->and((float) $this->order->total_amount)->toBe(880.0);
});

it('agrees with OrderPricingCalculator — no second total formula survives', function () {
    ctrLine($this->order, 1000.0, 10.0);
    ctrFixedCoupon('M14ENG', 200.0);

    ctrApply('M14ENG');

    $engine = app(OrderPricingCalculator::class)->priceGroups(
        ['10' => 1000.0], 200.0, 0.0, 0.0, false, 1.0,
    );

    $this->order->refresh();

    expect((float) $this->order->total_amount)->toBe($engine->totalAmount)
        ->and((float) $this->order->tax_amount)->toBe($engine->taxAmount);
});

it('allocates the discount pro-rata per rate group on a mixed 8% / 10% basket', function () {
    ctrLine($this->order, 1000.0, 8.0);   // reduced-rate line
    ctrLine($this->order, 1000.0, 10.0);  // standard-rate line
    ctrFixedCoupon('M14MIX', 400.0);

    ctrApply('M14MIX');

    $engine = app(OrderPricingCalculator::class)->priceGroups(
        ['8' => 1000.0, '10' => 1000.0], 400.0, 0.0, 0.0, false, 1.0,
    );

    $this->order->refresh();

    // 200 off each group → tax 8%×800 + 10%×800 = 64 + 80 = 144. A flat re-sum
    // cannot express this; only the per-rate engine can.
    expect((float) $this->order->tax_amount)->toBe($engine->taxAmount)
        ->and((float) $this->order->total_amount)->toBe($engine->totalAmount);
});

it('still clamps the total to zero when the coupon exceeds the subtotal', function () {
    ctrLine($this->order, 300.0, 10.0);
    ctrFixedCoupon('M14BIG', 5000.0);

    ctrApply('M14BIG');

    $this->order->refresh();

    expect((float) $this->order->total_amount)->toBe(0.0)
        ->and((float) $this->order->tax_amount)->toBe(0.0);
});
