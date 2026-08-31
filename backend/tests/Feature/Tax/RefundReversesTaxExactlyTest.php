<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * #2117 — khoản HOÀN phải ĐẢO NGƯỢC ảnh chụp thuế của dòng, không được TÍNH LẠI.
 *
 * ## Tiền đề của issue SAI — đo rồi, và đây là bằng chứng
 *
 * Issue lo rằng half-up của hệ này không đối xứng qua 0 (`floor(q + 0.5)` cho
 * `123.5 → 124` nhưng `-123.5 → -123`), nên khoản hoàn ở biên `.5` trả về thiếu.
 *
 * Bất đối xứng ấy **không với tới được**:
 *
 *  1. `refundItem()` làm tròn trên TRỊ TUYỆT ĐỐI rồi mới đảo dấu, tức đã là
 *     half-away-from-zero theo cấu tạo;
 *  2. `applyRefundLines()` cộng thẳng ảnh chụp đã âm sẵn, không làm tròn lại;
 *  3. mọi chỗ gọi `roundHalfUpToStep` còn lại đều nhận biểu thức không âm.
 *
 * ## Bài test này ghim tính chất KHÁC, vì tính chất kia không đo được
 *
 * Đã thử và **thất bại**: một bài "thu rồi hoàn thì thuế về 0" trên đơn MỘT dòng
 * xanh dưới MỌI phép tiêm lỗi — kể cả khi thay hẳn phép đảo ảnh chụp bằng phép
 * tính lại từ tỉ lệ. Lý do: với một dòng thì `round(subtotal × rate)` **trùng
 * đúng** thuế đã thu, nên hai cách tính không phân biệt được. Bài test ấy xanh
 * vĩnh viễn — đúng loại test vô nghĩa mà nghi thức chiều-ngược sinh ra để bắt.
 *
 * Chỗ hai cách tính TÁCH NHAU là phân bổ **largest-remainder**. Thuế làm tròn
 * MỘT lần cho cả nhóm mức rồi chia xuống từng dòng, nên một dòng có thể mang số
 * thuế mà **không dựng lại được từ chính nó**. Đo trên ba dòng ¥1.005 @ 10%:
 *
 *     nhóm: 3015 × 10% = 301,5 → 302
 *     chia: 101 / 101 / 100          ← dòng thứ ba KHÁC round(100,5) = 101
 *
 * Hoàn dòng thứ ba phải trả lại **100**. Ai đó "đơn giản hoá" `refundItem` thành
 * tính lại từ `subtotal × tax_rate` sẽ trả **101** — quán mất 1 đồng, im lặng,
 * và mọi bài test đơn-một-dòng vẫn xanh.
 *
 * `rounding_golden.json` (#2082) ghim chiều ngược lại: `-123.5 → -123` là hành vi
 * CỐ Ý của primitive. Không mâu thuẫn — primitive bất đối xứng, đường hoàn tiền
 * không đi qua chỗ ấy.
 */

/** Ba dòng cùng giá, cùng mức — ép phân bổ largest-remainder chia lẻ. */
function refundParityOrder(float $unitPrice, float $rate, int $lines = 3): array
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

    for ($i = 0; $i < $lines; $i++) {
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'topping_subtotal' => 0,
            'subtotal' => $unitPrice,
            'tax_rate' => $rate,
            'tax_amount' => 0,
            'status' => 'served',
        ]);
    }

    $service = app(CustomerOrderService::class);
    $service->refreshOrderTotals($order->fresh('items'));

    return [$order->fresh('items'), $service];
}

/** Σ thuế của MỌI dòng (thu + hoàn). */
function refundParityTaxSum(CustomerOrder $order): float
{
    return (float) $order->fresh('items')->items->sum(fn ($i) => (float) $i->tax_amount);
}

/** Dòng mang thuế THẤP NHẤT — dòng bị largest-remainder cắt phần lẻ. */
function refundParityShortChangedLine(CustomerOrder $order): CustomerOrderItem
{
    return $order->fresh('items')->items
        ->filter(fn ($i) => $i->refund_of_item_id === null)
        ->sortBy(fn ($i) => (float) $i->tax_amount)
        ->first();
}

/**
 * Đơn MỘT dòng nhiều số lượng — nền của mọi ca hoàn TỪNG PHẦN.
 *
 * Cùng cấu hình với `refundParityOrder` (JPY step 1, `round`, không giảm giá,
 * không phí phục vụ); khác ở chỗ nó trả về CHÍNH dòng đó, vì hoàn từng phần
 * cần gọi `refundItem` nhiều lần trên một `item->id`.
 *
 * @return array{0: CustomerOrder, 1: CustomerOrderService, 2: CustomerOrderItem}
 */
function refundParityOneLineOrder(float $unitPrice, float $quantity, float $rate): array
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

    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'topping_subtotal' => 0,
        'subtotal' => $unitPrice * $quantity,
        'tax_rate' => $rate,
        'tax_amount' => 0,
        'status' => 'served',
    ]);

    $service = app(CustomerOrderService::class);
    $service->refreshOrderTotals($order->fresh('items'));

    return [$order->fresh('items'), $service, $item];
}

/** Dòng HOÀN đầu tiên của đơn. */
function refundParityRefundLine(CustomerOrder $order): CustomerOrderItem
{
    return $order->fresh('items')->items
        ->first(fn ($i) => $i->refund_of_item_id !== null);
}

it('hoàn dòng bị cắt phần lẻ thì trả lại ĐÚNG thuế dòng đó mang, không phải thuế tính lại', function () {
    [$order, $service] = refundParityOrder(1005.0, 10.0);

    $line = refundParityShortChangedLine($order);
    $lineTax = (float) $line->tax_amount;
    $chargedTotal = refundParityTaxSum($order);

    // Điều kiện tiên quyết: nếu phân bổ KHÔNG chia lẻ thì bài test này không đo
    // được gì — nói ra thay vì xanh giả.
    $recomputed = round((float) $line->subtotal * (float) $line->tax_rate / 100.0);
    expect($lineTax)->not->toEqual($recomputed);

    $service->refundItem($order, $line->id, 1.0, 'test');

    expect(refundParityTaxSum($order))->toBe($chargedTotal - $lineTax, sprintf(
        "Hoàn dòng mang thuế %s nhưng Σ không giảm đúng chừng ấy.\n".
        'Nhiều khả năng refundItem đang TÍNH LẠI thuế (%s) thay vì đảo ảnh chụp.',
        $lineTax,
        $recomputed,
    ));
});

it('hoàn TỪNG PHẦN ở đúng biên .5 làm tròn RA XA 0 — `abs()` trong refundItem là load-bearing', function () {
    // Đây là ca DUY NHẤT trong file chạm được biên `.5` mà #2117 lo, và là bài
    // test duy nhất trong repo phân biệt được `abs($perQtyTax)` với `$perQtyTax`.
    //
    // 2 món × ¥1.005 @10% → nhóm 2010 × 10% = 201, số CHẴN. Hoàn 1 món:
    //
    //     perQtyTax = 201 × 1 / 2 = 100,5          ← đúng biên
    //     có abs():  -1 × round( 100,5) = -101      (half-away-from-zero)
    //     bỏ abs():       round(-100,5) = -100      (half-up kéo về +∞)
    //
    // Quán trả THIẾU khách 1 đồng, mỗi lần, im lặng — chính lỗi #2117 mô tả.
    // Ba bài còn lại không bắt được: chúng đều hoàn TRỌN một dòng qty=1, nên
    // `perQtyTax` bằng đúng `tax_amount` — một số nguyên, không bao giờ chạm .5.
    [$order, $service, $item] = refundParityOneLineOrder(1005.0, 2.0, 10.0);

    // Tiền đề: không ra 201 chẵn thì phần chia không rơi vào .5 và bài này
    // không đo được gì — nói ra thay vì xanh giả.
    expect((float) $item->fresh()->tax_amount)->toBe(201.0);

    $service->refundItem($order, $item->id, 1.0, 'test');

    expect((float) refundParityRefundLine($order)->tax_amount)->toBe(-101.0, implode("\n", [
        'Khoản hoàn ở biên .5 phải làm tròn RA XA 0 (-101), không phải về +∞ (-100).',
        '`abs()` trong WritesCustomerOrders::refundItem là chỗ giữ tính chất đó —',
        'bỏ nó đi thì primitive half-up bất đối xứng lộ ra đúng như #2117 tiên đoán.',
    ]));
});

it('hoàn HẾT mọi dòng thì thuế về đúng 0', function () {
    [$order, $service] = refundParityOrder(1005.0, 10.0);

    foreach ($order->items->pluck('id') as $id) {
        $service->refundItem($order, $id, 1.0, 'test');
    }

    expect(refundParityTaxSum($order))->toBe(0.0, 'hoàn hết mà Σ thuế không về 0');
});

/**
 * Khoảng lệch khi hoàn TỪNG PHẦN một dòng nhiều số lượng — nay là **0** (#2133).
 *
 * Hằng số ở lại thay vì bị xoá: nó là bánh cóc HAI CHIỀU, và ở giá trị 0 thì cả
 * hai chiều đều nói cùng một điều — "hoàn hết phải trả lại đúng số đã thu". Xoá
 * nó đi là bỏ mất bài kiểm duy nhất cho tính chất ấy trên đường hoàn từng phần.
 */
const PARTIAL_REFUND_KNOWN_DRIFT = 0.0;

it('hoàn TỪNG PHẦN nhiều lần trả lại ĐÚNG số đã thu, không dư không thiếu', function () {
    // Lỗi cũ tìm ra trong lúc bác bỏ tiền đề của #2117, và NGƯỢC chiều issue lo:
    // không phải trả thiếu, mà trả DƯ.
    //
    // Một dòng 3 món ¥1.005 @ 10%: thu 302 (3015 × 10% = 301,5 → 302). Hoàn ba
    // lần 1 món. Bản cũ làm tròn ĐỘC LẬP mỗi lần: `302 × 1 / 3 = 100,67` → 101,
    // ba lần = 303 ⇒ quán trả cho khách nhiều hơn đã thu 1 đồng.
    //
    // #2133 chuyển sang làm tròn TỔNG LUỸ KẾ rồi lấy hiệu (soi gương
    // `allocateGroupTax` ở phía thu, vốn làm tròn một lần cho cả nhóm):
    //
    //     lần 1: round(302×1/3)=101 − 0   = −101
    //     lần 2: round(302×2/3)=201 − 101 = −100
    //     lần 3: round(302×3/3)=302 − 201 = −101
    //                                Σ = −302, khớp đúng đã thu
    //
    // Tính chất tổng quát: mốc cuối là `round(taxTotal)` = chính nó, nên Σ mọi
    // lần hoàn LUÔN bằng `tax_amount` khi hoàn hết — với MỌI cách chia nhỏ.
    //
    // Cùng họ với lỗi làm tròn TỪNG DÒNG mà インボイス cấm ở phía thuế và
    // plan-043 đã dọn — chỉ khác là nó nằm trên đường ĐẢO tiền, nơi không ai nhìn.
    [$order, $service, $item] = refundParityOneLineOrder(1005.0, 3.0, 10.0);

    foreach ([1.0, 1.0, 1.0] as $qty) {
        $service->refundItem($order, $item->id, $qty, 'test');
    }

    $drift = refundParityTaxSum($order);

    // Quy ước dấu, ĐO chứ không suy: Σ thuế trên đơn = (thu) − (đã hoàn).
    // Σ < 0 ⇔ hoàn NHIỀU HƠN thu ⇔ quán trả DƯ (ba dòng −101 trên khoản thu
    // 302 → Σ = −1). Σ > 0 ⇔ trả THIẾU.
    //
    // Vòng review đầu của #2133 phát hiện hai thông điệp này bị ĐẢO so với bản
    // trên `dev`: bánh cóc bắn đúng vế nhưng in ra chẩn đoán ngược, và gợi ý
    // "quay lại làm tròn từng lần" lại nằm ở vế KHÔNG BAO GIỜ bắn cho lỗi đó.
    // Cả file này tồn tại để phân biệt hai chiều lệch — nhãn sai chiều làm hỏng
    // đúng thứ nó bảo vệ.
    expect($drift)->toBeLessThanOrEqual(0.0, implode("\n", [
        'Hoàn TỪNG PHẦN đang trả THIẾU — Σ thuế trên đơn > 0.',
        'Hai hướng có hệ quả pháp lý khác nhau — đo lại trước khi sửa hằng số.',
    ]));
    expect($drift)->toBeGreaterThanOrEqual(PARTIAL_REFUND_KNOWN_DRIFT, sprintf(
        "Hoàn TỪNG PHẦN đang trả DƯ: Σ thuế = %s.\n".
        'Nhiều khả năng có ai đó quay lại làm tròn từng lần thay vì luỹ kế (#2133).',
        $drift,
    ));

    // Hai vế trên kẹp `drift` vào đúng 0. Vế này nói thẳng điều đó, để thông
    // điệp lỗi đọc được mà không phải suy từ hai bất đẳng thức.
    expect($drift)->toBe(PARTIAL_REFUND_KNOWN_DRIFT,
        'hoàn hết bằng nhiều lần mà Σ thuế không về 0 — số trả lại khác số đã thu',
    );
});

it('hoàn TỪNG PHẦN chia kiểu nào cũng về 0 — không phụ thuộc cách chia', function () {
    // Vế này là thứ phân biệt "sửa đúng nguyên nhân" với "chỉnh cho vừa một ca".
    // Bài trên chỉ đo 1+1+1; một bản sửa gian lận (ví dụ ép lần cuối gánh phần
    // dư) cũng qua được nó. Làm tròn luỹ kế thì ĐÚNG với mọi cách chia, vì mốc
    // cuối luôn là `round(taxTotal)`.
    foreach ([[2.0, 1.0], [1.0, 2.0], [3.0]] as $plan) {
        [$order, $service, $item] = refundParityOneLineOrder(1005.0, 3.0, 10.0);

        foreach ($plan as $qty) {
            $service->refundItem($order, $item->id, $qty, 'test');
        }

        expect(refundParityTaxSum($order))->toBe(0.0, sprintf(
            'chia hoàn theo %s mà Σ thuế không về 0',
            implode('+', array_map('intval', $plan)),
        ));
    }
});
