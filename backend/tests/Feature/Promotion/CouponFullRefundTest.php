<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * #2114 — giỏ CO VỀ 0 và giỏ CHƯA HIỆN HỮU là hai chuyện khác nhau.
 *
 * `applyPricing` chỉ tính lại coupon khi `$liveSubtotal > 0`. Điều kiện ấy có
 * lý do thật — đơn máy trạm vừa sync (chưa kịp có `order_items`) và đơn headless
 * vừa áp coupon không có giỏ nào để tính lại — nhưng nó gộp luôn ca giỏ đã co về
 * 0 vào cùng nhánh "giữ nguyên", và khoản giảm treo lại trên một giỏ rỗng.
 *
 * File riêng chứ không nối vào `CouponRecomputeOnRefundTest`: file đó đang nằm
 * trong một PR khác đang mở (#2154 / PR #2181), và hai PR cùng nối vào cuối một
 * file là conflict chắc chắn lúc merge.
 */
function fullRefundCouponOrder(string $type = 'fixed', int $value = 500, int $minSpend = 0): array
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
        'code' => 'FR'.substr((string) $order->id, 0, 6),
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

    return [$order->fresh('items'), $coupon];
}

function fullRefundAddItems(CustomerOrder $order): void
{
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

    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));
}

it('#2114: hoàn HẾT mọi món ⇒ khoản giảm về 0, tổng không âm', function () {
    [$order] = fullRefundCouponOrder();
    fullRefundAddItems($order);
    $order = $order->fresh();

    expect((float) $order->discount_amount)->toBe(500.0);
    expect((float) $order->total_amount)->toBe(1650.0);

    // Hoàn ĐÍCH DANH từng dòng gốc — hoàn theo "dòng gốc đầu tiên" không dùng
    // được ở đây: sau lần một dòng đó đã hoàn hết (`refunded_quantity ==
    // quantity`) nên lần hai bị chặn 422.
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
            'test hoàn hết',
        );
    }
    $order = $order->fresh();

    expect((float) $order->subtotal)->toBe(0.0);
    expect((float) $order->discount_amount)->toBe(
        0.0,
        'giỏ rỗng mà coupon vẫn áp — khoản giảm không được lớn hơn thứ nó đang giảm',
    );

    // CỐ Ý vẫn chỉ ghim `>= 0` — đó là phạm vi của #2114 (tổng không được ÂM).
    //
    // Lúc #2114 vào, ca này còn dư đúng phần thuế (`tax 75 · total 75`) vì dòng
    // SỐNG được đóng dấu lại theo nền hiện tại (hết coupon ⇒ 100 + 100) trong
    // khi hai dòng HOÀN giữ ảnh chụp lúc coupon còn giảm (−75 rồi −50). Đó là
    // lỗi RIÊNG, đã sửa ở **#2182**; tính chất "hoàn hết ⇒ thuế 0 và tổng 0"
    // được ghim bằng số cụ thể ở `CouponFullRefundTaxTest`.
    //
    // Ghim `=== 0` ở đây sẽ biến bài test này thành nơi một lỗi KHÁC quyết định
    // đỏ/xanh — đúng thứ đã tách hai issue ra làm hai.
    expect((float) $order->total_amount)->toBeGreaterThanOrEqual(
        0.0,
        'tổng ÂM nghĩa là quán nợ ngược khách một khoản khách chưa từng trả (#2114)',
    );
});

/**
 * Mặt kia của bánh cóc — nới điều kiện quá tay thì chính hai ca mà điều kiện cũ
 * bảo vệ sẽ mất khoản giảm, và triệu chứng lúc đó khó truy hơn hẳn.
 */
it('#2114: đơn CHƯA có dòng nào vẫn GIỮ khoản giảm đã áp', function () {
    [$order] = fullRefundCouponOrder();

    // Coupon đã áp, nhưng CHƯA có `order_items` nào — đúng trạng thái của một
    // đơn máy trạm vừa sync lên trước khi các dòng của nó tới.
    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));
    $order = $order->fresh();

    expect($order->fresh('items')->items)->toHaveCount(0);
    expect((float) $order->discount_amount)->toBe(
        500.0,
        'đơn chưa có giỏ thì không có gì để tính lại — nới điều kiện quá tay sẽ '.
        'xoá mất khoản giảm của đơn máy trạm vừa sync (#2114)',
    );
});
