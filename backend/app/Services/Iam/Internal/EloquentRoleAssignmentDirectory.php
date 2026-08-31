<?php

declare(strict_types=1);

namespace App\Services\Iam\Internal;

use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use App\Support\Iam\RoleTemplateMatrix;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #1622 — hiện thực {@see RoleAssignmentDirectory}.
 *
 * Đây là chỗ **DUY NHẤT** ngoài PlatformIntegration được phép biết bảng tên gì:
 * mọi truy vấn `role_user_pivots` của Notifications giờ đi qua đây. Bảng vẫn
 * đọc bằng query builder (không qua model) vì bản cũ đã vậy và đổi sang Eloquent
 * là đổi số truy vấn trên đường gửi thông báo — việc riêng, không gộp vào một PR
 * dời ranh giới.
 *
 * `distinct()` giữ nguyên từng chỗ: một user có nhiều dòng phân công (nhiều vai,
 * nhiều chi nhánh) và bỏ nó đi thì danh sách người nhận có bản trùng ⇒ **gửi
 * trùng thông báo**, đúng loại lỗi không ai báo lỗi.
 *
 * ## #2460 — cổng này PHẢI đọc pivot cùng cách với tầng policy
 *
 * `User::getRolesForContext()` + `hasRoleInContext()` đã hiểu hai điều mà cổng
 * này từng bỏ qua. Lệch nhau nghĩa là cùng một người **được phép** làm việc ở
 * một chi nhánh nhưng **không bao giờ được báo** việc đó xảy ra:
 *
 * 1. **Slug tương đương.** Platform cấp vai dưới tên `tempo-admin`/`tempo-manager`
 *    (xem `UserProvisioner`), không phải slug template `org-admin`. `hasRoleInContext`
 *    khai triển qua {@see RoleTemplateMatrix::equivalentSlugs()}; cổng này thì so
 *    chuỗi trần, nên **mọi user SSO đều vô hình**. Đo trên production 2026-08-11:
 *    1/1 user giữ `tempo-admin`, không ai giữ slug ma trận.
 *
 * 2. **`branch_id IS NULL` nghĩa là MỌI chi nhánh**, không phải "không chi nhánh
 *    nào". Đó là cách `UserProvisioner::syncRoleScopes()` ghi lại
 *    `all_branches_access` của Platform: một dòng duy nhất, branch để trống.
 *    Truy vấn `where branch_id = X` bỏ đúng những người quyền cao nhất.
 *
 * ### Một chỗ CỐ Ý lệch khỏi tầng policy
 *
 * `getRolesForContext()` còn nhận dòng `(organization_id IS NULL AND branch_id
 * IS NULL)` — vai toàn cục, không thuộc tổ chức nào. Cổng này **không** nhận.
 * Policy trả lời "người này có được phép làm ở đây không", và một vai toàn cục
 * thì được. Cổng này trả lời "phải báo cho ai", và bắn thông báo tới một vai
 * không có tổ chức là **rò dữ liệu xuyên tenant**, không phải tính năng.
 */
final class EloquentRoleAssignmentDirectory implements RoleAssignmentDirectory
{
    private const TABLE = 'role_user_pivots';

    public function userIdsWithRoleInOrganization(string $roleSlug, string $organizationId): array
    {
        return $this->stringIds(
            $this->withRole($roleSlug)
                ->where(self::TABLE.'.organization_id', $organizationId)
                ->distinct()
                ->pluck(self::TABLE.'.user_id')
                ->all(),
        );
    }

    public function userIdsWithRoleInBranch(string $roleSlug, string $branchId): array
    {
        return $this->stringIds(
            $this->withRole($roleSlug)
                ->where($this->coversBranch([$branchId]))
                ->distinct()
                ->pluck(self::TABLE.'.user_id')
                ->all(),
        );
    }

    public function userIdsWithRoleInOrganizations(string $roleSlug, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return $this->stringIds(
            $this->withRole($roleSlug)
                ->whereIn(self::TABLE.'.organization_id', $organizationIds)
                ->distinct()
                ->pluck(self::TABLE.'.user_id')
                ->all(),
        );
    }

    public function userIdsInOrganizations(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return $this->stringIds(
            DB::table(self::TABLE)
                ->whereIn('organization_id', $organizationIds)
                ->distinct()
                ->pluck('user_id')
                ->all(),
        );
    }

    public function assignmentsInBranches(array $branchIds, ?string $roleSlug = null): array
    {
        if ($branchIds === []) {
            return [];
        }

        $query = $roleSlug === null
            ? DB::table(self::TABLE)
            : $this->withRole($roleSlug);

        $rows = $query
            ->where($this->coversBranch($branchIds))
            ->select([self::TABLE.'.user_id', self::TABLE.'.branch_id', self::TABLE.'.organization_id'])
            ->distinct()
            ->get();

        // Người giữ vai toàn tổ chức không có `branch_id` để trả về, nhưng hợp
        // đồng đòi cặp (user, branch) — chỗ gọi dùng nó để gom theo cửa hàng.
        // Nên trải họ ra ĐÚNG những chi nhánh được hỏi mà thuộc tổ chức của họ.
        $organizationByBranch = $this->organizationIdByBranch($branchIds);
        $pairs = [];

        foreach ($rows as $row) {
            if (filled($row->branch_id)) {
                $pairs[(string) $row->user_id.'|'.(string) $row->branch_id] = [
                    'userId' => (string) $row->user_id,
                    'branchId' => (string) $row->branch_id,
                ];

                continue;
            }

            foreach ($organizationByBranch as $branchId => $organizationId) {
                if ($organizationId !== (string) $row->organization_id) {
                    continue;
                }

                $pairs[(string) $row->user_id.'|'.$branchId] = [
                    'userId' => (string) $row->user_id,
                    'branchId' => (string) $branchId,
                ];
            }
        }

        return array_values($pairs);
    }

    public function userIdsAssignedToBranch(array $userIds, string $branchId): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->stringIds(
            DB::table(self::TABLE)
                ->whereIn('user_id', $userIds)
                ->where($this->coversBranch([$branchId]))
                ->pluck('user_id')
                ->all(),
        );
    }

    /**
     * Điều kiện "dòng phân công này phủ ít nhất một trong các chi nhánh đã cho".
     *
     * Gồm hai vế, và vế thứ hai là cái #2460 vá: `branch_id IS NULL` +
     * `organization_id` khớp tổ chức sở hữu chi nhánh = `all_branches_access`.
     *
     * @param  list<string>  $branchIds
     */
    private function coversBranch(array $branchIds): \Closure
    {
        $organizationIds = array_values(array_unique(array_values(
            $this->organizationIdByBranch($branchIds),
        )));

        return static function (Builder $query) use ($branchIds, $organizationIds): void {
            $query->whereIn(self::TABLE.'.branch_id', $branchIds);

            if ($organizationIds === []) {
                return;
            }

            $query->orWhere(static function (Builder $query) use ($organizationIds): void {
                $query->whereNull(self::TABLE.'.branch_id')
                    ->whereIn(self::TABLE.'.organization_id', $organizationIds);
            });
        };
    }

    /**
     * `branches` chỉ mang `console_organization_id` (khoá của Platform), còn
     * `role_user_pivots.organization_id` là khoá LOCAL — nên phải bắc qua
     * `organizations`. Nối sai hai khoá này là cách êm ái nhất để rò tenant.
     *
     * Alias BẮT BUỘC ở `select`: cả hai bảng đều có cột `id`, và
     * `pluck('organizations.id', 'branches.id')` trên một join sẽ đọc nhầm cột —
     * trả về map branchId ⇒ branchId, im lặng và sai.
     *
     * @param  list<string>  $branchIds
     * @return array<string, string> branchId ⇒ organizationId local
     */
    private function organizationIdByBranch(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        return DB::table('branches')
            ->join('organizations', 'organizations.console_organization_id', '=', 'branches.console_organization_id')
            ->whereIn('branches.id', $branchIds)
            ->select(['branches.id as scoped_branch_id', 'organizations.id as scoped_organization_id'])
            ->pluck('scoped_organization_id', 'scoped_branch_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function withRole(string $roleSlug): Builder
    {
        return DB::table(self::TABLE)
            ->join('roles', 'roles.id', '=', self::TABLE.'.role_id')
            ->whereIn('roles.slug', RoleTemplateMatrix::equivalentSlugs($roleSlug));
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<string>
     */
    private function stringIds(array $ids): array
    {
        return array_values(array_unique(array_map(static fn ($id): string => (string) $id, $ids)));
    }
}
