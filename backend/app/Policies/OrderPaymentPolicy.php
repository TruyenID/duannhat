<?php

namespace App\Policies;

use App\Models\OrderPayment;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class OrderPaymentPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function confirm(User $user, OrderPayment $payment): bool
    {
        return $this->belongsToUserOrg($user, $payment);
    }

    /**
     * BLOCKER 2 — refunds move real money, so they are manager-gated.
     *
     * A bare org-membership check (belongsToUserOrg) let ANY org user — a
     * cashier, a device-token operator — fire a live Stripe refund. Refund now
     * requires a manager-level role in the resolved org/branch context, mirroring
     * the plan-032 exit doors on {@see TillSessionPolicy} and MaterialLotPolicy:
     * org-admin || org-manager (HQ) || shop-manager (per-branch). Device-token
     * callers resolve to a Device (not a User) and hold no such role, so they are
     * rejected here.
     */
    public function refund(User $user, OrderPayment $payment): bool
    {
        return $this->belongsToUserOrg($user, $payment)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

    // =========================================================================
    //  Role helpers — same pattern as TillSessionPolicy / MaterialLotPolicy.
    // =========================================================================

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    private function isShopManager(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();
        $branchId = $this->resolveBranchId();

        return $user->hasRoleInContext('shop-manager', $organizationId, $branchId);
    }

    /**
     * Request-resolved branch id (set by ResolveShopFromSlug / ResolvePosShop on
     * shop-scoped routes) so a shop-manager pinned to one branch is accepted in
     * addition to org-wide role rows.
     */
    private function resolveBranchId(): ?string
    {
        return request()?->attributes->get('shop_id');
    }
}
