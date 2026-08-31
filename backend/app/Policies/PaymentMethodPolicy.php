<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class PaymentMethodPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PaymentMethod $method): bool
    {
        return $this->belongsToUserOrg($user, $method);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PaymentMethod $method): bool
    {
        return $this->belongsToUserOrg($user, $method);
    }

    public function delete(User $user, PaymentMethod $method): bool
    {
        return $this->belongsToUserOrg($user, $method);
    }

    public function restore(User $user, PaymentMethod $method): bool
    {
        return $this->belongsToUserOrg($user, $method);
    }
}
