<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPricingCalculator;
use Illuminate\Support\Str;

/**
 * #2079 — hoàn tiền phải TÍNH LẠI coupon, y như huỷ món.
 *
 * ## Cơ chế
 *
 * `rateSubtotalsForOrder()` cố ý BỎ QUA dòng hoàn — chúng mang ảnh chụp thuế đã
 * âm sẵn và không được đi qua bộ phân bổ ≥0. Đúng cho việc tính thuế.
 *
 * Nhưng cùng con số ấy bị dùng lại làm nền TÍNH LẠI COUPON, và ở vai đó nó sai:
 * `$liveSubtotal` vẫn là giỏ TRƯỚC hoàn, nên `recomputeDiscountForOrder()` đánh
 * giá điều kiện coupon trên một giỏ hàng không còn tồn tại.
 *
 * ## Luật đã có sẵn, refund chỉ chưa được dẫn qua nó
 *
 * #550 đã dựng luật này cho VOID, và comment của chính min-spend guard viết:
 *
 * > *"a coupon that cleared min_order_subtotal at apply time must NOT keep
 * > discounting once the cart shrinks below it — otherwise a fixed coupon
 * > becomes a house giveaway that bypasses the threshold silently."*
 *
 * Hoàn hàng làm giỏ co lại. Nên câu ấy đã bao gồm refund từ đầu.
 *
 * ## Đo được — quán mất tiền
 *
 * Coupon cố định ¥500, ngưỡng ¥1.500. Đơn 2 món ¥1.000 ⇒ đủ điều kiện, khách
 * trả ¥1.650. Hoàn một món ⇒ giỏ còn ¥1.000, DƯỚI ngưỡng:
 *
 *     trước sửa:  giảm 500 · tổng 575
 *     sau sửa:    giảm   0 · tổng 1.125
 *
 * Chênh ¥550 trên một đơn.
 *
 * ## Đây là ĐÁNH GIÁ LẠI, không phải PHÂN BỔ THEO TỈ LỆ
 *
 * Ghi rõ vì tôi từng nhầm chính chỗ này khi mở issue: hai mô hình khác nhau, và
 * repo chọn mô hình thứ nhất.
 *
 * - **Đánh giá lại** (repo dùng): điều kiện coupon được xét lại trên giỏ hiện
 *   tại. Coupon ¥500 ngưỡng 0 trên giỏ còn ¥1.000 vẫn giảm đủ ¥500 — đúng, vì
 *   đó là thứ coupon hứa.
 * - **Phân bổ theo tỉ lệ**: khoản giảm chia đều theo giá trị hàng, hoàn hàng thì
 *   trả lại kèm phần giảm của nó.
 *
 * Mô hình thứ nhất nhất quán với đường VOID và với chính điều khoản của coupon.
 * Chọn mô hình thứ hai là một quyết định nghiệp vụ khác, không phải bản sửa lỗi.
 */
function refundCouponOrder(int $minSpend, string $type = 'fixed', int $value = 500): array
{
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ]);

    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );

    $coupon = Coupon::factory()->create([
        'brand_id' => $order->brand_id,
        'organization_id' => $order->organization_id,
        'code' => 'RF'.substr((string) $order->id, 0, 6),
        'discount_type' => $type,
        'discount_value' => $value,
        'max_discount_cap' => null,
        'min_order_subtotal' => $minSpend,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
        'status' => 'draft',
    ]);

    $order->update([
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'discount_amount' => $type === 'fixed' ? $value : 0,
    ]);

    foreach ([1000, 1000] as $price) {
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => $price,
            'topping_subtotal' => 0,
            'subtotal' => $price,
            'tax_rate' => 10,
            'tax_amount' => 0,
            'status' => 'served',
        ]);
    }

    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    return [$fresh->fresh('items'), $coupon];
}

function refundFirstItem(CustomerOrder $order): CustomerOrder
{
    app(CustomerOrderService::class)->refundItem(
        $order->fresh('items'),
        (string) $order->fresh('items')->items->firstWhere('refund_of_item_id', null)->id,
        1.0,
        'test',
    );

    return $order->fresh();
}

it('hoàn hàng làm giỏ tụt DƯỚI ngưỡng ⇒ coupon về 0', function () {
    // Ca trung tâm. Trước bản sửa: giảm giữ nguyên 500 trên giỏ 1.000 — coupon
    // ngưỡng 1.500 vẫn giảm cho một giỏ không còn đủ điều kiện, và quán mất 550.
    [$order] = refundCouponOrder(minSpend: 1500);

    expect((float) $order->discount_amount)->toBe(500.0);
    expect((float) $order->total_amount)->toBe(1650.0);

    $order = refundFirstItem($order);

    expect((float) $order->subtotal)->toBe(1000.0);
    expect((float) $order->discount_amount)->toBe(0.0, 'coupon vẫn giảm dù giỏ đã dưới ngưỡng');
});

it('hoàn hàng mà giỏ VẪN đủ ngưỡng ⇒ coupon giữ nguyên', function () {
    // Mặt kia: bản sửa không được "chữa" quá tay. Coupon ngưỡng 0 thì giỏ còn
    // 1.000 vẫn đủ điều kiện, và ¥500 là đúng thứ coupon hứa.
    [$order] = refundCouponOrder(minSpend: 0);

    $order = refundFirstItem($order);

    expect((float) $order->subtotal)->toBe(1000.0);
    expect((float) $order->discount_amount)->toBe(500.0, 'coupon bị cắt oan dù vẫn đủ điều kiện');
});

it('coupon PHẦN TRĂM được tính lại trên giỏ đã co', function () {
    // 20% trên 2.000 = 400. Sau khi hoàn một món, giỏ còn 1.000 ⇒ 200.
    // Không tính lại thì khách hưởng 400 trên một giỏ 1.000 — tức 40%.
    [$order] = refundCouponOrder(minSpend: 0, type: 'percent', value: 20);

    expect((float) $order->discount_amount)->toBe(400.0);

    $order = refundFirstItem($order);

    expect((float) $order->discount_amount)->toBe(200.0);
});

it('giảm giá TAY (không coupon) KHÔNG bị đụng tới', function () {
    // `recomputeDiscountForOrder` trả null khi đơn không mang coupon, nên khoản
    // giảm tay của thu ngân giữ nguyên. Đó là quyết định của con người, không
    // phải một điều khoản để suy lại — bản sửa không được cuốn nó theo.
    $order = CustomerOrder::factory()->create([
        'status' => 'open', 'is_tax_included' => false, 'discount_amount' => 500,
        'coupon_id' => null, 'tax_rounding_mode' => 'round', 'tax_rounding_decimals' => 0,
    ]);
    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id, 'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0, 'currency_code' => 'JPY', 'tax_rounding_mode' => 'round',
        ]
    );
    foreach ([1000, 1000] as $price) {
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id, 'quantity' => 1, 'unit_price' => $price,
            'topping_subtotal' => 0, 'subtotal' => $price, 'tax_rate' => 10,
            'tax_amount' => 0, 'status' => 'served',
        ]);
    }
    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    $fresh = refundFirstItem($fresh->fresh('items'));

    expect((float) $fresh->discount_amount)->toBe(500.0);
});

it('dòng hoàn ĐÃ HUỶ không được trừ khỏi giỏ sống', function () {
    // `refundedSubtotalFor()` dùng đúng bộ lọc của `applyRefundLines()`: bỏ qua
    // dòng hoàn đã bị huỷ. Thiếu bộ lọc ấy thì một lần hoàn đã huỷ vẫn kéo giỏ
    // xuống và cắt coupon oan.
    [$order] = refundCouponOrder(minSpend: 1500);
    $order = refundFirstItem($order);
    expect((float) $order->discount_amount)->toBe(0.0);

    $refundLine = $order->fresh('items')->items->firstWhere(
        fn ($i) => $i->refund_of_item_id !== null,
    );
    $refundLine->update(['status' => 'voided']);

    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));
    $order = $order->fresh();

    // Hoàn tiền bị huỷ ⇒ giỏ trở lại 2.000 ⇒ coupon đủ điều kiện lại.
    expect((float) $order->discount_amount)->toBe(500.0);
});

it('#2257: dòng hoàn NULL tax_rate không được trừ khỏi giỏ sống', function () {
    // `refundedSubtotalFor()` phải dùng đúng bộ lọc của `applyRefundLines()`:
    // dòng hoàn thiếu ảnh chụp tax_rate là input hỏng — bên thuế đã DROP nó
    // (không đổi total), thì phép kẹp coupon cũng không được cho nó co giỏ
    // sống. Thiếu guard: giỏ bị kéo 2.000 → 1.000 dưới ngưỡng 1.500, coupon
    // bị cắt oan trong khi thuế/total không ghi nhận dòng hoàn đó.
    //
    // #2411 — ca này trước đây dựng hàng DB `tax_rate => null`; cột nay là NOT
    // NULL nên bước đó không chạy được. Guard KHÔNG bị gỡ (parity Go:
    // `order_items` của máy trạm vẫn nullable — `038_tax_types.sql`), nên nó
    // được đo ở đúng tầng nó sống: `refundedSubtotalFor()` nhận `iterable`, hai
    // dòng dựng trong bộ nhớ là đủ và không cần hàng nào xuống DB.
    $stamped = new CustomerOrderItem;
    $stamped->forceFill(['refund_of_item_id' => (string) Str::uuid(), 'subtotal' => -1000, 'tax_rate' => 10, 'status' => 'served']);

    $unstamped = new CustomerOrderItem;
    $unstamped->forceFill(['refund_of_item_id' => (string) Str::uuid(), 'subtotal' => -1000, 'tax_rate' => null, 'status' => 'served']);

    expect(app(OrderPricingCalculator::class)->refundedSubtotalFor([$stamped, $unstamped]))->toBe(
        -1000.0,
        'dòng hoàn NULL-rate bị DROP khỏi thuế nhưng vẫn co giỏ sống — coupon bị cắt lệch với total (#2257)',
    );
});

/**
 * #2154 — đường HOÀN cũng phải kéo sổ đổi theo, không riêng đường void.
 *
 * Fixture ở file này cố ý buộc coupon bằng `$order->update([...])` chứ không đi
 * qua `apply()`, nên nó KHÔNG có hàng `coupon_redemptions` nào — đó là lý do
 * lệch của sổ đổi vô hình ở đây. Ca này dựng hàng sổ tường minh (đúng kiểu
 * `CouponHqControllerTest` / `CouponConcurrencyTest` đang dùng; factory không
 * dùng được vì nó sinh `released_at` khác null, mà mọi truy vấn đọc đều lọc
 * `whereNull('released_at')`).
 */
it('#2154: hoàn hàng làm coupon về 0 thì sổ đổi cũng về 0', function () {
    [$order, $coupon] = refundCouponOrder(minSpend: 1500);

    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'customer_order_id' => $order->id,
        'customer_id' => null,
        'discount_applied_amount' => 500,
        // NOT NULL trong lược đồ — ảnh chụp điều khoản coupon lúc đổi.
        'coupon_snapshot' => ['code' => $coupon->code, 'discount_type' => 'fixed', 'discount_value' => 500],
        'redeemed_at' => now(),
        'redeemed_by_user_id' => null,
        'redeemed_via' => 'pos',
    ]);

    $ledgerAmount = fn () => (float) CouponRedemption::query()
        ->where('customer_order_id', $order->id)
        ->whereNull('released_at')
        ->value('discount_applied_amount');

    expect((float) $order->discount_amount)->toBe(500.0);
    expect($ledgerAmount())->toBe(500.0);

    $order = refundFirstItem($order);

    expect((float) $order->discount_amount)->toBe(0.0);
    expect($ledgerAmount())->toBe(
        0.0,
        'đơn hết đủ điều kiện coupon nhưng sổ đổi vẫn khai 500 — Z-report cộng '.
        'khoản giảm cho một coupon không còn áp (#2154)',
    );
});
