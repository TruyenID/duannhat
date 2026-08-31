<?php

namespace App\Policies;

use App\Models\ProductOption;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Authorization rules for ProductOption — walks `option → product → organization`.
 */
class ProductOptionPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, ProductOption $option): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $option, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, ProductOption $option): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $option, 'catalog.update');
    }

    public function delete(User $user, ProductOption $option): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $option, 'catalog.delete');
    }

    private function belongsToProductOrganizationWithPermission(User $user, ProductOption $option, string $permission): bool
    {
        $option->loadMissing('product.organization');

        return $option->product !== null
            && $this->belongsToUserOrgWithPermission($user, $option->product, $permission);
    }
}
