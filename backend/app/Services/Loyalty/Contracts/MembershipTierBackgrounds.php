<?php

namespace App\Services\Loyalty\Contracts;

use App\Models\Brand;

/**
 * #1772 — Loyalty công bố: **ảnh nền thẻ thành viên theo hạng**.
 *
 * `BrandSettingsService` (module Shop) trước đây phụ thuộc thẳng vào
 * `MembershipTierBackgroundService` của Loyalty, và deptrac bắt đúng cạnh đó.
 * Cùng hình dạng với `CustomerOrderReassignment` (#1550): module gọi ra lệnh qua
 * một cổng HẸP, module sở hữu tự giữ lấy cách làm.
 *
 * Cổng chỉ gồm **ba** method mà Shop thật sự dùng — không phải toàn bộ bề mặt
 * của service. `sanitize()` và `decorate()` cố ý ở ngoài: chúng là chi tiết nội
 * bộ của Loyalty, và đưa vào cổng là mời module khác phụ thuộc vào thứ Loyalty
 * còn muốn đổi.
 */
interface MembershipTierBackgrounds
{
    /** Khoá các hạng thành viên đang có, theo thứ tự hiển thị. @return list<string> */
    public function tierKeys(): array;

    /**
     * Giữ vĩnh viễn các File được bản đồ hạng trỏ tới.
     *
     * @param  array<string, string>|null  $map  hạng → file id
     */
    public function retain(?array $map): void;

    /**
     * URL ảnh nền theo hạng, giải ra LÚC ĐỌC.
     *
     * Giải lúc đọc chứ không lưu URL tuyệt đối: `File::getUrl()` dựng lại đường
     * dẫn từ cấu hình disk hiện hành, nên đổi disk không làm chết ảnh cũ.
     *
     * @return array<string, string|null>
     */
    public function urls(?Brand $brand): array;
}
