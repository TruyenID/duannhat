<?php

namespace App\Policies;

use App\Models\ToppingGroup;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class ToppingGroupPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, ToppingGroup $toppingGroup): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $toppingGroup, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, ToppingGroup $toppingGroup): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $toppingGroup, 'catalog.update');
    }

    public function delete(User $user, ToppingGroup $toppingGroup): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $toppingGroup, 'catalog.delete');
    }

    public function restore(User $user, ToppingGroup $toppingGroup): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $toppingGroup, 'catalog.update');
    }
}
