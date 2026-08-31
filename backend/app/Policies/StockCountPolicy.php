<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class StockCountPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockCount $stockCount): bool
    {
        return $this->belongsToUserOrg($user, $stockCount);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StockCount $stockCount): bool
    {
        return $this->belongsToUserOrg($user, $stockCount);
    }

    public function delete(User $user, StockCount $stockCount): bool
    {
        return $this->belongsToUserOrg($user, $stockCount);
    }

    public function approve(User $user, StockCount $stockCount): bool
    {
        return $this->belongsToUserOrg($user, $stockCount);
    }
}
