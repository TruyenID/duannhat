<?php

namespace App\Services\Order\Contracts;

/**
 * Tổng của MỘT sub-check trong kiểu chia theo món (#1594).
 *
 * Payments hỏi "cái hoá đơn con này đáng bao nhiêu" để đối chiếu với số tiền
 * khách gửi lên (`split_by_items_total_mismatch`). Trước #1594 nó hỏi bằng cách
 * cầm thẳng `SplitByItemsCalculator` — một class của Ordering nhận
 * `CustomerOrder` rồi đi tiếp xuống `CustomerOrderItem`. Tức để so một con số,
 * Payments phải phụ thuộc vào cả aggregate đơn hàng.
 *
 * Cổng này KHÔNG chuyển phép tính đi đâu cả: bộ tính vẫn là
 * `SplitByItemsCalculator`, vẫn khớp bit-for-bit với
 * `pos-web/src/app/pos/lib/split-by-items.ts` qua
 * `tests/Fixtures/split_by_items_cases.json`. Nó chỉ đổi thứ đi qua ranh giới
 * từ **model** thành **id + tham số + một số float**.
 *
 * Chính sách (`roundingMode` · `currencyCode` · `serviceChargeRate`) vẫn do
 * người gọi truyền vào, y như trước, vì Payments đã đọc chúng qua
 * {@see BranchSplitBillPolicy}. Đưa nốt việc tra chính sách vào đây sẽ gộp hai
 * quyết định vốn tách rời — và làm cổng này khác đi so với chữ ký cũ ở chỗ
 * không ai đo được.
 */
interface OrderSplitBillTotals
{
    /**
     * Tổng "tự nhiên" của sub-check `$billIndex` — KHÔNG hoà rounding drift.
     *
     * Đúng ngữ nghĩa `SplitByItemsCalculator::computeBillTotal()` (chạy
     * `reconcile: false`). Người gọi vẫn tự lo phần đối chiếu với sub-check
     * chốt sổ; đổi điều đó ở đây là đổi tiền.
     *
     * Trả `0.0` khi không tìm thấy đơn — giống hệt kết quả cũ khi bill rỗng
     * (`$result['bills'][$billIndex]['total'] ?? 0.0`).
     *
     * @param  array<int, array{item_id: string, units: int}>  $itemAllocations
     */
    public function billTotalFor(
        string $orderId,
        array $itemAllocations,
        int $billIndex,
        string $roundingMode,
        ?string $currencyCode,
        float $taxRate,
        float $serviceChargeRate,
        int $peopleCount,
    ): float;

    /**
     * #2180 — số suất CÒN CHIA ĐƯỢC của từng dòng, theo đúng quy tắc của
     * `SplitByItemsCalculator::splittableUnits()` (#2159: trừ `refunded_quantity`;
     * dòng hoàn hết ra 0, dòng chưa hoàn giữ tối thiểu 1).
     *
     * Payments cần con số này cho cổng `split_by_items_double_claim` — cùng lý
     * do `billTotalFor` tồn tại: để so một con số, Payments không được cầm
     * aggregate của Ordering (deptrac chặn `Payments → Ordering`). Quy tắc vẫn
     * ở một chỗ duy nhất trong Ordering; thứ đi qua ranh giới là map id → int.
     *
     * @return array<string, int> item_id → số suất còn chia được (mọi dòng của
     *                            đơn, kể cả dòng hoàn/void — người gọi tự lọc
     *                            như đang lọc với `items`); đơn không tồn tại
     *                            trả mảng rỗng.
     */
    public function splittableUnitsByItem(string $orderId): array;
}
