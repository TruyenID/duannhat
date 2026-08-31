<?php

namespace App\Policies;

use App\Models\MaterialSubstitutionRule;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * MaterialSubstitutionRulePolicy — org-scoped management surface.
 *
 * Mirrors MaterialPolicy: reads and mutations require the corresponding
 * material permission and the rule must belong to the resolved organization.
 */
class MaterialSubstitutionRulePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.view');
    }

    public function view(User $user, MaterialSubstitutionRule $rule): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $rule, 'material.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.create');
    }

    public function update(User $user, MaterialSubstitutionRule $rule): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $rule, 'material.update');
    }

    public function delete(User $user, MaterialSubstitutionRule $rule): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $rule, 'material.delete');
    }
}
