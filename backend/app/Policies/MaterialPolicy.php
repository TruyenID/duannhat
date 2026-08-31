<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class MaterialPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.view');
    }

    public function view(User $user, Material $material): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $material, 'material.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.create');
    }

    public function update(User $user, Material $material): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $material, 'material.update');
    }

    public function delete(User $user, Material $material): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $material, 'material.delete');
    }

    public function restore(User $user, Material $material): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $material, 'material.update');
    }
}
