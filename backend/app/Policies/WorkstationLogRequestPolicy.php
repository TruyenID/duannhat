<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;
use App\Services\Iam\UserWorkspaceAccess;
use App\Support\Iam\RoleTemplateMatrix;

/**
 * #2901 — ai được yêu cầu kéo log máy trạm, và ai được đọc chúng.
 *
 * ## Không dựng bề mặt quyền thứ hai
 *
 * Chốt của chủ dự án: đi qua đúng cổng vai đã có ({@see RoleTemplateMatrix}),
 * không phát minh permission slug mới. Lý do rất cụ thể ở repo này: một slug
 * mới phải được seed vào MỌI tổ chức đang chạy trước khi nó cấp được gì, và
 * một permission vắng mặt trong im lặng **fail-open** đúng ngày nó lên
 * production. Cùng lý lẽ đã ghi ở {@see PrintTemplatePolicy}.
 *
 * ## Vì sao `shop.manage`
 *
 * Ma trận cấp `shop.manage` cho `org-admin` · `org-manager` · `shop-manager`,
 * và KHÔNG cấp cho `staff` / `shop-staff`. Đó đúng là đường phân chia cần ở
 * đây: máy trạm là thiết bị VẬT LÝ của quán, nên ai quản lý quán thì điều tra
 * được máy của quán đó; còn thu ngân thì không — log mang dữ liệu vận hành mà
 * một ca bán hàng không có lý do đọc.
 *
 * `shop.view` (mà `shop-staff` có) cố ý KHÔNG được dùng: đây là bề mặt PII,
 * nên nó phải nằm sau vai quản lý chứ không sau vai xem.
 *
 * ## Cách ly chi nhánh đi kèm, không tách rời
 *
 * `hasOrganizationPermission()` lấy org + branch từ **request attributes** (do
 * `ResolveBrandFromSlug` đặt), không từ `users.console_organization_id` — một
 * user có vai ở nhiều brand, nên ngữ cảnh URL mới là căn cứ. Và
 * `branch_id IS NULL` trên pivot nghĩa là MỌI chi nhánh (`all_branches_access`),
 * không phải "không chi nhánh nào" (#2460) — điều đó do
 * {@see UserWorkspaceAccess::canAccessBranch()} lo, và
 * controller gọi nó cho từng thiết bị đích.
 */
class WorkstationLogRequestPolicy
{
    use ResolvesOrganization;

    /** Đọc danh sách yêu cầu đã tạo của một brand. */
    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'shop.manage');
    }

    /**
     * Tạo một yêu cầu kéo log.
     *
     * Cùng quyền với đọc, có chủ đích: kéo log KHÔNG phải một hành vi "phê
     * duyệt" — nó không đổi gì ở quán, chỉ chuyển một bản sao đã lọc lên
     * Cloud. Đặt nó sau một vai cao hơn đọc sẽ tạo ra tình huống người điều
     * tra nhìn thấy màn hình mà không bấm được nút, tức mời họ đi nhờ người
     * khác bấm hộ — và cái đó tệ hơn cho dấu vết kiểm toán.
     */
    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'shop.manage');
    }
}
