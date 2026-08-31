<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — THỜI ĐIỂM TRỪ KHO đã cấu hình cho một chi nhánh, do Ordering công bố.
 *
 * Cùng khuôn với {@see BranchCurrency} (#1589) và cùng lý do: `ShopOrderSetting`
 * thuộc Ordering từ #1592, nhưng `StockDeductionService` (Inventory) đọc đúng một
 * cột của nó — `stock_deduction_timing`. Cổng hẹp đúng bằng câu hỏi đó.
 *
 * **Trả chuỗi thô, KHÔNG trả enum và KHÔNG tự áp mặc định.** Luật "thiếu cấu hình
 * / giá trị lạ ⇒ `on_close`" là quyết định của INVENTORY: nó nói lúc nào hàng bị
 * trừ khỏi kho, và hạ về `on_close` là chọn phương án trừ muộn nhất — an toàn
 * nhất cho tồn kho. Đưa mặc định vào đây là chuyển một quyết định về hàng tồn
 * sang module không chịu hậu quả của nó, và giấu luôn ca "cột chứa giá trị lạ"
 * mà Inventory hiện đang xử lý tường minh.
 */
interface BranchStockDeductionTiming
{
    /**
     * Giá trị thô đã lưu, hoặc `null` khi chi nhánh chưa có hàng cấu hình —
     * và cũng `null` khi truy vấn không đọc được (bảng chưa migrate trong một
     * ngữ cảnh test tối giản). Cả hai đều dẫn về cùng một mặc định ở phía
     * Inventory, đúng như hành vi trước khi có cổng này.
     */
    public function rawTimingFor(string $branchId): ?string;
}
