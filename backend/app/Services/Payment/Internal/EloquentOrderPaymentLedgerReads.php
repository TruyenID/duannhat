<?php

declare(strict_types=1);

namespace App\Services\Payment\Internal;

use App\Models\OrderPayment;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Order\Contracts\OrderPaymentCacheTotals;
use App\Services\Order\Contracts\OrderPaymentLedgerReads;

/**
 * #1662 — Payments hiện thực cổng đọc sổ tiền mà Ordering khai.
 *
 * Hai truy vấn dưới đây được **chép nguyên** từ `WritesCustomerOrders`, cố ý không
 * "dọn" gì: đây là PR ranh giới, đổi chỗ code chứ không đổi số tiền. Lý do từng điều
 * kiện nằm ở docblock của {@see OrderPaymentLedgerReads}.
 */
final class EloquentOrderPaymentLedgerReads implements OrderPaymentLedgerReads
{
    public function cachedTotalsForOrder(string $orderId): OrderPaymentCacheTotals
    {
        $row = OrderPayment::query()
            ->where('customer_order_id', $orderId)
            ->whereNull('metadata->settles_payment_id')
            ->whereIn('status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->selectRaw('COALESCE(SUM(amount), 0) as total_paid, COALESCE(SUM(tip_amount), 0) as total_tip')
            ->first();

        if ($row === null) {
            return OrderPaymentCacheTotals::empty();
        }

        return new OrderPaymentCacheTotals(
            totalPaid: (float) $row->total_paid,
            totalTip: (float) $row->total_tip,
        );
    }

    /**
     * CHUYỂN TIẾP, không tính lại — xem cạm bẫy #816 ở docblock hợp đồng.
     */
    public function netCollectedForOrder(string $orderId): float
    {
        return OrderPayment::netCollectedForOrder($orderId);
    }
}
