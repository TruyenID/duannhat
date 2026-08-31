<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * #2182 — hoàn HẾT một giỏ có coupon phải để lại thuế 0 và tổng 0.
 *
 * ## Lỗi: hai NỀN GIẢM GIÁ khác nhau ở hai phía của cùng một phép trừ
 *
 * Đơn 2 món ¥1.000 @10%, coupon cố định ¥500 (ngưỡng 0), hoàn lần lượt cả hai:
 *
 *     dòng SỐNG  qty  1  subtotal  1000  tax  100     ← đóng dấu lại, coupon đã chết
 *     dòng SỐNG  qty  1  subtotal  1000  tax  100
 *     dòng HOÀN  qty -1  subtotal -1000  tax  -75     ← chụp lúc coupon còn giảm
 *     dòng HOÀN  qty -1  subtotal -1000  tax  -50
 *                                        ─────
 *                                          75         ← thuế còn đọng
 *
 * Phía HOÀN mang thuế đã trừ coupon (nền 750 rồi 500), phía SỐNG được định giá
 * lại **không còn** coupon (nền 1.000 mỗi dòng). Hai nền khác nhau nên không
 * triệt tiêu.
 *
 * ## Vì sao nền GỘP mới là nền đúng cho dòng hoàn
 *
 * Repo chọn mô hình **ĐÁNH GIÁ LẠI** cho coupon, không phải **PHÂN BỔ THEO TỈ
 * LỆ** (#2079 viết thẳng lựa chọn ấy, #550 dựng, #2114 chốt tiếp). Nghĩa là khi
 * trả lại một món, coupon **không** đi theo món ấy — nó dồn hết sang phần hàng
 * còn giữ:
 *
 *     mua:      2 × 1.000 − 500 = 1.500 + thuế 150 = 1.650 khách trả
 *     trả 1 món: còn 1.000 − 500 =   500 + thuế  50 =   550 khách nợ
 *     ⇒ phải hoàn 1.100 = 1.000 (gộp) + 100 (thuế trên gộp)
 *
 * Nên dòng hoàn phải mang thuế của phần GỘP đã trả lại. Trộn hai mô hình — thuế
 * hoàn thì pro-rata, coupon thì đánh giá lại — là chỗ ¥75 sinh ra.
 *
 * Ảnh chụp của dòng hoàn KHÔNG bị viết lại; chỉ cách TÍNH nó lúc tạo đổi.
 */
function fullRefundTaxOrder(
    string $type = 'fixed',
    float $value = 500,
    bool $includeTax = false,
    float $unitPrice = 1000,
    float $manualDiscount = 0,
): array {
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => $includeTax,
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

    if ($manualDiscount > 0) {
        $order->update(['discount_amount' => $manualDiscount]);
    } else {
        $coupon = Coupon::factory()->create([
            'brand_id' => $order->brand_id,
            'organization_id' => $order->organization_id,
            'code' => 'FT'.substr((string) $order->id, 0, 6),
            'discount_type' => $type,
            'discount_value' => $value,
            'max_discount_cap' => null,
            'min_order_subtotal' => 0,
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
    }

    foreach ([$unitPrice, $unitPrice] as $price) {
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

    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));

    return [$order->fresh('items'), app(CustomerOrderService::class)];
}

/** id của các dòng GỐC (không phải dòng hoàn), theo thứ tự tạo. */
function fullRefundTaxOriginalIds(CustomerOrder $order): array
{
    return $order->fresh('items')->items
        ->whereNull('refund_of_item_id')
        ->pluck('id')
        ->all();
}

/** Bộ ba con số quyết định: (thuế, tổng, giảm giá). */
function fullRefundTaxMoney(CustomerOrder $order): array
{
    $fresh = $order->fresh();

    return [
        'subtotal' => (float) $fresh->subtotal,
        'discount' => (float) $fresh->discount_amount,
        'tax' => (float) $fresh->tax_amount,
        'total' => (float) $fresh->total_amount,
    ];
}

it('coupon CỐ ĐỊNH: hoàn hết ⇒ thuế 0 và tổng 0', function () {
    // 2 × ¥1.000 @10%, coupon ¥500 ⇒ nền 1.500, thuế 150, khách trả 1.650.
    [$order, $service] = fullRefundTaxOrder();

    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 2000.0, 'discount' => 500.0, 'tax' => 150.0, 'total' => 1650.0,
    ]);

    [$firstId, $secondId] = fullRefundTaxOriginalIds($order);

    // Hoàn món thứ nhất: coupon dồn hết sang món còn lại ⇒ khách còn nợ
    // 1.000 − 500 = 500 + thuế 50 = 550, tức phải hoàn 1.100 (gộp + thuế gộp).
    $service->refundItem($order->fresh('items'), (string) $firstId, 1.0, 'test');

    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 1000.0, 'discount' => 500.0, 'tax' => 50.0, 'total' => 550.0,
    ]);

    $service->refundItem($order->fresh('items'), (string) $secondId, 1.0, 'test');

    // Trước bản sửa: tax 75 · total 75 — đơn không bán gì mà vẫn khai thuế.
    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 0.0,
    ]);
});

it('coupon PHẦN TRĂM: hoàn hết ⇒ thuế 0 và tổng 0', function () {
    // 25% trên 2.000 = 500 ⇒ nền 1.500, thuế 150.
    [$order, $service] = fullRefundTaxOrder(type: 'percent', value: 25);

    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 2000.0, 'discount' => 500.0, 'tax' => 150.0, 'total' => 1650.0,
    ]);

    [$firstId, $secondId] = fullRefundTaxOriginalIds($order);

    // 25% được đánh giá lại trên giỏ còn 1.000 ⇒ giảm 250, nền 750, thuế 75.
    $service->refundItem($order->fresh('items'), (string) $firstId, 1.0, 'test');

    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 1000.0, 'discount' => 250.0, 'tax' => 75.0, 'total' => 825.0,
    ]);

    $service->refundItem($order->fresh('items'), (string) $secondId, 1.0, 'test');

    // Trước bản sửa: tax 50 · total 50.
    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 0.0,
    ]);
});

it('内税 (giá đã gồm thuế): hoàn hết giỏ có coupon ⇒ thuế 0 và tổng 0', function () {
    // 2 × ¥1.100 GỒM thuế 10% ⇒ nội thuế nhóm 200. Coupon ¥500 ⇒ nền gộp 1.700,
    // nội thuế 1.700 − round(1.700/1,1) = 1.700 − 1.545 = 155.
    [$order, $service] = fullRefundTaxOrder(includeTax: true, unitPrice: 1100);

    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 2200.0, 'discount' => 500.0, 'tax' => 155.0, 'total' => 1700.0,
    ]);

    foreach (fullRefundTaxOriginalIds($order) as $id) {
        $service->refundItem($order->fresh('items'), (string) $id, 1.0, 'test');
    }

    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 0.0,
    ]);
});

it('giảm giá TAY: hoàn hết cũng phải về 0 — khoản giảm không lớn hơn giỏ SỐNG', function () {
    // Giảm giá tay KHÔNG được đánh giá lại (nó là quyết định của con người, xem
    // `CouponRecomputeOnRefundTest`), nhưng phần ÁP DỤNG ĐƯỢC vẫn không được lớn
    // hơn thứ nó đang giảm. Giỏ co về 0 ⇒ giảm được 0.
    //
    // Trước bản sửa: tax 25 · total −475 — đơn khẳng định quán NỢ khách ¥475
    // khách chưa từng trả (`total_amount − paid_amount` là số dư nợ thật,
    // `CustomerOutstandingOrderService`). Đây là chính triệu chứng #2114 chữa
    // cho coupon; đường giảm giá TAY thì chưa ai đi qua.
    [$order, $service] = fullRefundTaxOrder(manualDiscount: 500);

    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 2000.0, 'discount' => 500.0, 'tax' => 150.0, 'total' => 1650.0,
    ]);

    foreach (fullRefundTaxOriginalIds($order) as $id) {
        $service->refundItem($order->fresh('items'), (string) $id, 1.0, 'test');
    }

    // #2041 — `discount_amount` đọc từ sổ (khoản giảm ÁP DỤNG). Ý định nhập tay
    // nằm ở `manual_discount_amount` — xem `ConditionLedgerEdgeCasesTest`.
    expect((float) $order->fresh()->manual_discount_amount)->toBe(500.0);
    expect(fullRefundTaxMoney($order))->toBe([
        'subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 0.0,
    ]);

    expect((float) $order->fresh()->conditions()->where('type', 'discount')->sum('amount'))
        ->toBe(0.0, 'sổ vẫn khai một khoản giảm trên giỏ đã trả hết');
});

it('Σ ảnh chụp thuế từng dòng luôn bằng tax_amount của đơn, ở MỌI bước hoàn', function () {
    // Bất biến mà mọi báo cáo cộng `items.tax_amount` (Z-report, tax_breakdown)
    // dựa vào. Nó vỡ ngay ở bước hoàn ĐẦU TIÊN chứ không đợi tới lúc hoàn hết:
    // dòng sống được đóng dấu bằng mẫu số SAU hoàn (1.000) trong khi tử số vẫn
    // là dòng GỘP (1.000 mỗi dòng) — hai tập khác nhau.
    [$order, $service] = fullRefundTaxOrder();

    $sumLines = fn () => (float) $order->fresh('items')->items->sum(
        fn ($i) => (float) $i->tax_amount,
    );

    expect($sumLines())->toBe((float) $order->fresh()->tax_amount);

    foreach (fullRefundTaxOriginalIds($order) as $id) {
        $service->refundItem($order->fresh('items'), (string) $id, 1.0, 'test');

        expect($sumLines())->toBe(
            (float) $order->fresh()->tax_amount,
            'Σ thuế từng dòng lệch khỏi tax_amount của đơn — báo cáo cộng dòng sẽ '.
            'ra một con số khác con số trên đơn',
        );
    }
});
