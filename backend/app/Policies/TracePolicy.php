<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * TracePolicy — HQ-only investigation surface (org-admin / org-manager).
 *
 * Trace queries hit recursive joins on hot supplier lots that can fan out
 * to thousands of children — only HQ roles get access. Sidebar entry is
 * also hidden for non-HQ roles in admin-web.
 */
class TracePolicy
{
    use ResolvesOrganization;

    public function view(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }
}
