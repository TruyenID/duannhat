<?php

declare(strict_types=1);

namespace App\Services\Iam;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\Support\Iam\RoleTemplateMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UserWorkspaceAccess
{
    /** @return Builder<Branch> */
    public function branches(User $user): Builder
    {
        [$organizationIds, $branchIds] = $this->scopes($user);
        $consoleOrganizationIds = Organization::query()
            ->whereIn('id', $organizationIds)
            ->pluck('console_organization_id');

        return Branch::query()->where(function (Builder $query) use ($consoleOrganizationIds, $branchIds): void {
            if ($consoleOrganizationIds->isNotEmpty()) {
                $query->whereIn('console_organization_id', $consoleOrganizationIds);
            }

            if ($branchIds->isNotEmpty()) {
                $method = $consoleOrganizationIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                $query->{$method}('id', $branchIds);
            }

            if ($consoleOrganizationIds->isEmpty() && $branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /** @return Builder<Brand> */
    public function brands(User $user): Builder
    {
        $organizationIds = $this->headquartersOrganizationIds($user);
        $consoleOrganizationIds = Organization::query()
            ->whereIn('id', $organizationIds)
            ->pluck('console_organization_id');

        return Brand::query()->where(function (Builder $query) use ($consoleOrganizationIds): void {
            if ($consoleOrganizationIds->isNotEmpty()) {
                $query->whereIn('console_organization_id', $consoleOrganizationIds);
            }

            if ($consoleOrganizationIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    public function canAccessHeadquarters(User $user, string $organizationId): bool
    {
        return $this->headquartersOrganizationIds($user)->contains($organizationId);
    }

    /**
     * #1122 — can the user act on data of ONE branch? True for an org-wide
     * pivot (`branch_id IS NULL`) on the (local) organization, or a pivot
     * assigned to that branch. Exactly the predicate ResolvesShopContext
     * enforces at the route ring — exposed here so policies can apply it as a
     * second layer on paths that never traverse shop-context middleware.
     */
    public function canAccessBranch(User $user, string $branchId, string $organizationId): bool
    {
        return DB::table('role_user_pivots')
            ->where('user_id', $user->id)
            ->where(fn ($scope) => $scope
                ->where(fn ($orgWide) => $orgWide
                    ->where('organization_id', $organizationId)
                    ->whereNull('branch_id'))
                ->orWhere('branch_id', $branchId))
            ->exists();
    }

    /** @return array{0: Collection<int, string>, 1: Collection<int, string>} */
    private function scopes(User $user): array
    {
        $pivots = DB::table('role_user_pivots')
            ->where('user_id', $user->id)
            ->whereNotNull('organization_id')
            ->get(['organization_id', 'branch_id']);

        return [
            $pivots->whereNull('branch_id')->pluck('organization_id')->unique()->values(),
            $pivots->whereNotNull('branch_id')->pluck('branch_id')->unique()->values(),
        ];
    }

    /** @return Collection<int, string> */
    private function headquartersOrganizationIds(User $user): Collection
    {
        $shopStaffRoleSlugs = RoleTemplateMatrix::equivalentSlugs('shop-staff');

        return DB::table('role_user_pivots')
            ->join('roles', 'roles.id', '=', 'role_user_pivots.role_id')
            ->where('role_user_pivots.user_id', $user->id)
            ->whereNull('role_user_pivots.branch_id')
            // Explicit shop-staff templates belong in a shop workspace only.
            // Other org-wide roles, including custom permission-based roles,
            // may enter HQ and are still constrained by each endpoint policy.
            ->whereNotIn('roles.slug', $shopStaffRoleSlugs)
            ->pluck('role_user_pivots.organization_id')
            ->filter()
            ->unique()
            ->values();
    }
}
