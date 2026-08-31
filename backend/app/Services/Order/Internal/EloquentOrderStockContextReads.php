<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\OrderStockContext;
use App\Services\Order\Contracts\OrderStockContextReads;

/**
 * #1605 — hiện thực {@see OrderStockContextReads}.
 *
 * Chỉ đọc SÁU cột và chỉ của bảng Ordering, nên chủ sở hữu dữ liệu cũng là chủ
 * truy vấn — cùng lý do `EloquentBranchStockDeductionTiming` nằm bên này.
 *
 * Dùng `select()` hẹp chứ không nạp cả hàng: đây là đường trừ kho, chạy một lần
 * cho mỗi dòng đơn ở timing `on_add` / `on_preparing`.
 */
final class EloquentOrderStockContextReads implements OrderStockContextReads
{
    public function find(string $orderId): ?OrderStockContext
    {
        $order = CustomerOrder::query()
            ->whereKey($orderId)
            ->first(['id', 'organization_id', 'branch_id', 'order_code', 'created_by_id', 'customer_id']);

        if ($order === null) {
            return null;
        }

        return new OrderStockContext(
            id: (string) $order->id,
            organizationId: (string) $order->organization_id,
            branchId: (string) $order->branch_id,
            orderCode: (string) $order->order_code,
            createdById: $order->created_by_id === null ? null : (string) $order->created_by_id,
            customerId: $order->customer_id === null ? null : (string) $order->customer_id,
        );
    }
}
