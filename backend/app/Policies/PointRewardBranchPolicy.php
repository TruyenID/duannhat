<?php

namespace App\Policies;

use App\Models\PointRewardBranch;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * #1514 — ai được bật/tắt một phần thưởng cho RIÊNG một chi nhánh.
 *
 * Tách khỏi `PointRewardPolicy` có chủ đích. Công tắc này là quyết định vận
 * hành hằng ngày của cửa hàng ("hôm nay hết dừa, tắt đi"), còn giá điểm và
 * thông số giảm giá là quyết định cấp brand. Gộp vào một quyền `update` thì
 * chỉ ra được một trong hai kết cục sai: shop-manager sửa được giá điểm của
 * cả chuỗi, hoặc shop-manager không tắt nổi món đã hết.
 *
 * `belongsToUserOrg` KHÔNG dùng được ở đây: pivot không có cột
 * `organization_id`. Phạm vi thật là chi nhánh, nên kiểm bằng
 * `resolveLocalBranchId()` — id chi nhánh do `ResolveBranchFromSlug`
 * middleware đặt lên request từ `{shopSlug}` trên URL. Người dùng có vai ở tổ
 * chức nhưng đang đứng ở URL của cửa hàng khác thì vẫn trượt.
 */
class PointRewardBranchPolicy
{
    use ResolvesOrganization;

    /**
     * Bật/tắt cho chi nhánh ĐANG ĐỨNG trên URL.
     *
     * Nhận `?PointRewardBranch` vì lần tắt đầu tiên của một cửa hàng chưa có
     * dòng pivot nào để kiểm — quyền phải trả lời được câu hỏi trước khi đối
     * tượng tồn tại.
     */
    public function setAvailability(User $user, ?PointRewardBranch $pivot = null): bool
    {
        $branchId = $this->resolveLocalBranchId();

        if ($branchId === null) {
            return false;
        }

        if ($pivot !== null && $pivot->branch_id !== $branchId) {
            return false;
        }

        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId)
            || $user->hasRoleInContext('shop-manager', $organizationId);
    }
}
