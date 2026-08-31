<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VoidReason;
use App\Policies\Traits\ResolvesOrganization;

/**
 * plan-051 (#1149) — brand-scoped VoidReason master data. Mirrors
 * TaxTypePolicy: HQ catalog permissions, org boundary from the request
 * context (ResolveBrandFromSlug). No delete ability on purpose — the only
 * removal path is soft-deactivation via update (is_active=false).
 */
class VoidReasonPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, VoidReason $voidReason): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $voidReason, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, VoidReason $voidReason): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $voidReason, 'catalog.update');
    }
}
