<?php

namespace App\Services\Inventory;

use App\Omnify\Enums\StockDeductionTimingEnum;
use App\Services\Inventory\Contracts\OrderLineStockDeduction;
use App\Services\Inventory\Contracts\VoidReasonStockEffect;
use App\Services\Inventory\Contracts\VoidReasonStockEffects;

/**
 * #1595 — adapter cho {@see OrderLineStockDeduction}.
 *
 * Cố ý là một class RIÊNG chứ không phải `StockDeductionService implements …`:
 * `compensateVoid` của cổng nhận **id lý do void** còn của service nhận ảnh
 * chụp {@see VoidReasonStockEffect}, và
 * `hasReachedPreparing` là một hàm tĩnh. Gộp thì phải đổi chữ ký/tên method
 * trong một service hơn 1100 dòng nằm trên đường trừ kho.
 *
 * ## #1605 — class này KHÔNG còn chạm model của Ordering
 *
 * Trước đây nó `CustomerOrderItem::find()` ở ba method chỉ để đổi id thành
 * model rồi truyền tiếp. Giờ `StockDeductionService` nhận thẳng id ở cả sáu
 * method của cổng và tự nạp — cùng quyết định mà #1666 đã ghi cho hai method
 * `…ByOrderId`, chỉ là làm nốt. Đổi lại: adapter là uỷ quyền thuần, và cạnh
 * `EloquentOrderLineStockDeduction → CustomerOrderItem` biến mất khỏi baseline.
 *
 * Dòng/đơn không tồn tại vẫn là **no-op im lặng**, không throw — quyết định
 * không đổi, chỉ chuyển vào trong service. Caller là Ordering đang ở giữa một
 * transaction ghi đơn; ném ở đây sẽ cuộn ngược cả việc bán hàng vì một dòng vừa
 * bị xoá song song.
 */
final class EloquentOrderLineStockDeduction implements OrderLineStockDeduction
{
    public function __construct(
        private readonly StockDeductionService $deduction,
        private readonly VoidReasonStockEffects $voidReasons,
    ) {}

    public function timingForBranch(string $branchId): StockDeductionTimingEnum
    {
        return $this->deduction->timingForBranch($branchId);
    }

    public function deductLine(string $orderItemId, string $cause, ?\DateTimeInterface $occurredAt = null): void
    {
        $this->deduction->deductLine($orderItemId, $cause, $occurredAt);
    }

    public function adjustDeductedLineQuantity(string $orderItemId, float $previousQuantity): void
    {
        $this->deduction->adjustDeductedLineQuantity($orderItemId, $previousQuantity);
    }

    public function compensateVoid(string $orderItemId, ?string $voidReasonId): void
    {
        // Lý do void không tìm thấy ⇒ `null`, và `null` rơi vào nhánh
        // "không rõ lý do → không bù + log cảnh báo" của bảng #1149. Đó là
        // hành vi ĐÚNG, không phải nuốt lỗi: bù kho cho một lý do không đọc
        // được là đoán, và đoán sai thì hàng tồn lệch mà không ai biết.
        $reason = $voidReasonId === null ? null : $this->voidReasons->find($voidReasonId);

        $this->deduction->compensateVoid($orderItemId, $reason);
    }

    public function sweepUndeductedLinesAtClose(string $orderId): void
    {
        $this->deduction->sweepUndeductedLinesAtClose($orderId);
    }

    public function recordSalesGenealogy(string $orderId, string $transactionId, ?array $orderItemIds = null): void
    {
        $this->deduction->recordSalesGenealogy($orderId, $transactionId, $orderItemIds);
    }

    public function hasReachedPreparing(string $status): bool
    {
        return StockDeductionService::statusHasReachedPreparing($status);
    }
}
