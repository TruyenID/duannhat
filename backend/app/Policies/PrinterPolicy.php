<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Printer;
use App\Models\User;

/**
 * Tenant isolation for printer configuration.
 *
 * Mirrors DevicePolicy: a printer record carries a shop's internal LAN
 * addresses, so a user from another tenant must never read or write it.
 */
class PrinterPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Printer $printer): bool
    {
        return $this->belongsToUserOrg($user, $printer);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Printer $printer): bool
    {
        return $this->belongsToUserOrg($user, $printer);
    }

    public function delete(User $user, Printer $printer): bool
    {
        return $this->belongsToUserOrg($user, $printer);
    }

    public function restore(User $user, Printer $printer): bool
    {
        return $this->belongsToUserOrg($user, $printer);
    }

    /**
     * Resolve console_organization_id → local organization.id, then compare.
     */
    private function belongsToUserOrg(User $user, Printer $printer): bool
    {
        $localOrgId = Organization::where('console_organization_id', $user->console_organization_id)
            ->value('id');

        return $localOrgId && $localOrgId === $printer->organization_id;
    }
}
