<?php

namespace App\Services\Order\Contracts;

/**
 * #1595 — cổng Ordering công bố cho Inventory để đóng DẤU ĐÃ TRỪ KHO.
 *
 * Trước PR này, `StockDeductionService` (Inventory) gọi thẳng
 * `app(EloquentOrderPersistence::class)` — tức với tay vào namespace `Internal`
 * của Ordering. Đó là 2 cạnh, và là **cạnh khép ngược** của chu trình
 * Ordering↔Inventory: Ordering gọi Inventory trừ kho, Inventory gọi ngược vào
 * ruột Ordering để ghi dấu.
 *
 * Hai method dưới là TOÀN BỘ những gì Inventory ghi lên đơn — đo từ code, không
 * suy đoán. Cả hai chỉ đặt cột đánh dấu; không cột nào chạm tiền, trạng thái hay
 * số lượng. Đó là lý do chúng công bố được: một cổng cho phép Inventory sửa
 * `paid_amount` hay `status` thì đã không còn là cổng đánh dấu nữa.
 */
interface OrderStockMarker
{
    /**
     * Ghi id giao dịch stock-out cấp ĐƠN (dấu theo đơn của đường on_close).
     */
    public function stampOrderStockOutTransaction(string $orderId, string $transactionId): void;

    /**
     * Ghi dấu ĐÃ TRỪ KHO lên một dòng đơn. Chính cái dấu này làm mọi hook sau đó
     * và cả lượt quét lúc đóng đơn bỏ qua dòng — tức nó là thứ chặn trừ kho hai
     * lần khi shop đổi `stock_deduction_timing` giữa ngày.
     */
    public function markLineDeducted(
        string $orderItemId,
        \DateTimeInterface $deductedAt,
        ?string $stockOutTransactionId,
    ): void;

    /**
     * #1731 — ghi một dòng VẾT KIỂM TOÁN gắn vào dòng đơn.
     *
     * Inventory ghi bốn vết trên dòng đơn (`stock_deducted`,
     * `stock_waste_recorded`, `stock_compensated`) và trước #1731 nó ghi bằng
     * `$item->logAudit(…)` — tức phải đang cầm model của Ordering.
     *
     * Vết này KHÔNG đi qua `App\Services\Audit\AuditLogWriter`: writer đó nhận
     * `auditable_type` dạng chuỗi, nên Inventory sẽ phải tự viết ra chuỗi morph
     * của một bảng nó không sở hữu — đổi một cạnh nhìn thấy được lấy một chuỗi
     * cứng không ai đo được. Chủ sở hữu dòng đơn là chủ sở hữu câu "vết này gắn
     * vào cái gì".
     *
     * Fail-open như `AuditsActivity::logAudit()`: ghi vết hỏng không được làm
     * hỏng việc bán hàng.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordLineStockAudit(string $orderItemId, string $action, array $metadata = []): void;
}
