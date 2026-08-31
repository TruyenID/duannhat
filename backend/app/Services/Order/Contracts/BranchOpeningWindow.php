<?php

namespace App\Services\Order\Contracts;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * #962 — cổng GIỜ MỞ CỬA mà Ordering hỏi và Organization hiện thực.
 *
 * `weekly_hours` là sơ đồ vận hành của chi nhánh, thuộc Organization. Ordering
 * hỏi nó vì #1160/#1167: một đơn takeaway chỉ được đặt khi cửa hàng đang mở, và
 * giờ hẹn lấy phải rơi trong giờ mở cửa. Trước cổng này,
 * `CustomerTakeawayOrderService` gọi thẳng `App\Services\Shop\BranchOpeningHours`
 * dạng static.
 *
 * **Nhận `Branch`, không nhận `branchId`.** `App\Models\Branch` là TenancyKernel
 * — mọi module được phép chạm, nên cổng mang nó KHÔNG rò model của một module.
 * Và người gọi vốn đã cầm sẵn instance đó: nhận id rồi nạp lại sẽ đọc một hàng
 * KHÁC với hàng người gọi đang phán xét, đúng kiểu lệch mà một bước "dời chỗ"
 * không được phép tạo ra.
 *
 * Luật giờ giấc là #1091: mọi so sánh chạy trên `branches.timezone`, không phải
 * đồng hồ ứng dụng. Cổng chỉ chuyển tiếp, nó không tự phán lại.
 */
interface BranchOpeningWindow
{
    /**
     * Chi nhánh chưa khai `weekly_hours` thì coi như LUÔN mở — cổng không biến
     * một shop chưa cấu hình thành shop đóng cửa vĩnh viễn.
     */
    public function isOpenAt(Branch $branch, DateTimeInterface $instant): bool;

    /** Thời điểm đóng cửa của ca đang chứa `$instant`; null khi không xác định. */
    public function closingAt(Branch $branch, DateTimeInterface $instant): ?CarbonImmutable;

    /** Lần mở cửa kế tiếp tính từ `$instant`; null khi không xác định. */
    public function nextOpeningAt(Branch $branch, DateTimeInterface $instant): ?CarbonImmutable;
}
