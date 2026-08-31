<?php

namespace App\Policies;

use App\Models\StockAlert;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class StockAlertPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockAlert $stockAlert): bool
    {
        return $this->belongsToUserOrg($user, $stockAlert);
    }
}
