<?php

namespace App\Policies;

use App\Models\ProductType;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class ProductTypePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, ProductType $productType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $productType, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, ProductType $productType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $productType, 'catalog.update');
    }

    public function delete(User $user, ProductType $productType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $productType, 'catalog.delete');
    }

    public function restore(User $user, ProductType $productType): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $productType, 'catalog.update');
    }
}
