<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use App\Policies\Traits\ResolvesOrganization;

class WarehousePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToUserOrg($user, $warehouse);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToUserOrg($user, $warehouse);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToUserOrg($user, $warehouse);
    }

    public function restore(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToUserOrg($user, $warehouse);
    }

    public function toggleActive(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToUserOrg($user, $warehouse);
    }

    /**
     * Plan-024 authorization matrix — "Toggle Warehouse.allow_negative_sales":
     * org-admin ✅, everyone else ❌. updateSettings carries the
     * allow_negative_sales flag (a policy switch that lets sales drive stock
     * negative), so it must be restricted to org admins — not any org member.
     * See plans/plan-024/DESIGN.md §"Policy ↔ UI gate cross-check".
     */
    public function updateSettings(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToUserOrg($user, $warehouse)
            && $user->hasRoleInContext('org-admin', $this->resolveLocalOrgId());
    }

    public function manageMembers(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToUserOrg($user, $warehouse);
    }
}
