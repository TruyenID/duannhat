<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VariantUnit;
use App\Policies\Traits\ResolvesOrganization;

class VariantUnitPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, VariantUnit $unit): bool
    {
        $unit->loadMissing('productSku.product');

        return $this->belongsToUserOrgWithPermission($user, $unit->productSku->product, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, VariantUnit $unit): bool
    {
        $unit->loadMissing('productSku.product');

        return $this->belongsToUserOrgWithPermission($user, $unit->productSku->product, 'catalog.update');
    }

    public function delete(User $user, VariantUnit $unit): bool
    {
        $unit->loadMissing('productSku.product');

        return $this->belongsToUserOrgWithPermission($user, $unit->productSku->product, 'catalog.delete');
    }

    public function restore(User $user, VariantUnit $unit): bool
    {
        $unit->loadMissing('productSku.product');

        return $this->belongsToUserOrgWithPermission($user, $unit->productSku->product, 'catalog.update');
    }
}
