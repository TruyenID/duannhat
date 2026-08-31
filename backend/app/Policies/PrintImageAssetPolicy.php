<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrintImageAsset;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * #1957 mảnh B — quyền cho ảnh in.
 *
 * Cùng cặp quyền với {@see PrintTemplatePolicy}: đọc cần `menu.manage`, ghi cần
 * `catalog.approve` (TR-37). Cố ý giống hệt — một logo và một mẫu phiếu cùng là
 * thứ brand phát hành xuống mọi máy quán mà không qua bản phát hành phần mềm
 * nào, nên hai bề mặt lệch quyền nhau sẽ chỉ tạo ra một đường vòng.
 *
 * Không có `delete`. Một ảnh đã publish phải còn render được mãi để bản in lại
 * của một phiếu cũ là trung thực (TR-28/TR-39) — hệt như template. Đường ra là
 * publish một phiên bản MỚI, không bao giờ là un-publish.
 */
class PrintImageAssetPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'menu.manage');
    }

    public function view(User $user, PrintImageAsset $asset): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $asset, 'menu.manage');
    }

    /** Tải lên / publish ở tầng BRAND — chỉ HQ. */
    public function manageBrand(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.approve');
    }

    public function create(User $user): bool
    {
        return $this->manageBrand($user);
    }

    public function update(User $user, PrintImageAsset $asset): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $asset, 'catalog.approve');
    }

    /** Bản ghi đè ở tầng shop. */
    public function manageShopOverride(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'shop.manage');
    }
}
