<?php

declare(strict_types=1);

namespace App\Services\Iam\Contracts;

/**
 * #1622 — cổng đọc PHÂN CÔNG VAI mà PlatformIntegration công bố.
 *
 * Năm resolver của Notifications đọc thẳng `role_user_pivots` bằng
 * `DB::table()` — **10/21 chỗ** trong lớp nợ mà `architecture:raw-table-reads`
 * (#1621) vừa đo được, và là cụm lớn nhất. Bảng đó là của
 * PlatformIntegration (`RoleUserPivot`), nhưng vì không class nào được import
 * nên deptrac **không thấy một cạnh nào** — nợ vô hình.
 *
 * Sáu method dưới là **sáu truy vấn CÓ THẬT**, chép nguyên hình dạng từ chỗ
 * chúng vừa rời đi. Cố ý KHÔNG gộp thành một method "linh hoạt" nhận mảng điều
 * kiện: gộp lại thì phạm vi (org / brand-nhiều-org / branch) trở thành tham số
 * runtime, và một chỗ gọi quên truyền phạm vi sẽ **gửi thông báo cho toàn bộ
 * người dùng của mọi tổ chức** mà không có gì đỏ lên.
 *
 * Trả về **id dạng chuỗi**, không trả model: đó là điều kiện để cổng nằm được
 * trong `PublishedContracts` (layer chỉ được phụ thuộc hai kernel). Chỗ gọi tự
 * nạp `User` — chúng vốn đã làm đúng như vậy.
 */
interface RoleAssignmentDirectory
{
    /**
     * User có vai `$roleSlug` trong MỘT tổ chức.
     *
     * @return list<string>
     */
    public function userIdsWithRoleInOrganization(string $roleSlug, string $organizationId): array;

    /**
     * User có vai `$roleSlug` gắn với MỘT chi nhánh.
     *
     * @return list<string>
     */
    public function userIdsWithRoleInBranch(string $roleSlug, string $branchId): array;

    /**
     * User có vai `$roleSlug` trong BẤT KỲ tổ chức nào của danh sách.
     *
     * Dùng cho phạm vi thương hiệu: một brand trải trên nhiều tổ chức local,
     * nên chỗ gọi phân giải brand → orgIds TRƯỚC rồi mới hỏi ở đây.
     *
     * @param  list<string>  $organizationIds
     * @return list<string>
     */
    public function userIdsWithRoleInOrganizations(string $roleSlug, array $organizationIds): array;

    /**
     * User có BẤT KỲ phân công vai nào trong các tổ chức đã cho.
     *
     * @param  list<string>  $organizationIds
     * @return list<string>
     */
    public function userIdsInOrganizations(array $organizationIds): array;

    /**
     * Phân công vai theo chi nhánh — trả kèm `branchId` vì chỗ gọi cần biết
     * user thuộc chi nhánh NÀO (một người có thể có vai ở nhiều cửa hàng).
     *
     * `$roleSlug` null = mọi vai.
     *
     * @param  list<string>  $branchIds
     * @return list<array{userId: string, branchId: string}>
     */
    public function assignmentsInBranches(array $branchIds, ?string $roleSlug = null): array;

    /**
     * Lọc một tập user id xuống những người CÓ phân công ở chi nhánh đã cho.
     *
     * Giao tập, không phải tra cứu: chỗ gọi đã có danh sách người nhận và chỉ
     * cần thu hẹp về đúng cửa hàng.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function userIdsAssignedToBranch(array $userIds, string $branchId): array;
}
