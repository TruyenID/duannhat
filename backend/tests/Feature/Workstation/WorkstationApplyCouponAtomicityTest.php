<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use Illuminate\Support\Str;

/**
 * #1686 (con của #1666) — `POST /workstation/orders/{id}/apply-coupon` writes
 * TWO things that only mean something together: the coupon binding on the order
 * (Ordering) and the `coupon_redemptions` row + guarded `times_used` increment
 * (Pricing, via `OrderCouponLedger`).
 *
 * Half of that batch is money. A coupon bound to an order with NO redemption
 * row means the usage cap has silently lost a use and every coupon report
 * under-counts — and nothing ever notices, because the order looks discounted
 * and the customer got their discount.
 *
 * `SyncUpAtomicityTest` asserts a transaction EXISTS by reading source. That is
 * a location check, not a behaviour check: it stays green for any wrapper
 * anywhere on the call chain and would stay green if the wrapper were moved
 * somewhere that no longer covers both writes. This file asserts the property
 * itself, by failing the SECOND write and requiring the FIRST to be gone.
 *
 * Failure is injected on the `CouponRedemption` created event rather than by
 * mocking a service, so the test does not pin which class performs the write —
 * only that the two writes commit or roll back together.
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
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'prices_include_tax' => false,
        'service_charge_tax_rate' => 0,
    ]);

    $this->std = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => Organization::where('console_organization_id', $this->orgId)->value('id'),
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation', 'status' => 'active', 'device_token' => $this->wsToken,
        'organization_id' => Organization::where('console_organization_id', $this->orgId)->value('id'),
        'branch_id' => $this->branch->id,
    ]);

    $this->coupon = Coupon::factory()->create([
        'code' => 'ATOMIC500', 'discount_type' => 'fixed', 'discount_value' => 500,
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(),
        'times_used' => 0, 'usage_limit_total' => null,
    ]);
});

function couponAtomicityHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

/** An order carrying one ¥2,000 line, exactly as a workstation sync would leave it. */
function couponAtomicityOrder(): CustomerOrder
{
    $sku = ProductSku::factory()->create(['selling_price' => 2000]);
    $sku->product->update([
        'tax_type_id' => test()->std->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $mp = MenuProduct::factory()->create([
        'menu_id' => test()->menu->id, 'product_id' => $sku->product_id, 'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $mp->id, 'product_sku_id' => $sku->id, 'is_active' => true, 'selling_price' => 2000,
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'WS-'.Str::random(5), 'order_type' => 'dine_in', 'status' => 'open',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0, 'opened_at' => now(),
        'branch_id' => test()->branch->id, 'brand_id' => test()->brand->id, 'organization_id' => test()->orgId,
    ]);

    test()->withHeaders(couponAtomicityHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [['id' => (string) Str::uuid(), 'product_sku_id' => $sku->id, 'quantity' => 1]],
        ])
        ->assertOk();

    return $order->fresh();
}

it('applies the coupon and records the redemption together', function () {
    $order = couponAtomicityOrder();
    expect((float) $order->subtotal)->toBe(2000.0);

    $this->withHeaders(couponAtomicityHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", ['code' => 'ATOMIC500'])
        ->assertOk();

    $order->refresh();
    expect($order->coupon_id)->toBe($this->coupon->id)
        ->and((float) $order->discount_amount)->toBe(500.0)
        ->and(CouponRedemption::where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((int) $this->coupon->fresh()->times_used)->toBe(1);
});

it('rolls the coupon OFF the order when the redemption row cannot be written', function () {
    $order = couponAtomicityOrder();

    // Fail the SECOND write, after the order has already been stamped with the
    // coupon. Without one transaction spanning both, the stamp survives and the
    // order carries a discount that no redemption row accounts for.
    CouponRedemption::created(function (): void {
        throw new DomainException('injected — redemption write failed');
    });

    $this->withoutExceptionHandling();

    try {
        $this->withHeaders(couponAtomicityHeaders())
            ->postJson("/api/v1/workstation/orders/{$order->id}/apply-coupon", ['code' => 'ATOMIC500']);
        expect(false)->toBeTrue('the injected redemption failure never surfaced');
    } catch (DomainException $e) {
        expect($e->getMessage())->toBe('injected — redemption write failed');
    }

    $order->refresh();

    expect($order->coupon_id)->toBeNull(
        'the order kept the coupon while its redemption row was rolled back — '.
        'a discount with no redemption breaks the usage cap and every coupon report',
    )
        ->and($order->coupon_code_snapshot)->toBeNull()
        ->and((float) $order->discount_amount)->toBe(0.0)
        ->and(CouponRedemption::where('customer_order_id', $order->id)->count())->toBe(0)
        ->and((int) $this->coupon->fresh()->times_used)->toBe(0);
});
