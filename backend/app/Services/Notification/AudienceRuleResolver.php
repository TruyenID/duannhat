<?php

namespace App\Services\Notification;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Plan-023 M8 T8.3 — shorthand audience resolver for system rules.
 *
 * Resolves action.audience_rule JSON into a Collection<User> using
 * the firing model's context.
 *
 * Shorthand types:
 *   role_scoped  — users with role slug in `role_user_pivots`, scoped by:
 *                  'brand'        → org IDs from brand.console_organization_id
 *                  'branch'       → branch_id read from model field
 *                  'organization' → organization_id read from model field
 *   model_user   — single User via FK field on the model
 *   union        — recursive union of multiple rules (deduped by user ID)
 */
class AudienceRuleResolver
{
    /** #1622 — cổng đọc phân công vai của PlatformIntegration. */
    private function roles(): RoleAssignmentDirectory
    {
        return app(RoleAssignmentDirectory::class);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return Collection<int, User>
     */
    public function resolve(array $rule, Model $model): Collection
    {
        return match ($rule['type'] ?? '') {
            'role_scoped' => $this->resolveRoleScoped($rule, $model),
            'model_user' => $this->resolveModelUser($rule, $model),
            'union' => $this->resolveUnion($rule, $model),
            default => collect(),
        };
    }

    private function resolveRoleScoped(array $rule, Model $model): Collection
    {
        $role = (string) ($rule['role'] ?? '');
        $scope = (string) ($rule['scope'] ?? 'brand');

        if ($scope === 'organization') {
            $orgId = $this->getField($model, $rule['org_field'] ?? 'organization_id');
            if (! $orgId) {
                return collect();
            }

            return $this->usersWithRoleInOrg($role, (string) $orgId);
        }

        if ($scope === 'branch') {
            $branchId = $this->getField($model, $rule['branch_field'] ?? 'branch_id');
            if (! $branchId) {
                return collect();
            }

            return $this->usersWithRoleInBranch($role, (string) $branchId);
        }

        // scope = 'brand' (default)
        $brandId = $this->getField($model, $rule['brand_field'] ?? 'brand_id');
        if (! $brandId) {
            return collect();
        }

        return $this->usersWithRoleInBrand($role, (string) $brandId);
    }

    private function resolveModelUser(array $rule, Model $model): Collection
    {
        $field = (string) ($rule['field'] ?? 'user_id');
        $userId = $this->getField($model, $field);

        if (! $userId) {
            return collect();
        }

        $user = User::find($userId);

        return $user ? collect([$user]) : collect();
    }

    private function resolveUnion(array $rule, Model $model): Collection
    {
        $members = (array) ($rule['members'] ?? []);

        return collect($members)
            ->flatMap(fn ($member) => $this->resolve((array) $member, $model))
            ->unique('id')
            ->values();
    }

    private function usersWithRoleInOrg(string $slug, string $organizationId): Collection
    {
        $userIds = $this->roles()->userIdsWithRoleInOrganization($slug, $organizationId);

        return User::query()->whereIn('id', $userIds)->get();
    }

    private function usersWithRoleInBranch(string $slug, string $branchId): Collection
    {
        $userIds = $this->roles()->userIdsWithRoleInBranch($slug, $branchId);

        return User::query()->whereIn('id', $userIds)->get();
    }

    private function usersWithRoleInBrand(string $slug, string $brandId): Collection
    {
        $brand = Brand::query()->find($brandId);
        if ($brand === null) {
            return collect();
        }

        $orgIds = Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->pluck('id')
            ->all();

        if ($orgIds === []) {
            return collect();
        }

        $userIds = $this->roles()->userIdsWithRoleInOrganizations($slug, $orgIds);

        return User::query()->whereIn('id', $userIds)->get();
    }

    private function getField(Model $model, string $field): mixed
    {
        return data_get($model, $field);
    }
}
