<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Plan-019 — CouponPolicy.
 *
 * HQ-only management surface (org-admin / org-manager) for create /
 * update / delete / pause / resume. Read access opens up to any user
 * already in the brand's organization (via ResolvesOrganization).
 *
 * delete() additionally enforces "only deletable while times_used = 0"
 * — the same guard the service throws via CouponException::
 * alreadyRedeemed, but having it here lets the FE hide / disable the
 * delete button BEFORE the user clicks it.
 */
class CouponPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->isAnyOrgUser($user);
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->belongsToUserOrg($user, $coupon) && $this->isAnyOrgUser($user);
    }

    public function create(User $user): bool
    {
        return $this->isHqRole($user);
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->belongsToUserOrg($user, $coupon) && $this->isHqRole($user);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $this->belongsToUserOrg($user, $coupon)
            && $this->isHqRole($user)
            && (int) $coupon->times_used === 0;
    }

    public function restore(User $user, Coupon $coupon): bool
    {
        return $this->belongsToUserOrg($user, $coupon) && $this->isHqRole($user);
    }

    public function pause(User $user, Coupon $coupon): bool
    {
        return $this->belongsToUserOrg($user, $coupon) && $this->isHqRole($user);
    }

    public function resume(User $user, Coupon $coupon): bool
    {
        return $this->belongsToUserOrg($user, $coupon) && $this->isHqRole($user);
    }

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    private function isAnyOrgUser(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $this->isHqRole($user)
            || $user->hasRoleInContext('shop-manager', $organizationId)
            || $user->hasRoleInContext('staff', $organizationId)
            || $user->hasRoleInContext('shop-staff', $organizationId);
    }
}
