<?php

declare(strict_types=1);

namespace App\Services\Shop\Contracts;

/**
 * #2879 (T2 của #2876) — Organization công bố: **chi nhánh này chịu được lệch
 * tiền mặt bao nhiêu trước khi báo động?**
 *
 * `CashDrawerReconciliationService` (Payments) hỏi câu đó. Trước bản vá nó tự
 * đi `Branch → console_brand_id → Brand → BrandOrderPolicy`, tức Payments đọc
 * thẳng model của Organization — deptrac bắt đúng.
 *
 * Cổng trả về **câu trả lời cuối cùng**, không phải mảnh ghép: Payments không
 * cần biết ngưỡng sống ở bảng nào, và càng không cần biết `branches` KHÔNG có
 * `brand_id` mà chỉ có `console_brand_id` (cùng họ bẫy với
 * `console_organization_id` ở #2847). Kiến thức về mirror console ở lại đúng
 * module sở hữu nó.
 *
 * `null` = chưa cấu hình ⇒ chỗ gọi dùng mặc định của nó. KHÔNG trả 0 thay cho
 * "chưa cấu hình": 0 là một ngưỡng HỢP LỆ nghĩa là "báo mọi lệch", và trộn hai
 * thứ đó sẽ âm thầm biến lựa chọn của một brand thành giá trị mặc định.
 */
interface BranchCashVarianceTolerance
{
    /** Ngưỡng tính bằng minor units, hoặc null khi brand chưa cấu hình. */
    public function toleranceMinorForBranch(string $branchId): ?int;
}
