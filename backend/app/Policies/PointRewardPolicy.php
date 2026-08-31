<?php

namespace App\Policies;

use App\Models\PointReward;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * #1514 — PointRewardPolicy.
 *
 * Cùng hình dạng với `CouponPolicy`, và có lý do: một phần thưởng CHÍNH LÀ
 * bản mẫu của coupon (đổi điểm ⇒ mint coupon theo đúng thông số này). Ai sửa
 * được coupon thì sửa được phần thưởng; ai không sửa được coupon mà sửa được
 * phần thưởng thì đã có đường vòng để tự phát coupon cho mình.
 *
 * Ghi là HQ-only (org-admin / org-manager). Đọc mở cho mọi người trong tổ
 * chức — nhân viên cửa hàng cần thấy catalog để trả lời khách hỏi "đổi 200
 * điểm được gì".
 *
 * **Công tắc theo chi nhánh KHÔNG đi qua policy này.** Shop bật/tắt phần
 * thưởng cho cửa hàng mình là ghi vào pivot `point_reward_branches`, không
 * phải ghi vào `point_rewards` — xem `PointRewardBranchPolicy`. Tách ra vì
 * nếu dùng chung `update()` thì hoặc shop-manager sửa được cả giá điểm toàn
 * brand, hoặc shop-manager không tắt được gì cả; cả hai đều sai.
 */
class PointRewardPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->isAnyOrgUser($user);
    }

    public function view(User $user, PointReward $reward): bool
    {
        return $this->belongsToUserOrg($user, $reward) && $this->isAnyOrgUser($user);
    }

    public function create(User $user): bool
    {
        return $this->isHqRole($user);
    }

    public function update(User $user, PointReward $reward): bool
    {
        return $this->belongsToUserOrg($user, $reward) && $this->isHqRole($user);
    }

    /**
     * Xoá được cả khi đã có người đổi — KHÁC `CouponPolicy::delete()`, vốn
     * chặn khi `times_used > 0`.
     *
     * Lý do khác nhau: coupon đã dùng là một dòng tiền, xoá đi thì báo cáo
     * doanh thu mất gốc. Phần thưởng thì không — coupon nó mint ra đã sao
     * chép toàn bộ thông số sang bảng `coupons` rồi, và soft-delete vẫn giữ
     * dòng cho `customer_point_entries.point_reward_id` trỏ vào. Lịch sử
     * điểm của khách không thủng.
     */
    public function delete(User $user, PointReward $reward): bool
    {
        return $this->belongsToUserOrg($user, $reward) && $this->isHqRole($user);
    }

    public function restore(User $user, PointReward $reward): bool
    {
        return $this->belongsToUserOrg($user, $reward) && $this->isHqRole($user);
    }

    public function forceDelete(User $user, PointReward $reward): bool
    {
        return $this->belongsToUserOrg($user, $reward) && $this->isHqRole($user);
    }

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    private function isAnyOrgUser(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $this->isHqRole($user)
            || $user->hasRoleInContext('shop-manager', $organizationId)
            || $user->hasRoleInContext('staff', $organizationId)
            || $user->hasRoleInContext('shop-staff', $organizationId);
    }
}
