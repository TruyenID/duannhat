<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class StockTransferPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->belongsToUserOrg($user, $stockTransfer);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->belongsToUserOrg($user, $stockTransfer);
    }

    public function delete(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->belongsToUserOrg($user, $stockTransfer);
    }

    public function approve(User $user, StockTransfer $stockTransfer): bool
    {
        return $this->belongsToUserOrg($user, $stockTransfer);
    }
}
