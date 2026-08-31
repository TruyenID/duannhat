<?php

namespace App\Services\Inventory\Concerns;

use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * Defense-in-depth for #851 (cross-tenant inventory IDOR).
 *
 * The request layer already scopes every warehouse_id to the caller's org via
 * App\Http\Requests\Concerns\ScopesWarehouseToOrganization, so an HTTP attacker
 * never reaches the service with a foreign warehouse. This guard re-asserts the
 * invariant for callers that bypass the FormRequest (internal service-to-service
 * flows, jobs, console commands) right before the mutation runs.
 */
trait AssertsWarehouseOrganization
{
    /**
     * Reject if any of the supplied warehouse ids does not belong to
     * $organizationId. No-op when the org id is absent (the caller's own
     * required-field validation owns that case) or when no warehouse id is
     * supplied.
     *
     * @param  array<int, string|null>  $warehouseIds
     */
    protected function assertWarehousesBelongToOrganization(?string $organizationId, array $warehouseIds): void
    {
        if (! $organizationId) {
            return;
        }

        $ids = array_values(array_unique(array_filter($warehouseIds)));

        if ($ids === []) {
            return;
        }

        $ownedCount = Warehouse::whereIn('id', $ids)
            ->where('organization_id', $organizationId)
            ->count();

        if ($ownedCount !== count($ids)) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'One or more warehouses do not belong to your organization.',
            ]);
        }
    }
}
