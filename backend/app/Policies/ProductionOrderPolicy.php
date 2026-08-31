<?php

namespace App\Policies;

use App\Models\ProductionOrder;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class ProductionOrderPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductionOrder $productionOrder): bool
    {
        return $this->belongsToUserOrg($user, $productionOrder);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProductionOrder $productionOrder): bool
    {
        return $this->belongsToUserOrg($user, $productionOrder);
    }

    public function delete(User $user, ProductionOrder $productionOrder): bool
    {
        return $this->belongsToUserOrg($user, $productionOrder);
    }

    public function approve(User $user, ProductionOrder $productionOrder): bool
    {
        return $this->belongsToUserOrg($user, $productionOrder);
    }
}
