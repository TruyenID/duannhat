<?php

namespace App\Policies;

use App\Models\ProductOptionValue;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Authorization rules for ProductOptionValue — walks
 * `value → option → product → organization`.
 */
class ProductOptionValuePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, ProductOptionValue $value): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $value, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, ProductOptionValue $value): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $value, 'catalog.update');
    }

    public function delete(User $user, ProductOptionValue $value): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $value, 'catalog.delete');
    }

    private function belongsToProductOrganizationWithPermission(User $user, ProductOptionValue $value, string $permission): bool
    {
        $value->loadMissing('option.product.organization');

        return $value->option?->product !== null
            && $this->belongsToUserOrgWithPermission($user, $value->option->product, $permission);
    }
}
