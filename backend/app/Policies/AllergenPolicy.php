<?php

namespace App\Policies;

use App\Models\Allergen;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Allergens are catalog master data shared across the organization. Reads
 * and mutations require their matching catalog permission in that org.
 */
class AllergenPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, Allergen $allergen): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $allergen, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, Allergen $allergen): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $allergen, 'catalog.update');
    }

    public function delete(User $user, Allergen $allergen): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $allergen, 'catalog.delete');
    }

    public function restore(User $user, Allergen $allergen): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $allergen, 'catalog.update');
    }
}
