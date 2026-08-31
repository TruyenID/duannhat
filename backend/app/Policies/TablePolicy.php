<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;
use App\Policies\Traits\ChecksShopContext;

class TablePolicy
{
    use ChecksShopContext;

    public function viewAny(User $user): bool
    {
        return $user->console_organization_id !== null;
    }

    public function view(User $user, Table $table): bool
    {
        return $this->belongsToOrganization($user, $table)
            && $this->belongsToResolvedShop($table);
    }

    public function create(User $user): bool
    {
        // For shop-scoped create (no model instance to inspect yet) we still
        // need to honour branch-scoped role assignments. Pull the resolved
        // shop id from the request — same source ResolveShopFromSlug
        // middleware sets — so a shop-manager assigned to ONLY branch X can
        // create tables under that branch's shop slug. Without this, the
        // trait's null branchId path only matches org-wide role rows and a
        // branch-scoped manager hits a silent 403.
        $branchId = request()?->attributes->get('shop_id');

        return $this->isShopManager($user, $branchId);
    }

    public function update(User $user, Table $table): bool
    {
        return $this->belongsToOrganization($user, $table)
            && $this->belongsToResolvedShop($table)
            && $this->isShopManager($user, $table->branch_id);
    }

    public function delete(User $user, Table $table): bool
    {
        return $this->update($user, $table);
    }

    public function restore(User $user, Table $table): bool
    {
        return $this->update($user, $table);
    }

    public function toggleActive(User $user, Table $table): bool
    {
        return $this->update($user, $table);
    }

    /**
     * Runtime status change is allowed for any authenticated user with shop access,
     * including Shop Staff. This is the only mutation Staff can perform.
     */
    public function changeStatus(User $user, Table $table): bool
    {
        return $this->belongsToOrganization($user, $table)
            && $this->belongsToResolvedShop($table)
            && $this->isShopUser($user, $table->branch_id);
    }

    public function regenerateQr(User $user, Table $table): bool
    {
        return $this->update($user, $table);
    }
}
