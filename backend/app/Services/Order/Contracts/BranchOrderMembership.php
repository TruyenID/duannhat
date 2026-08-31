<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #2878 — Ordering công bố: **đơn này có thuộc chi nhánh kia không?**
 *
 * `CashDeviceTransactionIntake` hỏi câu đó để kiểm phạm vi một `customer_order_id`
 * do THIẾT BỊ gửi lên. Trước bản vá nó tự `CustomerOrder::whereKey(...)`, tức
 * Payments đọc thẳng model của Ordering — deptrac bắt đúng.
 *
 * Cùng hình dạng với {@see CustomerOrderPresence} (#1596): trả `bool`, KHÔNG
 * trả đơn. Chỗ gọi chỉ cần giữ-hay-bỏ một FK; trả cả đơn là mời người sau đọc
 * chi tiết đơn hàng từ một module không có việc gì với nó.
 *
 * **Đơn không tồn tại và đơn thuộc chi nhánh khác trả về CÙNG một `false`** —
 * có chủ ý. Chỗ gọi xử hai ca đó y hệt nhau (bỏ giá trị, giữ hàng), và phân
 * biệt chúng sẽ nói cho một thiết bị biết đơn nào có thật ở chi nhánh khác.
 */
interface BranchOrderMembership
{
    public function orderBelongsToBranch(string $orderId, string $branchId): bool;
}
