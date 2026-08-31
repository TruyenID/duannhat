<?php

declare(strict_types=1);

namespace App\Services\Inventory\Contracts;

/**
 * #962 — Notifications hỏi Inventory "ai giữ vai này ở kho này".
 *
 * Đây là mảnh CÒN LẠI của #1622: `RoleResolver` đã đi qua
 * `Iam\Contracts\RoleAssignmentDirectory` cho vai theo org/brand/branch, nhưng
 * vai theo KHO nằm ở `warehouse_members` — bảng của Inventory, không phải của
 * IAM — nên nó ở lại dạng truy vấn thẳng. Cùng khuôn, khác chủ sở hữu.
 *
 * Trả về id chứ không phải model: bên gọi nạp `User` (TenancyKernel) để lấy
 * `notifiable`, nên cổng không cần rò model của Inventory ra ngoài.
 */
interface WarehouseMemberDirectory
{
    /**
     * Id người dùng giữ đúng vai `$memberRole` trong kho `$warehouseId`.
     *
     * @return list<string>
     */
    public function userIdsWithRoleInWarehouse(string $warehouseId, string $memberRole): array;
}
