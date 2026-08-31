<?php

namespace App\Services\Order\Contracts;

use App\Services\Order\ValueObjects\SplitBillSettings;

/**
 * #962 — cổng Ordering công bố cho Payments: cấu hình CHIA HOÁ ĐƠN của chi nhánh.
 *
 * Payments xác thực một sub-check "chia theo món" bằng cách tính lại tổng dự
 * kiến, và ba tham số của phép tính đó (`split_bill_rounding_mode`,
 * `currency_code`, `service_charge_rate`) nằm trên `shop_order_settings` — bảng
 * của Ordering. Trước cổng này, Payments đọc thẳng model đó.
 *
 * Cổng CHỈ đọc cấu hình. Nó không tính tiền, không làm tròn, không biết gì về
 * sub-check — phép tính ở lại `SplitByItemsCalculator`.
 */
interface BranchSplitBillPolicy
{
    /**
     * Chi nhánh chưa có `shop_order_settings` thì trả về bộ mặc định, không ném
     * lỗi: đường cũ dùng `?->` + `??` và một shop nửa cấu hình vẫn chia được bill.
     */
    public function forBranch(?string $branchId): SplitBillSettings;
}
