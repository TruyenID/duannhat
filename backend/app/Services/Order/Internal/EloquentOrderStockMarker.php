<?php

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Services\Order\Contracts\OrderStockMarker;

/**
 * #1595 — hiện thực {@see OrderStockMarker}.
 *
 * Uỷ quyền về `EloquentOrderPersistence`, KHÔNG tự `update()`. Hai writer kia là
 * biên mutation của order-aggregate (plan-047) và có allowlist domain-writer đi
 * kèm; ghi tắt ở đây sẽ tạo writer thứ hai cho cùng bảng — đúng thứ
 * `architecture:domain-writers` tồn tại để bắt.
 *
 * Không tìm thấy đơn/dòng ⇒ no-op, cùng lý do đã ghi ở
 * `EloquentOrderLineStockDeduction`: caller đang ở giữa transaction bán hàng.
 */
final class EloquentOrderStockMarker implements OrderStockMarker
{
    public function __construct(private readonly EloquentOrderPersistence $persistence) {}

    public function stampOrderStockOutTransaction(string $orderId, string $transactionId): void
    {
        $order = CustomerOrder::find($orderId);
        if ($order === null) {
            return;
        }

        $this->persistence->stampStockOutTransactionId($order, $transactionId);
    }

    public function markLineDeducted(
        string $orderItemId,
        \DateTimeInterface $deductedAt,
        ?string $stockOutTransactionId,
    ): void {
        $item = CustomerOrderItem::find($orderItemId);
        if ($item === null) {
            return;
        }

        $this->persistence->patchOrderItemUnchecked($item, [
            'stock_deducted_at' => $deductedAt,
            'stock_out_transaction_id' => $stockOutTransactionId,
        ]);
    }

    /**
     * #1731 — KHÔNG đọc lại dòng đơn.
     *
     * `AuditsActivity::logAudit()` chỉ đọc `getMorphClass()` và `getKey()` của
     * model — cả hai đều không chạm DB. Nên một instance chưa nạp, gắn đúng khoá
     * chính, cho ra **cùng một dòng audit** mà không thêm truy vấn nào. Đây là
     * đường trừ kho: `find()` ở đây là một lượt đọc thừa CHO MỖI DÒNG của mỗi
     * đơn, chỉ để lấy hai giá trị đã biết sẵn.
     *
     * Instance này không được `save()`, nên không sự kiện model nào bắn ra —
     * `bootAuditsActivity` chỉ móc vào created/updated/deleted.
     */
    public function recordLineStockAudit(string $orderItemId, string $action, array $metadata = []): void
    {
        $item = new CustomerOrderItem;
        $item->setAttribute($item->getKeyName(), $orderItemId);
        $item->exists = true;

        $item->logAudit($action, $metadata);
    }
}
