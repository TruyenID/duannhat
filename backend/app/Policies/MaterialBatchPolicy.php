<?php

namespace App\Policies;

use App\Models\MaterialBatch;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class MaterialBatchPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.view');
    }

    public function view(User $user, MaterialBatch $materialBatch): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $materialBatch, 'material.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.create');
    }

    public function update(User $user, MaterialBatch $materialBatch): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $materialBatch, 'material.update');
    }

    public function delete(User $user, MaterialBatch $materialBatch): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $materialBatch, 'material.delete');
    }

    public function approve(User $user, MaterialBatch $materialBatch): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $materialBatch, 'material.approve');
    }
}
