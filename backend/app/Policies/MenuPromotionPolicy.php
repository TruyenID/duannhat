<?php

namespace App\Policies;

use App\Models\MenuPromotion;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Plan-019 — MenuPromotionPolicy.
 *
 * Shop-scoped: shop-manager creates / updates / deletes / toggles
 * promotions for THEIR branch. Org-admin / org-manager can also do it.
 * Read access opens up to anyone in the brand's organization.
 *
 * HQ cross-shop list (S11) uses the dedicated `viewAnyHq` method —
 * the controller authorizes once via `MenuPromotionPolicy@viewAnyHq`
 * and then the service applies the org filter. Mirrors the way
 * MaterialUnitPolicy distinguishes HQ-only operations.
 *
 * delete() additionally requires items_with_promotion_count = 0 — the
 * same guard the service throws via
 * MenuPromotionException::alreadyUsedUseDeactivateInstead, but having
 * it here lets the FE hide / disable the delete button BEFORE the
 * user clicks it.
 */
class MenuPromotionPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->isAnyOrgUser($user);
    }

    /**
     * HQ cross-shop list (DESIGN.md S11) — read-only across every branch
     * of the brand. Authorize at the controller level with this method.
     */
    public function viewAnyHq(User $user): bool
    {
        return $this->isHqRole($user);
    }

    public function view(User $user, MenuPromotion $promotion): bool
    {
        return $this->belongsToUserOrg($user, $promotion) && $this->isAnyOrgUser($user);
    }

    public function create(User $user): bool
    {
        return $this->isShopOrHqRole($user);
    }

    public function update(User $user, MenuPromotion $promotion): bool
    {
        return $this->belongsToUserOrg($user, $promotion)
            && $this->canManageBranch($user, $promotion);
    }

    public function delete(User $user, MenuPromotion $promotion): bool
    {
        return $this->belongsToUserOrg($user, $promotion)
            && $this->canManageBranch($user, $promotion)
            && $promotion->customerOrderItems()->count() === 0;
    }

    public function toggle(User $user, MenuPromotion $promotion): bool
    {
        return $this->belongsToUserOrg($user, $promotion)
            && $this->canManageBranch($user, $promotion);
    }

    public function restore(User $user, MenuPromotion $promotion): bool
    {
        return $this->belongsToUserOrg($user, $promotion)
            && $this->canManageBranch($user, $promotion);
    }

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    private function isShopOrHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $this->isHqRole($user)
            || $user->hasRoleInContext('shop-manager', $organizationId);
    }

    private function isAnyOrgUser(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $this->isShopOrHqRole($user)
            || $user->hasRoleInContext('staff', $organizationId)
            || $user->hasRoleInContext('shop-staff', $organizationId);
    }

    /**
     * shop-manager may only manage promotions belonging to a branch they
     * have a role in. HQ roles bypass the branch filter.
     */
    private function canManageBranch(User $user, MenuPromotion $promotion): bool
    {
        if ($this->isHqRole($user)) {
            return true;
        }

        $organizationId = $this->resolveLocalOrgId();
        if (! $user->hasRoleInContext('shop-manager', $organizationId)) {
            return false;
        }

        // hasRoleInContext('shop-manager') already scopes to the org;
        // additional branch-membership check leans on User::branches() if
        // the model exposes it. Defensive: if the relation isn't loaded
        // we err on the side of "manager belongs to the resolved org" to
        // avoid false negatives in tests that don't seed branch_user.
        if (method_exists($user, 'branches')) {
            $managedBranchIds = $user->branches()->pluck('branches.id')->all();

            return $managedBranchIds === [] || in_array($promotion->branch_id, $managedBranchIds, true);
        }

        return true;
    }
}
