<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * #2190 — nhánh CHIẾT KHẤU TAY của "hoàn hết ⇒ tổng ÂM".
 *
 * #2114 đóng nhánh coupon (recompute về 0 khi giỏ co về 0). Chiết khấu tay thì
 * KHÔNG được đánh giá lại — nó là quyết định của con người — nên đường sửa là
 * kẹp phần ÁP DỤNG: `$appliedDiscount = min($discountAmount, $liveSubtotal)`
 * (#2182). Cột `discount_amount` cố ý GIỮ số YÊU CẦU của thu ngân; sổ
 * `order_conditions` và tổng tiền mang số THỰC TẾ.
 *
 * Kịch bản đo của issue: đơn 2 món ¥1.000, `discount_amount = 300` đặt tay lúc
 * checkout, hoàn hết cả hai ⇒ trước kẹp: `subtotal 0 · discount 300 ·
 * total −285`. Tổng ÂM là đơn khẳng định quán nợ ngược khách một khoản khách
 * chưa từng trả (`CustomerOutstandingOrderService` đọc `total − paid`).
 */
function manualDiscountOrder(float $discount): CustomerOrder
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

    // Chiết khấu TAY: ghi thẳng cột như đường checkout làm, không coupon nào.
    $order->update(['discount_amount' => $discount]);
    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));

    return $order->fresh();
}

it('#2190: hoàn HẾT trên đơn chiết khấu tay ⇒ tổng 0, không âm', function () {
    $order = manualDiscountOrder(300.0);

    // Nền: 2.000 − 300 = 1.700 @10% ⇒ tax 170, total 1.870.
    expect((float) $order->total_amount)->toBe(1870.0);

    $originalIds = $order->fresh('items')->items
        ->whereNull('refund_of_item_id')
        ->pluck('id')
        ->all();
    expect($originalIds)->toHaveCount(2);

    foreach ($originalIds as $itemId) {
        app(CustomerOrderService::class)->refundItem(
            $order->fresh('items'),
            (string) $itemId,
            1.0,
            'test hoàn hết (#2190)',
        );
    }
    $order = $order->fresh();

    expect((float) $order->subtotal)->toBe(0.0)
        // Ý định nhập tay giữ ở `manual_discount_amount`; accessor `discount_amount`
        // phản ánh sổ (khoản giảm áp dụng được — 0 sau hoàn hết).
        ->and((float) $order->manual_discount_amount)->toBe(300.0)
        ->and((float) $order->discount_amount)->toBe(0.0)
        ->and((float) $order->tax_amount)->toBe(0.0)
        ->and((float) $order->total_amount)->toBe(
            0.0,
            'tổng ÂM = quán nợ ngược khách khoản chưa từng trả (#2190); '
            .'kẹp appliedDiscount = min(discount, liveSubtotal) phải giữ nó ở 0',
        );
});

it('#2190: hoàn MỘT PHẦN thì chiết khấu tay vẫn áp trên phần còn giữ', function () {
    $order = manualDiscountOrder(300.0);

    $firstId = (string) $order->fresh('items')->items
        ->whereNull('refund_of_item_id')
        ->first()->id;
    app(CustomerOrderService::class)->refundItem(
        $order->fresh('items'), $firstId, 1.0, 'hoàn một món',
    );
    $order = $order->fresh();

    // Giỏ sống còn 1.000; 300 vẫn nhỏ hơn nền nên áp đủ: (1.000 − 300) @10%.
    expect((float) $order->discount_amount)->toBe(300.0)
        ->and((float) $order->total_amount)->toBe(770.0);
});
