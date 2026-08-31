<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * #2158 — ba ca hoàn tiền chưa được ghim, tách từ review PR #2135 (#2117).
 *
 * Người anh em `RefundReversesTaxExactlyTest` ghim tính chất ĐẢO-ẢNH-CHỤP trên
 * đơn một-mức JPY step 1. File này ghim phần còn thiếu:
 *
 *  1. Đơn NHIỀU mức (10% + 8% + 0%), hoàn hết  → Σ thuế về đúng 0.
 *  2. 内税 (`is_tax_included`), hoàn hết        → Σ về đúng 0.
 *  3. `tax_rounding_decimals = 2` (step 0,01)  → issue ghi "chưa đo"; ĐÃ ĐO
 *     2026-08-09 và mọi con số dưới đây là hành vi ĐO ĐƯỢC, không suy diễn:
 *
 *       2 × $10.05 @10% (USD, step 0,01):  thuế thu 2.01
 *         hoàn 1 món  → −1.01   (biên .5: 2.01×1/2 = 1.005 → RA XA 0,
 *                                cùng tính chất PR #2135 ghim ở step 1)
 *         hoàn nốt    → −1.00   (mốc luỹ kế: 2.01 − 1.01)
 *         Σ = 0, order.tax_amount = 0.00, total = 0.00
 *
 *       3 × $10.05 @10%: nhóm 30.15 × 10% = 3.015 → 3.02, chia 1.01/1.01/1.00
 *         hoàn dòng bị cắt phần lẻ → −1.00 (đảo ảnh chụp, KHÔNG phải
 *         round(1.005) = 1.01 tính lại)
 *
 *       $1.45 @10% — chính ví dụ `0.145 @ 0.01` trong bảng của #2117:
 *         thu 0.15 (0.145 → .5 lên), hoàn hết → −0.15, Σ = 0
 *
 *       JPY nhưng decimals = 2 (rev-B option-B, step MỊN hơn currency):
 *         2 × ¥1005 @10% → thuế 201.00, total 2211 (nguyên yên);
 *         hoàn 1 món → −100.50 (mốc 100,5 nằm ĐÚNG trên lưới 0,01 nên không
 *         phải làm tròn — đối chiếu −101 ở step 1 mà bài `abs()` đã ghim);
 *         hoàn hết → Σ = 0.
 *
 *     Không tìm thấy hành vi đáng ngờ nào: không mất tiền, không lệch chiều.
 *
 * Ca 4 của issue (void dòng hoàn) KHÔNG ở đây — đường đó đã ĐÓNG từ #2173 và
 * được ghim hành-vi ở `VoidRefundLineIsRefusedTest` (POS + replay) +
 * `RefundTraceProtectionTest` (#2200, void dòng GỐC); phần tĩnh chặn cửa mới
 * nằm ở `Architecture/RefundLineVoidPathStaysClosedTest`.
 *
 * ## Ghi chú bụi float
 *
 * Ở step 0,01, cột DECIMAL(15,2) trả "1.01"/" -1.00"… nhưng Σ các (float) ép
 * kiểu mang bụi nhị phân (~2.2e-16 đo được). Các phép so Σ ở step 0,01 vì thế
 * đi qua `round(·, 2)` — nửa step là 0,005, bụi 1e-16 không bao giờ chạm.
 * Step 1 (JPY) là số nguyên, so thẳng.
 */

/**
 * Đơn nhiều dòng tuỳ cấu hình — mỗi dòng [unit_price, quantity, rate].
 *
 * @param  list<array{0: float, 1: float, 2: float}>  $lines
 * @return array{0: CustomerOrder, 1: CustomerOrderService}
 */
function refundUnpinnedOrder(
    array $lines,
    string $currency = 'JPY',
    int $taxDecimals = 0,
    bool $taxIncluded = false,
): array {
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => $taxIncluded,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => $taxDecimals,
    ]);

    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => $currency,
            'tax_rounding_mode' => 'round',
        ]
    );

    foreach ($lines as [$unitPrice, $quantity, $rate]) {
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'topping_subtotal' => 0,
            'subtotal' => $unitPrice * $quantity,
            'tax_rate' => $rate,
            'tax_amount' => 0,
            'status' => 'served',
        ]);
    }

    $service = app(CustomerOrderService::class);
    $service->refreshOrderTotals($order->fresh('items'));

    return [$order->fresh('items'), $service];
}

/** Σ thuế mọi dòng (thu + hoàn), lượng tử hoá về step 0,01 để gạt bụi float. */
function refundUnpinnedTaxSum(CustomerOrder $order): float
{
    return round(
        (float) $order->fresh('items')->items->sum(fn ($i) => (float) $i->tax_amount),
        2,
    );
}

/** Hoàn TRỌN mọi dòng gốc còn sống của đơn, mỗi dòng một lệnh. */
function refundUnpinnedEverything(CustomerOrder $order, CustomerOrderService $service): void
{
    $originals = $order->fresh('items')->items
        ->filter(fn ($i) => $i->refund_of_item_id === null);

    foreach ($originals as $item) {
        $service->refundItem($order->fresh('items'), (string) $item->id, (float) $item->quantity, 'test');
    }
}

/** Dòng HOÀN trỏ về một dòng gốc cho trước. */
function refundUnpinnedLineFor(CustomerOrder $order, CustomerOrderItem $original): CustomerOrderItem
{
    return $order->fresh('items')->items
        ->first(fn ($i) => (string) $i->refund_of_item_id === (string) $original->id);
}

// ─── Ca 1 — đơn NHIỀU mức thuế (10% + 8% + 0%), hoàn hết ────────────────────

it('đơn ba mức 10%+8%+0% hoàn hết thì Σ thuế về đúng 0, từng mức tự triệt tiêu', function () {
    // 1005 @10 → 100,5 → 101 (chạm đúng biên .5); 1063 @8 → 85,04 → 85;
    // 999 @0 → 0. Ba mức, ba kiểu làm tròn khác nhau trong CÙNG một đơn.
    [$order, $service] = refundUnpinnedOrder([
        [1005.0, 1.0, 10.0],
        [1063.0, 1.0, 8.0],
        [999.0, 1.0, 0.0],
    ]);

    // Tiền đề: mỗi mức phải mang đúng thuế nhóm của nó — sai ở đây là hỏng
    // phía THU, không phải phía hoàn; nói ra thay vì để ca chính xanh giả.
    $byRate = $order->items->keyBy(fn ($i) => (string) (float) $i->tax_rate);
    expect((float) $byRate['10']->tax_amount)->toBe(101.0)
        ->and((float) $byRate['8']->tax_amount)->toBe(85.0)
        ->and((float) $byRate['0']->tax_amount)->toBe(0.0);

    refundUnpinnedEverything($order, $service);

    // Từng dòng hoàn phải là ẢNH CHỤP ĐẢO DẤU của dòng gốc — không có coupon
    // thì nền gộp (#2182) trùng đúng tax_amount, mọi mức, kể cả mức 0%.
    foreach ($byRate as $original) {
        expect((float) refundUnpinnedLineFor($order, $original)->tax_amount)
            ->toBe(-1.0 * (float) $original->tax_amount, sprintf(
                'dòng hoàn của mức %s%% không phải ảnh chụp đảo dấu (gốc mang %s)',
                (float) $original->tax_rate,
                $original->tax_amount,
            ));
    }

    expect(refundUnpinnedTaxSum($order))->toBe(0.0, 'hoàn hết đơn ba mức mà Σ thuế không về 0');
    expect((float) $order->fresh()->tax_amount)->toBe(0.0, 'order.tax_amount không về 0');
    expect((float) $order->fresh()->total_amount)->toBe(0.0, 'order.total_amount không về 0');
});

// ─── Ca 2 — 内税 (giá đã gồm thuế), hoàn hết ────────────────────────────────

it('đơn 内税 hoàn hết thì Σ thuế về đúng 0 và tổng về 0', function () {
    // 3 × ¥1005 gộp thuế @10%: nhóm 3015, thuế trích 3015 − round(3015/1,1)
    // = 3015 − 2741 = 274, chia largest-remainder 92/91/91 — thuế của một dòng
    // KHÔNG dựng lại được từ chính nó, đúng chỗ hai cách tính tách nhau.
    [$order, $service] = refundUnpinnedOrder(
        [[1005.0, 1.0, 10.0], [1005.0, 1.0, 10.0], [1005.0, 1.0, 10.0]],
        taxIncluded: true,
    );

    // Tiền đề: nếu phép trích 内税 không ra 274 chia 92/91/91 thì bài này
    // không đo được tính chất phân bổ — nói ra thay vì xanh giả.
    expect((float) $order->fresh()->tax_amount)->toBe(274.0);
    expect($order->items->map(fn ($i) => (float) $i->tax_amount)->sort()->values()->all())
        ->toBe([91.0, 91.0, 92.0]);

    refundUnpinnedEverything($order, $service);

    expect(refundUnpinnedTaxSum($order))->toBe(0.0, 'hoàn hết đơn 内税 mà Σ thuế không về 0');
    expect((float) $order->fresh()->tax_amount)->toBe(0.0, 'order.tax_amount (内税) không về 0');
    // 内税: subtotal là GỘP, dòng hoàn cũng gộp — tổng phải triệt tiêu hết.
    expect((float) $order->fresh()->total_amount)->toBe(0.0, 'order.total_amount (内税) không về 0');
});

// ─── Ca 3 — tax_rounding_decimals = 2 (step 0,01) — hành vi ĐO ĐƯỢC ────────

it('step 0,01: hoàn từng phần ở biên .5 làm tròn RA XA 0, hoàn hết về đúng 0', function () {
    // Bản sao ở step 0,01 của ca "2 × ¥1.005 @10% → −101" mà PR #2135 ghim ở
    // step 1: 2 × $10.05 @10% → thuế 2.01; hoàn 1 món chạm đúng biên
    // 2.01 × 1/2 = 1.005.
    [$order, $service] = refundUnpinnedOrder([[10.05, 2.0, 10.0]], 'USD', taxDecimals: 2);
    $item = $order->items->first();

    // Tiền đề: 20.10 × 10% = 2.010 nằm ngoài biên — thuế thu phải là 2.01 chẵn,
    // nếu không phần chia 1/2 không rơi vào .5 và bài này không đo được gì.
    expect((float) $item->tax_amount)->toBe(2.01);

    $service->refundItem($order, (string) $item->id, 1.0, 'test');
    $firstRefund = $order->fresh('items')->items->first(fn ($i) => $i->refund_of_item_id !== null);

    expect((float) $firstRefund->tax_amount)->toBe(-1.01, implode("\n", [
        'Biên .5 ở step 0,01 phải làm tròn RA XA 0: round(1.005 @ 0.01) = 1.01 → −1.01.',
        'Ra −1.00 nghĩa là hoặc mất chuẩn hoá 15-chữ-số của roundHalfUpToStep',
        '(1.005/0.01 = 100.4999… nhị phân kéo biên .5 xuống), hoặc mất abs() —',
        'đúng hai cách #2117 tiên đoán quán trả thiếu khách.',
    ]));

    $service->refundItem($order->fresh('items'), (string) $item->id, 1.0, 'test');
    $secondRefund = $order->fresh('items')->items
        ->filter(fn ($i) => $i->refund_of_item_id !== null)
        ->first(fn ($i) => (string) $i->id !== (string) $firstRefund->id);

    expect((float) $secondRefund->tax_amount)->toBe(-1.00, implode("\n", [
        'Lần hoàn thứ hai phải là HIỆU HAI MỐC LUỸ KẾ: round(2.01) − round(1.005) = 1.00.',
        'Ra −1.01 nghĩa là ai đó quay lại làm tròn TỪNG LẦN (#2133) — Σ hoàn thành −2.02',
        'trên khoản thu 2.01, quán trả dư $0.01 mỗi đơn, im lặng.',
    ]));

    expect(refundUnpinnedTaxSum($order))->toBe(0.0, 'hoàn hết ở step 0,01 mà Σ thuế không về 0');
    expect((float) $order->fresh()->tax_amount)->toBe(0.0)
        ->and((float) $order->fresh()->total_amount)->toBe(0.0);
});

it('step 0,01: hoàn dòng bị cắt phần lẻ trả đúng ảnh chụp, không tính lại', function () {
    // Largest-remainder ở step 0,01: 3 × $10.05 @10% → nhóm 3.015 → 3.02,
    // chia 1.01/1.01/1.00 — dòng thứ ba mang thuế KHÁC round(1.005) = 1.01.
    [$order, $service] = refundUnpinnedOrder(
        [[10.05, 1.0, 10.0], [10.05, 1.0, 10.0], [10.05, 1.0, 10.0]],
        'USD',
        taxDecimals: 2,
    );

    // Tiền đề: phân bổ phải chia lẻ thật — nói ra thay vì xanh giả.
    expect($order->items->map(fn ($i) => (float) $i->tax_amount)->sort()->values()->all())
        ->toBe([1.00, 1.01, 1.01]);

    $short = $order->items->sortBy(fn ($i) => (float) $i->tax_amount)->first();
    $service->refundItem($order, (string) $short->id, 1.0, 'test');

    expect((float) refundUnpinnedLineFor($order, $short)->tax_amount)->toBe(-1.00, implode("\n", [
        'Dòng bị largest-remainder cắt phần lẻ mang 1.00 — hoàn nó phải trả đúng 1.00.',
        'Ra −1.01 là refundItem đang TÍNH LẠI round(subtotal × rate) thay vì đảo ảnh chụp;',
        'quán mất $0.01 mỗi lần, chính lỗi mà bài parity step-1 đã canh, nay ở step 0,01.',
    ]));
});

it('step 0,01: biên 0.145 của chính #2117 — thu 0.15, hoàn hết về đúng 0', function () {
    // Bảng của #2117 lấy ví dụ `0.145 @ 0.01`: $1.45 @10% → 0.145, đúng biên.
    [$order, $service] = refundUnpinnedOrder([[1.45, 1.0, 10.0]], 'USD', taxDecimals: 2);
    $item = $order->items->first();

    // Tiền đề: phía THU phải cho 0.15 (0.145 → .5 lên) — đây là chính ca mà
    // chuẩn hoá 15-chữ-số trong roundHalfUpToStep tồn tại để cứu.
    expect((float) $item->tax_amount)->toBe(0.15);

    $service->refundItem($order, (string) $item->id, 1.0, 'test');

    expect((float) refundUnpinnedLineFor($order, $item)->tax_amount)->toBe(-0.15);
    expect(refundUnpinnedTaxSum($order))->toBe(0.0);
});

it('step 0,01 MỊN hơn currency (JPY, rev-B option-B): mốc 100,5 nằm trên lưới nên hoàn nửa là −100.50, hoàn hết về 0', function () {
    // Cùng kịch bản 2 × ¥1005 @10% mà bài `abs()` của file người anh em ghim ở
    // step 1 (−101) — nhưng đơn này snapshot decimals = 2, nên 201 × 1/2 =
    // 100,5 nằm ĐÚNG trên lưới 0,01: không có gì để làm tròn, hoàn nửa mang
    // −100.50 lẻ dưới yên (hiển thị 消費税, tổng thanh toán vẫn nguyên yên).
    [$order, $service] = refundUnpinnedOrder([[1005.0, 2.0, 10.0]], 'JPY', taxDecimals: 2);
    $item = $order->items->first();

    // Tiền đề: thuế thu 201.00 và tổng thanh toán vẫn NGUYÊN YÊN (2211) —
    // taxStep mịn chỉ đổi độ phân giải của thuế, không đổ lẻ vào total.
    expect((float) $item->tax_amount)->toBe(201.0);
    expect((float) $order->fresh()->total_amount)->toBe(2211.0);

    $service->refundItem($order, (string) $item->id, 1.0, 'test');
    $half = $order->fresh('items')->items->first(fn ($i) => $i->refund_of_item_id !== null);

    expect((float) $half->tax_amount)->toBe(-100.50, implode("\n", [
        'Ở step 0,01 mốc 100,5 KHÔNG phải biên làm tròn — nó nằm trên lưới.',
        'Ra −101 nghĩa là taxStep của đường hoàn đang đọc step CURRENCY (1) thay vì',
        'snapshot tax_rounding_decimals của đơn — hai đường thu/hoàn lệch lưới nhau.',
    ]));

    $service->refundItem($order->fresh('items'), (string) $item->id, 1.0, 'test');
    expect(refundUnpinnedTaxSum($order))->toBe(0.0, 'hoàn hết mà Σ thuế không về 0 (JPY, step thuế 0,01)');
    expect((float) $order->fresh()->tax_amount)->toBe(0.0)
        ->and((float) $order->fresh()->total_amount)->toBe(0.0);
});
