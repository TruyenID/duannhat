<?php

namespace App\Services\Notification\AudienceResolvers;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use App\Services\Inventory\Contracts\WarehouseMemberDirectory;
use Illuminate\Support\Collection;

/**
 * Resolves `{type: 'role', role: '<slug>', scope: {...}}` sub-rules.
 *
 * Role lookup is bi-modal in this codebase:
 *
 *   - Warehouse-scoped roles (slug `warehouse_manager`, `warehouse_staff`)
 *     live in `warehouse_members.role` (enum manager|staff). Scope must
 *     include `warehouse_id` to anchor the lookup.
 *
 *   - Org / brand / shop-scoped roles live in `role_user_pivots` joined
 *     to `roles.slug`. Scope `brand_id` maps to all organizations under
 *     that brand's console org; `shop_id` maps to the pivot's
 *     `branch_id` (in this codebase, shop == branch).
 *
 * Trace format: `role:<slug>:<scope-key>:<scope-id>` — e.g.
 * `role:warehouse_manager:warehouse_id:{uuid}`.
 */
class RoleResolver implements AudienceResolver
{
    /** #1622 — cổng đọc phân công vai của PlatformIntegration. */
    private function roles(): RoleAssignmentDirectory
    {
        return app(RoleAssignmentDirectory::class);
    }

    public function type(): string
    {
        return 'role';
    }

    public function resolve(array $rule, Brand $brand): Collection
    {
        $slug = (string) ($rule['role'] ?? '');
        if ($slug === '') {
            return collect();
        }

        $scope = (array) ($rule['scope'] ?? []);

        return match (true) {
            $slug === 'warehouse_manager' && isset($scope['warehouse_id']) => $this->warehouseRole($scope['warehouse_id'], 'manager', $slug),
            $slug === 'warehouse_staff' && isset($scope['warehouse_id']) => $this->warehouseRole($scope['warehouse_id'], 'staff', $slug),
            isset($scope['branch_id']) || isset($scope['shop_id']) => $this->branchRole($slug, $scope['branch_id'] ?? $scope['shop_id']),
            isset($scope['brand_id']) => $this->brandRole($slug, $scope['brand_id'], $brand),
            isset($scope['organization_id']) => $this->orgRole($slug, $scope['organization_id']),
            default => $this->brandRole($slug, $brand->id, $brand),
        };
    }

    /**
     * WarehouseMember-backed resolution.
     */
    private function warehouseRole(string $warehouseId, string $memberRole, string $traceSlug): Collection
    {
        // #962 — `warehouse_members` thuộc Inventory. Cùng khuôn với
        // `$this->roles()` ngay dưới, chỉ khác chủ sở hữu bảng.
        $userIds = app(WarehouseMemberDirectory::class)
            ->userIdsWithRoleInWarehouse($warehouseId, $memberRole);

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user): array => [
                'notifiable' => $user,
                'key' => $user->getMorphClass().':'.$user->getKey(),
                'trace' => "role:{$traceSlug}:warehouse_id:{$warehouseId}",
            ])
            ->values();
    }

    /**
     * role_user_pivots-backed branch/shop-scoped resolution.
     */
    private function branchRole(string $slug, string $branchId): Collection
    {
        $userIds = $this->roles()->userIdsWithRoleInBranch($slug, $branchId);

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user): array => [
                'notifiable' => $user,
                'key' => $user->getMorphClass().':'.$user->getKey(),
                'trace' => "role:{$slug}:branch_id:{$branchId}",
            ])
            ->values();
    }

    /**
     * role_user_pivots-backed brand-scoped resolution. Brand → local orgs
     * (via console_organization_id mirror).
     */
    private function brandRole(string $slug, string $brandId, Brand $brand): Collection
    {
        $targetBrand = (string) $brandId === (string) $brand->id
            ? $brand
            : Brand::query()->find($brandId);

        if ($targetBrand === null) {
            return collect();
        }

        $orgIds = Organization::query()
            ->where('console_organization_id', $targetBrand->console_organization_id)
            ->pluck('id')
            ->all();

        if ($orgIds === []) {
            return collect();
        }

        $userIds = $this->roles()->userIdsWithRoleInOrganizations($slug, $orgIds);

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user): array => [
                'notifiable' => $user,
                'key' => $user->getMorphClass().':'.$user->getKey(),
                'trace' => "role:{$slug}:brand_id:{$brandId}",
            ])
            ->values();
    }

    /**
     * role_user_pivots-backed org-scoped resolution.
     */
    private function orgRole(string $slug, string $organizationId): Collection
    {
        $userIds = $this->roles()->userIdsWithRoleInOrganization($slug, $organizationId);

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user): array => [
                'notifiable' => $user,
                'key' => $user->getMorphClass().':'.$user->getKey(),
                'trace' => "role:{$slug}:organization_id:{$organizationId}",
            ])
            ->values();
    }
}
