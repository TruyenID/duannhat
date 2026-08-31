<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class CategoryPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $category, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, Category $category): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $category, 'catalog.update');
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $category, 'catalog.delete');
    }

    public function restore(User $user, Category $category): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $category, 'catalog.update');
    }
}
