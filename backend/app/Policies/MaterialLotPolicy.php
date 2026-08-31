<?php

namespace App\Policies;

use App\Models\MaterialLot;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * MaterialLotPolicy — enforces the DESIGN.md §"Authorization matrix" cells.
 *
 * Role mapping:
 *   org-admin (level 100)   → "org-admin" in DESIGN
 *   org-manager (level 80)  → "brand-admin" in DESIGN
 *   shop-manager (level 60) → "warehouse-manager" in DESIGN
 *   staff (level 30)        → operational staff (allowed view + receive)
 *   shop-staff (level 10)   → "warehouse-staff" in DESIGN
 *
 * | Action       | org-admin | org-manager | shop-manager | staff | shop-staff |
 * |--------------|-----------|-------------|--------------|-------|------------|
 * | viewAny (HQ) |     ✅     |      ✅      |       ❌      |   ❌   |     ❌      |
 * | view         |     ✅     |      ✅      |       ✅      |   ✅   |     ✅      |
 * | create       |     ✅     |      ✅      |       ✅      |   ✅   |     ❌      |
 * | split        |     ✅     |      ✅      |       ✅      |   ❌   |     ❌      |
 * | quarantine   |     ✅     |      ✅      |       ✅      |   ❌   |     ❌      |
 * | release      |     ✅     |      ✅      |       ✅      |   ❌   |     ❌      |
 * | dispose      |     ✅     |      ✅      |       ❌      |   ❌   |     ❌      |
 * | delete       |     ✅     |      ✅      |       ❌      |   ❌   |     ❌      |
 *
 * Org boundary check: every method also asserts the lot's organization_id
 * matches the request-scoped org (ResolvesOrganization trait). Without this,
 * a user with role-X in org-A could mutate lots in org-B.
 */
class MaterialLotPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        // HQ list — Shop list controllers also call viewAny but they layer
        // their own warehouse-membership filter on top.
        return $this->isHqRole($user) || $this->isShopRole($user);
    }

    public function view(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot)
            && ($this->isHqRole($user) || $this->isShopRole($user));
    }

    public function create(User $user): bool
    {
        // Receive flow — Shop scope. Org-admin / org-manager / shop-manager /
        // staff can intake. shop-staff is read-only.
        return $this->isHqRole($user) || $this->isShopManagerOrStaff($user);
    }

    public function split(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

    public function quarantine(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

    public function release(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

    /**
     * Dispose is the most destructive action — restricted to HQ roles
     * (org-admin / org-manager). shop-manager and below cannot dispose
     * lots that may contain stock.
     */
    public function dispose(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot) && $this->isHqRole($user);
    }

    public function update(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot) && $this->isHqRole($user);
    }

    public function delete(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot) && $this->isHqRole($user);
    }

    public function restore(User $user, MaterialLot $lot): bool
    {
        return $this->belongsToUserOrg($user, $lot) && $this->isHqRole($user);
    }

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    /**
     * Pull the request-resolved branch id (set by ResolveShopFromSlug
     * middleware on shop-scoped routes). When present, role checks must
     * accept role rows pinned to THAT branch in addition to org-wide
     * rows — otherwise a shop-manager assigned to one specific branch
     * hits a silent 403 because hasRoleInContext($slug, $org, null)
     * only matches `branch_id IS NULL` pivot rows.
     */
    private function resolveBranchId(): ?string
    {
        return request()?->attributes->get('shop_id');
    }

    private function isShopManager(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();
        $branchId = $this->resolveBranchId();

        return $user->hasRoleInContext('shop-manager', $organizationId, $branchId);
    }

    private function isShopManagerOrStaff(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();
        $branchId = $this->resolveBranchId();

        return $this->isShopManager($user)
            || $user->hasRoleInContext('staff', $organizationId, $branchId);
    }

    private function isShopRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();
        $branchId = $this->resolveBranchId();

        return $this->isShopManager($user)
            || $user->hasRoleInContext('staff', $organizationId, $branchId)
            || $user->hasRoleInContext('shop-staff', $organizationId, $branchId);
    }
}
