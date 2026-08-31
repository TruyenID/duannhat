<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Authorization rules for Product.
 *
 * Every ability checks both the organization resolved from the HQ URL and
 * the matching catalog permission. This keeps tenant isolation and mutable
 * IAM grants authoritative at the policy boundary.
 */
class ProductPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.delete');
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.update');
    }

    public function submitForApproval(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.update');
    }

    public function approve(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.approve');
    }

    public function reject(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.approve');
    }

    public function activate(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.update');
    }

    public function deactivate(User $user, Product $product): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $product, 'catalog.update');
    }
}
