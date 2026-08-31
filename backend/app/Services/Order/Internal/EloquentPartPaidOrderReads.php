<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\PartPaidOrder;
use App\Services\Order\Contracts\PartPaidOrderReads;
use App\Services\Order\CustomerOutstandingOrderService;

/**
 * #1992 — hiện thực {@see PartPaidOrderReads}.
 *
 * Vị ngữ KHÔNG viết lại ở đây: `->partPaid()` là scope trên
 * {@see CustomerOrder}, dùng chung với {@see CustomerOutstandingOrderService}.
 * Đó là toàn bộ điểm của #1992 — chép nó ra lần nữa, dù chỉ hai dòng, là dựng
 * lại đúng khoản nợ vừa trả.
 *
 * Đi qua model nên đơn xoá mềm rụng theo `SoftDeletes` scope, thay vì phải nhớ
 * `whereNull('deleted_at')` như bản `DB::table` cũ.
 */
final class EloquentPartPaidOrderReads implements PartPaidOrderReads
{
    public function forBranch(string $branchId): array
    {
        return CustomerOrder::query()
            ->partPaid()
            ->where('branch_id', $branchId)
            // Tra cứu là tra THEO KHÁCH — xem docblock của cổng.
            ->whereNotNull('customer_id')
            ->orderBy('opened_at')
            ->get(['id', 'order_code', 'customer_id', 'total_amount', 'paid_amount', 'opened_at'])
            ->map(static function (CustomerOrder $order): PartPaidOrder {
                $total = (float) $order->total_amount;
                $paid = (float) $order->paid_amount;

                return new PartPaidOrder(
                    orderId: (string) $order->id,
                    orderCode: $order->order_code === null ? null : (string) $order->order_code,
                    customerId: (string) $order->customer_id,
                    totalAmount: $total,
                    paidAmount: $paid,
                    unpaidAmount: $total - $paid,
                    openedAt: $order->opened_at?->toDateTimeString(),
                );
            })
            ->values()
            ->all();
    }
}
