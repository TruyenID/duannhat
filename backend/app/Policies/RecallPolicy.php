<?php

namespace App\Policies;

use App\Models\Recall;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * RecallPolicy — HQ-only.
 *
 * Per DESIGN.md authorization matrix: only org-admin and org-manager
 * (the codebase's "brand-admin" equivalent) can initiate / cancel.
 * Recalls are visible to anyone in the org.
 */
class RecallPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->isOrgUser($user);
    }

    public function view(User $user, Recall $recall): bool
    {
        return $this->belongsToUserOrg($user, $recall) && $this->isOrgUser($user);
    }

    public function create(User $user): bool
    {
        return $this->isHqRole($user);
    }

    public function complete(User $user, Recall $recall): bool
    {
        return $this->belongsToUserOrg($user, $recall) && $this->isHqRole($user);
    }

    public function cancel(User $user, Recall $recall): bool
    {
        return $this->belongsToUserOrg($user, $recall) && $this->isHqRole($user);
    }

    public function notify(User $user, Recall $recall): bool
    {
        return $this->belongsToUserOrg($user, $recall) && $this->isHqRole($user);
    }

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    private function isOrgUser(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $this->isHqRole($user)
            || $user->hasRoleInContext('shop-manager', $organizationId)
            || $user->hasRoleInContext('staff', $organizationId)
            || $user->hasRoleInContext('shop-staff', $organizationId);
    }
}
