<?php

namespace App\Policies;

use App\Models\ProductSku;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Authorization rules for ProductSku.
 *
 * Walks the relation chain `sku → product → organization` and matches against
 * the user's `console_organization_id` shadow column.
 */
class ProductSkuPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, ProductSku $sku): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $sku, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, ProductSku $sku): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $sku, 'catalog.update');
    }

    public function delete(User $user, ProductSku $sku): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $sku, 'catalog.delete');
    }

    public function restore(User $user, ProductSku $sku): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $sku, 'catalog.update');
    }

    public function toggleStatus(User $user, ProductSku $sku): bool
    {
        return $this->belongsToProductOrganizationWithPermission($user, $sku, 'catalog.update');
    }

    private function belongsToProductOrganizationWithPermission(User $user, ProductSku $sku, string $permission): bool
    {
        $sku->loadMissing('product.organization');

        return $sku->product !== null
            && $this->belongsToUserOrgWithPermission($user, $sku->product, $permission);
    }
}
