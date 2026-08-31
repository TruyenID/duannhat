<?php

namespace App\Policies;

use App\Models\TaxType;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class TaxTypePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, TaxType $taxType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $taxType, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, TaxType $taxType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $taxType, 'catalog.update');
    }

    public function delete(User $user, TaxType $taxType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $taxType, 'catalog.delete');
    }

    public function restore(User $user, TaxType $taxType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $taxType, 'catalog.update');
    }
}
