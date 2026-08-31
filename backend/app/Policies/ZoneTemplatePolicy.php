<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ZoneTemplate;
use App\Policies\Traits\ResolvesOrganization;

class ZoneTemplatePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ZoneTemplate $zoneTemplate): bool
    {
        return $this->belongsToUserOrg($user, $zoneTemplate);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ZoneTemplate $zoneTemplate): bool
    {
        return $this->belongsToUserOrg($user, $zoneTemplate);
    }

    public function delete(User $user, ZoneTemplate $zoneTemplate): bool
    {
        return $this->belongsToUserOrg($user, $zoneTemplate);
    }

    public function restore(User $user, ZoneTemplate $zoneTemplate): bool
    {
        return $this->belongsToUserOrg($user, $zoneTemplate);
    }

    public function toggleActive(User $user, ZoneTemplate $zoneTemplate): bool
    {
        return $this->belongsToUserOrg($user, $zoneTemplate);
    }
}
