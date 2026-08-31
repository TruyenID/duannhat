<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\Organization;
use App\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Device $device): bool
    {
        return $this->belongsToUserOrg($user, $device);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Device $device): bool
    {
        return $this->belongsToUserOrg($user, $device);
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->belongsToUserOrg($user, $device);
    }

    public function restore(User $user, Device $device): bool
    {
        return $this->belongsToUserOrg($user, $device);
    }

    /**
     * Resolve console_organization_id → local organization.id, then compare.
     */
    private function belongsToUserOrg(User $user, Device $device): bool
    {
        $localOrgId = Organization::where('console_organization_id', $user->console_organization_id)
            ->value('id');

        return $localOrgId && $localOrgId === $device->organization_id;
    }
}
