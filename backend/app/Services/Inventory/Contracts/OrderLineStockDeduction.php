<?php

namespace App\Services\Inventory\Contracts;

use App\Omnify\Enums\StockDeductionTimingEnum;

/**
 * #1595 — cổng Inventory công bố cho Ordering để trừ/hoàn kho THEO DÒNG ĐƠN.
 *
 * `Ordering ↔ Inventory` là một 2-chu-trình, và cả hai đầu là cùng một class:
 * `StockDeductionService`. Đo trước khi sửa:
 *
 *     Inventory → Ordering  29   (đọc CustomerOrder / CustomerOrderItem / VoidReason)
 *     Ordering  → Inventory 11   (WritesCustomerOrders gọi StockDeductionService)
 *
 * Đã đo và TỪ CHỐI cách rẻ: chuyển `StockDeductionService` sang Ordering đo được
 * **−27 cạnh**, con số đẹp nhất còn lại của epic. Không làm — class đó ghi
 * `stock_transactions`, aggregate của Inventory. Lấy 27 cạnh bằng khai nhầm chỗ
 * là refactor để chiều số đo.
 *
 * **Vì sao chữ ký chỉ có primitive.** Bản nháp đầu định giữ nguyên
 * `deductLine(CustomerOrderItem $item, …)`. Cổng như thế chỉ ĐẢO CHIỀU vi phạm
 * chứ không gỡ: contract mang model của Ordering thì chính contract phụ thuộc
 * Ordering. Từ #1598, `PublishedContracts` chỉ được phụ thuộc hai kernel — nên
 * một cổng rò model **không khai vào đó được**, và cái rào đó chặn đúng bản
 * nháp này. Chữ ký toàn primitive là điều kiện để cổng được công bố, và công bố
 * là điều kiện để cạnh thôi bị tính là nợ.
 *
 * Giá phải trả, nói thẳng: caller đang cầm sẵn model, cổng lại nhận id, nên
 * adapter nạp lại một lần. `deductLine` vốn đã đi qua recipe/topping/warehouse
 * (hàng chục truy vấn mỗi dòng), nên một `find()` theo khoá chính là nhiễu —
 * nhưng nó là chi phí THẬT, không phải không có.
 */
interface OrderLineStockDeduction
{
    /**
     * Thời điểm trừ kho hiệu lực của chi nhánh. Thiếu cấu hình / giá trị lạ thì
     * hạ về `on_close` (hành vi cũ) — cổng không ném lỗi ở đây.
     */
    public function timingForBranch(string $branchId): StockDeductionTimingEnum;

    /**
     * Trừ kho cho một dòng đơn. Idempotent theo marker `stock_deducted_at`:
     * dòng đã trừ thì mọi lần gọi sau là no-op, kể cả khi shop đổi timing giữa
     * ngày.
     */
    public function deductLine(string $orderItemId, string $cause, ?\DateTimeInterface $occurredAt = null): void;

    /**
     * Đổi số lượng một dòng ĐÃ trừ kho: phát phần chênh, không trừ lại từ đầu.
     */
    public function adjustDeductedLineQuantity(string $orderItemId, float $previousQuantity): void;

    /**
     * Bù kho khi void một dòng, theo bảng sự thật #1149:
     * chưa trừ → không làm gì · đã trừ + restock → `adjustment_in` đảo đúng lô cũ
     * · đã trừ + waste → không bù (vật tư đã tiêu thật) · không rõ lý do → không
     * bù + log cảnh báo.
     */
    public function compensateVoid(string $orderItemId, ?string $voidReasonId): void;

    /**
     * Quét mọi dòng CHƯA trừ kho của đơn lúc đóng đơn (#1666 — pha 5 cũ của
     * `OrderClosingService`).
     *
     * Trước đây `OrderClosingService` (Ordering) cầm thẳng `StockDeductionService`,
     * tức module bán hàng gọi thẳng vào ruột kho. Đơn không tồn tại là **no-op im
     * lặng** như mọi method khác của cổng này — xem
     * `EloquentOrderLineStockDeduction`. Tên viết trong backtick chứ không phải
     * thẻ tham chiếu: pint biến tham chiếu FQCN trong docblock thành `use` THẬT,
     * và ở một cổng công bố thì đúng cái đó làm cạnh mọc lại.
     *
     * Cố ý KHÔNG ném khi thiếu kho: chỗ gọi bọc riêng savepoint để một lần trừ
     * kho hỏng không cuộn ngược tiền đã thu. Đó là luật của chỗ gọi, không phải
     * của cổng — nên cổng giữ nguyên hành vi ném của engine bên dưới.
     */
    public function sweepUndeductedLinesAtClose(string $orderId): void;

    /**
     * Ghi phả hệ lô (genealogy) cho một giao dịch kho đã phát.
     *
     * `$orderItemIds === null` nghĩa là "mọi dòng chưa void của đơn" — GIỮ NGUYÊN
     * hành vi cũ. Mảng RỖNG **không** đồng nghĩa với `null`: nó là tập rỗng thật
     * (plan-040 C8 truyền phần made_to_order còn lại, có thể rỗng), nên đừng gộp
     * hai cái đó lại bằng một phép `?:`.
     *
     * @param  list<string>|null  $orderItemIds
     */
    public function recordSalesGenealogy(string $orderId, string $transactionId, ?array $orderItemIds = null): void;

    /**
     * Trạng thái dòng đã CHẠM tới mốc `preparing` chưa (pending → preparing /
     * ready / served đều đã chạm).
     *
     * Có mặt ở đây vì Ordering đang gọi `StockDeductionService::statusHasReachedPreparing()`
     * dạng STATIC — một cạnh không cổng nào bắt được nếu chỉ chuyển các method
     * instance. Ngưỡng "khi nào một dòng coi như đã vào bếp" là luật của Inventory
     * (nó quyết định lúc nào trừ kho), nên nó thuộc về cổng này chứ không phải
     * một hằng số Ordering tự giữ bản sao.
     */
    public function hasReachedPreparing(string $status): bool;
}
