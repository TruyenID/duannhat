<?php

namespace App\Policies;

use App\Models\TillSession;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Plan 036 — Manager Till Tracking authorization.
 *
 * Gates the four read endpoints under /shops/{shopSlug}/till/*:
 * dashboard, session list, session detail, Z-report PDF.
 *
 * All actions require manager+: shop-manager (branch-scoped) OR org-manager /
 * org-admin (brand-wide). Staff and shop-staff get false everywhere — these
 * surfaces are the manager's monitoring tools, not the cashier's.
 *
 * Branch isolation: the ResolveShopFromSlug middleware stamps
 * request.attributes.shop_id (Branch UUID) and request.attributes.organization_id
 * before the policy fires. {@see resolveBranchId} pulls those out so a
 * shop-manager assigned to one specific branch only passes the check on
 * that branch's URLs.
 *
 * Per-session policies (viewSession, printZReport) additionally verify
 * session.branch_id matches the resolved shop — controllers map mismatches
 * to 404 (not 403, per DESIGN §Endpoint #3) to avoid leaking existence.
 *
 * | Action          | shop-staff | staff | shop-manager | org-manager | org-admin |
 * |-----------------|------------|-------|--------------|-------------|-----------|
 * | viewDashboard   |     ❌      |   ❌   |       ✅      |      ✅      |     ✅     |
 * | viewSessions    |     ❌      |   ❌   |       ✅      |      ✅      |     ✅     |
 * | viewSession     |     ❌      |   ❌   |       ✅      |      ✅      |     ✅     |
 * | printZReport    |     ❌      |   ❌   |       ✅      |      ✅      |     ✅     |
 */
class ShopTillTrackingPolicy
{
    use ResolvesOrganization;

    public function viewDashboard(User $user): bool
    {
        return $this->isHqRole($user) || $this->isShopManager($user);
    }

    public function viewSessions(User $user): bool
    {
        return $this->isHqRole($user) || $this->isShopManager($user);
    }

    public function viewSession(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

    public function printZReport(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

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

    private function resolveBranchId(): ?string
    {
        return request()?->attributes->get('shop_id');
    }
}
