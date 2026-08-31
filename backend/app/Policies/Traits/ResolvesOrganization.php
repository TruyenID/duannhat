<?php

namespace App\Policies\Traits;

use App\Models\Organization;
use App\Models\User;
use App\Services\Iam\UserWorkspaceAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Checks if a model belongs to the organization resolved from the current request.
 *
 * Context always comes from the URL (ResolveBrandFromSlug sets request.organization_id).
 * Do NOT use users.console_organization_id for org boundary checks — a single user
 * can have roles in multiple brands, so the request URL is the authoritative context.
 */
trait ResolvesOrganization
{
    protected function hasOrganizationPermission(User $user, string $permission): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $organizationId !== null
            && $user->hasPermission($permission, $organizationId, $this->resolveLocalBranchId());
    }

    protected function belongsToUserOrg(User $user, Model $model): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        if ($organizationId === null || $organizationId !== $model->organization_id) {
            return false;
        }

        // #1122 (second layer of #904's decision) — branch IS an isolation
        // boundary. When the model carries a branch dimension, the user must
        // hold an org-wide pivot or a pivot on THAT branch — the same
        // predicate ResolvesShopContext enforces at the route ring, repeated
        // here so a route that skips shop-context middleware still cannot
        // reach another branch's rows. Branch-less models (brand/org-level
        // rows) keep the pure org check.
        $modelBranchId = $model->getAttribute('branch_id');
        if ($modelBranchId === null || $modelBranchId === '') {
            return true;
        }

        return app(UserWorkspaceAccess::class)
            ->canAccessBranch($user, (string) $modelBranchId, $organizationId);
    }

    protected function belongsToUserOrgWithPermission(User $user, Model $model, string $permission): bool
    {
        return $this->belongsToUserOrg($user, $model)
            && $user->hasPermission($permission, $model->organization_id, $this->resolveLocalBranchId());
    }

    protected function resolveLocalOrgId(): ?string
    {
        return request()?->attributes->get('organization_id');
    }

    protected function resolveLocalBranchId(): ?string
    {
        return request()?->attributes->get('branch_id')
            ?? request()?->attributes->get('shop_id');
    }
}
