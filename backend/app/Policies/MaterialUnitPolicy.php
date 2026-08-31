<?php

namespace App\Policies;

use App\Models\MaterialUnit;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * MaterialUnitPolicy — HQ-only management surface.
 *
 * Per DESIGN.md authorization matrix: org-admin / org-manager only.
 * shop-manager and below have read-only access (Units tab is visible
 * but mutations are blocked).
 */
class MaterialUnitPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->isAnyOrgUser($user);
    }

    public function view(User $user, MaterialUnit $unit): bool
    {
        return $this->belongsToOrgViaMaterial($unit) && $this->isAnyOrgUser($user);
    }

    public function create(User $user): bool
    {
        return $this->isHqRole($user);
    }

    public function update(User $user, MaterialUnit $unit): bool
    {
        return $this->belongsToOrgViaMaterial($unit) && $this->isHqRole($user);
    }

    public function delete(User $user, MaterialUnit $unit): bool
    {
        return $this->belongsToOrgViaMaterial($unit) && $this->isHqRole($user);
    }

    /**
     * MaterialUnit doesn't carry organization_id directly — traverse via the
     * parent Material to compare against the request-scoped org.
     */
    private function belongsToOrgViaMaterial(MaterialUnit $unit): bool
    {
        $organizationId = $this->resolveLocalOrgId();
        if ($organizationId === null) {
            return false;
        }

        $material = $unit->relationLoaded('material') ? $unit->material : $unit->material()->first();

        return $material !== null && (string) $organizationId === (string) $material->organization_id;
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
