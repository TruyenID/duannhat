<?php

namespace App\Policies;

use App\Models\StockTransaction;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class StockTransactionPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockTransaction $stockTransaction): bool
    {
        return $this->belongsToUserOrg($user, $stockTransaction);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StockTransaction $stockTransaction): bool
    {
        return $this->belongsToUserOrg($user, $stockTransaction);
    }

    public function delete(User $user, StockTransaction $stockTransaction): bool
    {
        return $this->belongsToUserOrg($user, $stockTransaction);
    }

    public function approve(User $user, StockTransaction $stockTransaction): bool
    {
        return $this->belongsToUserOrg($user, $stockTransaction);
    }
}
