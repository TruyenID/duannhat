<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class CustomerPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->belongsToUserOrg($user, $customer);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->belongsToUserOrg($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->belongsToUserOrg($user, $customer);
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $this->belongsToUserOrg($user, $customer);
    }
}
