<?php

namespace App\Policies;

use App\Models\StockLevel;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class StockLevelPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockLevel $stockLevel): bool
    {
        $stockLevel->loadMissing('warehouse');

        return $stockLevel->warehouse !== null
            && $this->belongsToUserOrg($user, $stockLevel->warehouse);
    }

    /**
     * Plan-024 authorization matrix — "Update StockLevel threshold":
     * org-admin ✅, shop-manager ✅ (own warehouse), shop-staff ❌, hq-admin ❌.
     * Configuring a min/max threshold (which fires or resolves stock alerts)
     * is a manager-level action; staff are read-only. Org boundary is still
     * enforced first via belongsToUserOrg. See DESIGN.md §"Authorization matrix".
     */
    public function update(User $user, StockLevel $stockLevel): bool
    {
        $stockLevel->loadMissing('warehouse');

        if ($stockLevel->warehouse === null
            || ! $this->belongsToUserOrg($user, $stockLevel->warehouse)) {
            return false;
        }

        return $this->isManager($user);
    }

    /**
     * Manager gate for threshold configuration — org-admin / org-manager
     * (org-wide) or shop-manager pinned to the request-resolved branch.
     * Mirrors {@see MaterialLotPolicy} so a branch-scoped
     * shop-manager isn't silently 403'd on their own warehouse.
     */
    private function isManager(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();
        $branchId = request()?->attributes->get('shop_id');

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId)
            || $user->hasRoleInContext('shop-manager', $organizationId, $branchId);
    }
}
