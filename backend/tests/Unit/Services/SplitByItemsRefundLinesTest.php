<?php

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Services\Customer\SplitByItemsCalculator;

/**
 * #2159 — chia bill theo món trên đơn ĐÃ HOÀN MỘT PHẦN.
 *
 * `refundItem` không giảm `quantity` của dòng gốc; nó phụ thêm một dòng
 * `quantity = -1` mang `refund_of_item_id` và CỘNG vào `refunded_quantity`.
 * Bộ chia bill trước đây không biết cả hai điều đó:
 *
 *	- dòng hoàn lọt vào danh sách chia ⇒ `max(1, -1) = 1` suất MA;
 *	- dòng gốc vẫn khai đủ 2 suất trong khi khách chỉ còn nợ 1.
 *
 * Hậu quả đo được (đơn 1 dòng qty 2 @¥1.000, hoàn 1, thuế 0%):
 * `unassigned_units = 3` — thu ngân không bao giờ gán hết nên màn chia bill
 * KHÔNG HOÀN TẤT ĐƯỢC — và bill đầu ra `subtotal 2.000 / **tax −1.000** /
 * total 1.000`, tức một dòng thuế ÂM ¥1.000 trên đơn 0% thuế, in ra cho khách.
 *
 * Các bài dưới đây dựng model TRONG BỘ NHỚ (`setRawAttributes` + `setRelation`)
 * vì `SplitByItemsCalculator` là hàm thuần — cố ý không chạm DB, giống
 * `SplitByItemsCalculatorTest` bên cạnh.
 */

/**
 * Đơn 1 dòng `qty` suất @ `unitPrice`, đã hoàn `refunded` suất.
 *
 * @return array{0: CustomerOrder, 1: string, 2: string} [đơn, id dòng sống, id dòng hoàn]
 */
function splitRefundOrder(int $qty, int $refunded, int $unitPrice = 1000): array
{
    $remaining = ($qty - $refunded) * $unitPrice;

    $order = new CustomerOrder;
    $order->setRawAttributes([
        'id' => 'o1',
        'subtotal' => $remaining,
        'discount_amount' => 0,
        'total_amount' => $remaining,
    ], true);

    $live = new CustomerOrderItem;
    $live->setRawAttributes([
        'id' => 'i-1',
        'customer_order_id' => 'o1',
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'topping_subtotal' => 0,
        'subtotal' => $qty * $unitPrice,
        'status' => 'served',
        'refund_of_item_id' => null,
        'refunded_quantity' => $refunded,
    ], true);

    $items = [$live];

    if ($refunded > 0) {
        $refundLine = new CustomerOrderItem;
        $refundLine->setRawAttributes([
            'id' => 'i-1r',
            'customer_order_id' => 'o1',
            'quantity' => -1 * $refunded,
            // unit_price của dòng hoàn là số DƯƠNG — chỉ quantity/subtotal đổi dấu.
            'unit_price' => $unitPrice,
            'topping_subtotal' => 0,
            'subtotal' => -1 * $refunded * $unitPrice,
            'status' => 'served',
            'refund_of_item_id' => 'i-1',
            'refunded_quantity' => 0,
        ], true);
        $items[] = $refundLine;
    }

    $order->setRelation('items', collect($items));

    return [$order, 'i-1', 'i-1r'];
}

function splitRefund(CustomerOrder $order, array $allocations, int $people = 2): array
{
    return (new SplitByItemsCalculator)->compute(
        $order, $allocations, 'round', 'JPY', 0.0, 0.0, $people,
    );
}

it('chỉ đưa ra chia những suất CHƯA hoàn — không có suất ma', function () {
    [$order] = splitRefundOrder(qty: 2, refunded: 1);

    $out = splitRefund($order, []);

    // Trước #2159: 3 (2 suất dòng gốc + 1 suất ma của dòng hoàn).
    expect($out['unassigned_units'])->toHaveCount(1);
    expect($out['unassigned_units'][0]['item_id'])->toBe('i-1');
});

it('gán hết số suất còn lại thì màn chia bill HOÀN TẤT được', function () {
    [$order, $liveId] = splitRefundOrder(qty: 2, refunded: 1);

    $out = splitRefund($order, [
        ['item_id' => $liveId, 'units' => 1, 'bill_index' => 0],
    ]);

    // Trước #2159 chỗ này còn sót 2 suất ⇒ thu ngân kẹt vĩnh viễn.
    expect($out['unassigned_units'])->toBe([]);
});

it('KHÔNG in ra dòng thuế âm trên đơn 0% thuế đã hoàn một phần', function () {
    [$order, $liveId] = splitRefundOrder(qty: 2, refunded: 1);

    $out = splitRefund($order, [
        ['item_id' => $liveId, 'units' => 1, 'bill_index' => 0],
    ]);

    // Trước #2159: subtotal 2.000 / tax −1.000 / total 1.000.
    expect($out['bills'][0]['subtotal'])->toBe(1000.0);
    expect($out['bills'][0]['tax'])->toBe(0.0);
    expect($out['bills'][0]['total'])->toBe(1000.0);
});

it('gán QUÁ số suất còn lại thì bị kẹp, không dồn chênh lệch vào thuế', function () {
    [$order, $liveId] = splitRefundOrder(qty: 2, refunded: 1);

    // Thu ngân (hoặc một client cũ) vẫn tưởng dòng này còn 2 suất.
    $out = splitRefund($order, [
        ['item_id' => $liveId, 'units' => 2, 'bill_index' => 0],
    ]);

    expect($out['bills'][0]['subtotal'])->toBe(1000.0);
    expect($out['bills'][0]['tax'])->toBe(0.0);
    expect($out['bills'][0]['total'])->toBe(1000.0);
});

it('không cho gán CHÍNH dòng hoàn cho một khách', function () {
    [$order, $liveId, $refundId] = splitRefundOrder(qty: 2, refunded: 1);

    $out = splitRefund($order, [
        ['item_id' => $liveId, 'units' => 1, 'bill_index' => 0],
        ['item_id' => $refundId, 'units' => 1, 'bill_index' => 1],
    ]);

    // Trước #2159 khách #2 nhận một bill ¥1.000 cho một món KHÔNG TỒN TẠI.
    expect($out['bills'][1]['items_breakdown'])->toBe([]);
    expect($out['bills'][1]['is_empty'])->toBeTrue();
    expect($out['bills'][1]['total'])->toBe(0.0);
});

it('dòng hoàn HẾT thì còn 0 suất, không phải 1', function () {
    [$order] = splitRefundOrder(qty: 2, refunded: 2);

    $out = splitRefund($order, []);

    // `max(1, …)` cũ sẽ trả về 1 ở đây — đúng con suất ma vừa bỏ, ở dạng khác.
    expect($out['unassigned_units'])->toBe([]);
});

it('dòng chưa hoàn gì thì hành vi KHÔNG đổi', function () {
    [$order, $liveId] = splitRefundOrder(qty: 3, refunded: 0);

    $out = splitRefund($order, [
        ['item_id' => $liveId, 'units' => 2, 'bill_index' => 0],
    ]);

    expect($out['unassigned_units'])->toHaveCount(1);
    expect($out['bills'][0]['subtotal'])->toBe(2000.0);
});
