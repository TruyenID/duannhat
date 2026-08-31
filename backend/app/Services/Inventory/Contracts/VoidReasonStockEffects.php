<?php

declare(strict_types=1);

namespace App\Services\Inventory\Contracts;

/**
 * #962 — tra cứu LÝ DO VOID theo id, do Ordering hiện thực.
 *
 * `void_reasons` là bảng của Ordering (`config/modules.php`), nhưng người ĐỌC
 * nó để quyết định có bù kho hay không là Inventory. Trước cổng này, cả
 * `StockDeductionService` lẫn adapter `EloquentOrderLineStockDeduction` đều
 * `use App\Models\VoidReason` — hai cạnh Inventory → Ordering cho đúng một
 * phép tra cứu theo khoá chính.
 *
 * Không tìm thấy ⇒ `null`, và `null` rơi vào nhánh "không rõ lý do → không bù
 * + log cảnh báo" của bảng #1149. Đó là hành vi ĐÚNG, không phải nuốt lỗi: bù
 * kho cho một lý do không đọc được là đoán, và đoán sai thì hàng tồn lệch mà
 * không ai biết. (Chép nguyên quyết định đã ghi trong docblock của
 * {@see OrderLineStockDeduction} — cổng này không nghĩ lại nó.)
 */
interface VoidReasonStockEffects
{
    public function find(string $voidReasonId): ?VoidReasonStockEffect;
}
