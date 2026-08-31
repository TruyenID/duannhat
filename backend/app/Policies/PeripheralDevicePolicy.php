<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\User;

class PeripheralDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PeripheralDevice $peripheralDevice): bool
    {
        return $this->belongsToUserOrg($user, $peripheralDevice);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PeripheralDevice $peripheralDevice): bool
    {
        return $this->belongsToUserOrg($user, $peripheralDevice);
    }

    public function delete(User $user, PeripheralDevice $peripheralDevice): bool
    {
        return $this->belongsToUserOrg($user, $peripheralDevice);
    }

    public function restore(User $user, PeripheralDevice $peripheralDevice): bool
    {
        return $this->belongsToUserOrg($user, $peripheralDevice);
    }

    /**
     * Resolve console_organization_id → local organization.id, then compare.
     */
    private function belongsToUserOrg(User $user, PeripheralDevice $peripheralDevice): bool
    {
        $localOrgId = Organization::where('console_organization_id', $user->console_organization_id)
            ->value('id');

        return $localOrgId && $localOrgId === $peripheralDevice->organization_id;
    }
}
