<?php

/**
 * #550 — coupon discount must recompute when the cart changes, not freeze
 * at apply() time.
 *
 * A coupon whose discount was snapshotted at apply() time and never
 * re-derived caused:
 *   - a fixed coupon becoming a house giveaway that bypasses min-spend,
 *   - a persisted NEGATIVE total_amount when discount > shrunken subtotal,
 *   - percent discounts drifting when items are added/removed.
 *
 * The fix re-runs the discount computation (re-checking min-spend + cap)
 * inside CustomerOrderService::recalculateTotals — which fires on every
 * add/void/update item — and clamps total_amount to max(0, …).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Coupon\OrderCouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->orgId = $orgId;
    $this->orderCoupons = app(OrderCouponService::class);
    $this->orderService = app(CustomerOrderService::class);
});

/**
 * Build an open order with the given per-item unit prices (quantity 1
 * each), subtotal pre-set to the sum so apply()'s min-spend check reads
 * the live cart total.
 *
 * @param  array<int, float>  $unitPrices
 * @return array{0: CustomerOrder, 1: array<int, CustomerOrderItem>}
 */
function makeOrderWithItems(array $unitPrices, string $orgId, string $brandId, string $branchId): array
{
    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'status' => 'open',
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
    ]);

    $items = [];
    foreach ($unitPrices as $price) {
        $items[] = CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => $price,
            'subtotal' => $price,
            'topping_subtotal' => 0,
            'status' => 'pending',
            // #857 — stamp an explicit (0%) rate so the lines are NOT "unstamped".
            // A null tax_rate would trip applyPricing's lazy re-stamp branch,
            // whose own load('items') would reload the collection and MASK the
            // stale-snapshot bug this test guards. 0 keeps the zero-tax
            // assertions below intact.
            'tax_rate' => 0,
        ]);
    }

    $order->update([
        'subtotal' => array_sum($unitPrices),
        'total_amount' => array_sum($unitPrices),
    ]);
    $order->refresh();

    return [$order, $items];
}

it('recomputes a fixed coupon to 0 when the cart drops below min-spend (no giveaway, no negative total)', function () {
    // FIXED 50,000 with min_order_subtotal 100,000.
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'FIXED50',
        'discount_type' => 'fixed',
        'discount_value' => 50000,
        'max_discount_cap' => null,
        'min_order_subtotal' => 100000,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // Cart at 120,000 (100,000 + 20,000) clears min-spend.
    [$order, $items] = makeOrderWithItems([100000, 20000], $this->orgId, $this->brand->id, $this->branch->id);

    $this->orderCoupons->apply($order, $coupon->code);
    $order->refresh();
    expect((float) $order->discount_amount)->toBe(50000.0);

    // #857 — materialise the `items` relation BEFORE the void so applyPricing
    // is handed a stale snapshot to overwrite. Without the load() fix (or with
    // the removed band-aid) this snapshot would price the pre-void cart.
    $order->load('items');
    $order->items->count();

    // Void the 100,000 line → subtotal drops to 20,000, below the
    // 100,000 threshold. The frozen discount would give 50,000 off
    // 20,000 of goods (giveaway) and a −30,000 total.
    $this->orderService->voidItem($order, $items[0]->id, ['void_reason' => 'test trim']);
    $order->refresh();

    expect((float) $order->discount_amount)->toBe(0.0);      // min-spend re-enforced
    expect((float) $order->total_amount)->toBe(20000.0);     // subtotal − 0
    expect((float) $order->total_amount)->toBeGreaterThanOrEqual(0.0); // never negative
});

it('recomputes a percent coupon against the live subtotal when an item is voided', function () {
    // 20% off, no min, no cap.
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'PCT20',
        'discount_type' => 'percent',
        'discount_value' => 20,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // Cart at 200,000 → 20% = 40,000.
    [$order, $items] = makeOrderWithItems([100000, 100000], $this->orgId, $this->brand->id, $this->branch->id);

    $this->orderCoupons->apply($order, $coupon->code);
    $order->refresh();
    expect((float) $order->discount_amount)->toBe(40000.0);

    // #857 — hydrate the stale snapshot before the void (see the fixed-coupon
    // case above).
    $order->load('items');
    $order->items->count();

    // Void one 100,000 line → subtotal 100,000 → 20% should be 20,000,
    // NOT the frozen 40,000 (which would be a real 40% off).
    $this->orderService->voidItem($order, $items[0]->id, ['void_reason' => 'test trim']);
    $order->refresh();

    expect((float) $order->discount_amount)->toBe(20000.0);
    expect((float) $order->total_amount)->toBe(80000.0); // 100,000 − 20,000
});

/**
 * #2154 — sổ đổi (`coupon_redemptions.discount_applied_amount`) phải đi theo
 * khoản giảm đang áp, không đứng lại ở giá trị lúc áp coupon.
 *
 * Hai ca ở trên đã đi qua ĐÚNG kịch bản này từ trước, nhưng chỉ assert cột trên
 * `customer_orders` — nên lệch của sổ đổi sống sót qua cả hai. Đó là lý do lỗi
 * không ai thấy: bài test bao phủ đường đi, nhưng không nhìn vào con số mà
 * Z-report thật sự cộng.
 */
it('#2154: sổ đổi coupon bám theo khoản giảm khi void một dòng', function () {
    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'LEDGER20',
        'discount_type' => 'percent',
        'discount_value' => 20,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    [$order, $items] = makeOrderWithItems([100000, 100000], $this->orgId, $this->brand->id, $this->branch->id);

    $this->orderCoupons->apply($order, $coupon->code);
    $order->refresh();

    $ledgerAmount = fn () => (float) CouponRedemption::query()
        ->where('customer_order_id', $order->id)
        ->whereNull('released_at')
        ->value('discount_applied_amount');

    // Lúc áp: hai sổ khớp nhau — đây là trạng thái duy nhất mà bản cũ cũng đúng.
    expect((float) $order->discount_amount)->toBe(40000.0);
    expect($ledgerAmount())->toBe(40000.0);

    $order->load('items');
    $order->items->count();

    $this->orderService->voidItem($order, $items[0]->id, ['void_reason' => 'test trim']);
    $order->refresh();

    expect((float) $order->discount_amount)->toBe(20000.0);
    expect($ledgerAmount())->toBe(
        20000.0,
        'sổ đổi đứng yên ở 40.000 trong khi đơn giảm 20.000 — Z-report `SUM()` lên '.
        'chính cột này nên nó sẽ khai gấp đôi khoản giảm, dồn theo ca (#2154)',
    );
});

it('#2154: chiết khấu tay trên đơn KHÔNG có coupon không tạo/không chạm sổ đổi nào', function () {
    [$order] = makeOrderWithItems([50000], $this->orgId, $this->brand->id, $this->branch->id);

    // Không coupon ⇒ khoản giảm là chiết khấu tay lúc thanh toán. Nó KHÔNG
    // thuộc về mã coupon nào, nên không được gán tiền vào sổ đổi.
    $order->update(['discount_amount' => 5000]);
    $order->refresh();

    expect((float) $order->discount_amount)->toBe(5000.0);
    expect(CouponRedemption::query()->where('customer_order_id', $order->id)->count())->toBe(0);
});
