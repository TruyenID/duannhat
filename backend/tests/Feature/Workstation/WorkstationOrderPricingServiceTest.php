<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Services\Order\ValueObjects\CouponDiscountTerms;
use App\Services\Order\WorkstationOrderPricingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Plan-047 thin-controller/fat-service — the server-side money resolution
 * (authoritative price + coupon math) that moved from
 * Workstation/OrderLifecycleController into WorkstationOrderPricingService. The
 * HTTP surface stays covered by Plan040AddItemsPriceTest; these hit the service.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->service = app(WorkstationOrderPricingService::class);

    $this->order = fn (): CustomerOrder => CustomerOrder::create([
        'order_code' => 'WS-'.Str::random(5),
        'order_type' => 'spot',
        'status' => 'open',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // A SKU on an active menu of THIS branch at $menuPrice (raw sku price is 999).
    $this->menuSku = function (float $menuPrice): ProductSku {
        $sku = ProductSku::factory()->create(['selling_price' => 999]);
        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id, 'status' => 'Active',
        ]);
        $menuProduct = MenuProduct::factory()->create(['menu_id' => $menu->id, 'is_active' => true]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'is_active' => true,
            'selling_price' => $menuPrice,
        ]);

        return $sku;
    };
});

// ─── resolveAuthoritativeItemPrices ──────────────────────────────────────────

it('resolves the authoritative menu price and ignores a tampered client price', function () {
    $order = ($this->order)();
    $sku = ($this->menuSku)(800);

    $prices = $this->service->resolveAuthoritativeItemPrices($order, [
        ['product_sku_id' => $sku->id, 'quantity' => 2, 'unit_price' => 1], // tampered
    ]);

    expect($prices)->toBe([0 => 800.0]);
});

it('falls back to the raw SKU price for an off-menu SKU (mirrors the Cloud path)', function () {
    $order = ($this->order)();
    $sku = ProductSku::factory()->create(['selling_price' => 555]); // not on any menu

    $prices = $this->service->resolveAuthoritativeItemPrices($order, [
        ['product_sku_id' => $sku->id, 'quantity' => 1],
    ]);

    expect($prices)->toBe([0 => 555.0]);
});

it('throws a 422 ValidationException for an unknown SKU', function () {
    $order = ($this->order)();

    expect(fn () => $this->service->resolveAuthoritativeItemPrices($order, [
        ['product_sku_id' => (string) Str::uuid(), 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

it('throws for a non-sellable SKU', function () {
    $order = ($this->order)();
    $sku = ProductSku::factory()->create(['is_active' => false]); // isSellable() = false

    expect(fn () => $this->service->resolveAuthoritativeItemPrices($order, [
        ['product_sku_id' => $sku->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

// ─── computeCouponDiscount ───────────────────────────────────────────────────

/**
 * #962 — the service takes the coupon's TERMS, not the `Coupon` row. The
 * factory still supplies the values so the cases stay the real column shapes
 * (including the enum cast on `discount_type`).
 */
function couponTerms(Coupon $coupon): CouponDiscountTerms
{
    return CouponDiscountTerms::of($coupon->discount_type, $coupon->discount_value, $coupon->max_discount_cap);
}

it('caps a fixed coupon discount at the subtotal', function () {
    $coupon = Coupon::factory()->make(['discount_type' => 'fixed', 'discount_value' => 5000]);

    expect($this->service->computeCouponDiscount(couponTerms($coupon), 3000.0))->toBe(3000.0)   // capped
        ->and($this->service->computeCouponDiscount(couponTerms($coupon), 8000.0))->toBe(5000.0); // full value
});

it('computes a percent coupon discount (value is basis points /10000) and honours the cap', function () {
    // 1500 bps = 15%.
    $uncapped = Coupon::factory()->make(['discount_type' => 'percent', 'discount_value' => 1500, 'max_discount_cap' => null]);
    expect($this->service->computeCouponDiscount(couponTerms($uncapped), 10000.0))->toBe(1500.0); // 15% of 10,000

    $capped = Coupon::factory()->make(['discount_type' => 'percent', 'discount_value' => 1500, 'max_discount_cap' => 1000]);
    expect($this->service->computeCouponDiscount(couponTerms($capped), 10000.0))->toBe(1000.0);   // cap wins
});

it('caps a percent coupon discount at the subtotal itself', function () {
    // 100% of a subtotal must never exceed the subtotal.
    $coupon = Coupon::factory()->make(['discount_type' => 'percent', 'discount_value' => 10000, 'max_discount_cap' => null]);

    expect($this->service->computeCouponDiscount(couponTerms($coupon), 4200.0))->toBe(4200.0);
});
