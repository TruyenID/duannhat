<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * #851 — scope inventory warehouse_id validation to the caller's organization.
 *
 * Inventory write requests previously validated warehouse ids with an unscoped
 * `exists:warehouses,id`, letting an org-A caller target an org-B warehouse
 * (cross-tenant IDOR). ResolveShopFromSlug / ResolvesShopContext stamp
 * `organization_id` (the LOCAL organizations.id) onto the request, and
 * warehouses.organization_id is a non-nullable FK to the same table, so we
 * constrain the `exists` lookup to that org.
 *
 * Fail-closed: when no org context is present the attribute is null, which
 * Laravel compiles to `WHERE organization_id IS NULL`. Since
 * warehouses.organization_id is never null, that matches no rows and the rule
 * rejects every warehouse id.
 */
trait ScopesWarehouseToOrganization
{
    protected function warehouseExistsRule(): Exists
    {
        return Rule::exists('warehouses', 'id')
            ->where('organization_id', $this->attributes->get('organization_id'));
    }
}
