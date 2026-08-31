<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Zone;
use App\Policies\Traits\ChecksShopContext;

class ZonePolicy
{
    use ChecksShopContext;

    public function viewAny(User $user): bool
    {
        return $user->console_organization_id !== null;
    }

    public function view(User $user, Zone $zone): bool
    {
        return $this->belongsToOrganization($user, $zone)
            && $this->belongsToResolvedShop($zone);
    }

    public function create(User $user): bool
    {
        return $this->isShopManager($user);
    }

    public function update(User $user, Zone $zone): bool
    {
        return $this->belongsToOrganization($user, $zone)
            && $this->belongsToResolvedShop($zone)
            && $this->isShopManager($user, $zone->branch_id);
    }

    public function delete(User $user, Zone $zone): bool
    {
        return $this->update($user, $zone);
    }

    public function restore(User $user, Zone $zone): bool
    {
        return $this->update($user, $zone);
    }

    public function toggleActive(User $user, Zone $zone): bool
    {
        return $this->update($user, $zone);
    }
}
