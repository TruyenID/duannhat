<?php

namespace App\Services\Omnify\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * plan-040 M9 — org-scoping extension point for generated Omnify services.
 *
 * The generated `*ServiceBase::list()` whitelists sort + paginates but applies
 * NO tenant filter, so a controller that forwards an `organization_id` filter
 * (or forgets to) can surface rows from every org. This trait overrides `list()`
 * in the EDITABLE sibling service to apply the organization scope while keeping
 * the base contract (sort whitelist, eager-load hook, pagination) intact.
 *
 * Applied to inventory Omnify services whose model carries `organization_id`.
 * Models without that column are a no-op (the column check short-circuits), so
 * the trait is safe to apply broadly.
 */
trait ScopesOmnifyListToOrganization
{
    /**
     * @param  array{organization_id?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        /** @var Builder $query */
        $query = $this->model::query();

        $this->applyListEagerLoads($query);

        // plan-040 M9 — tenant boundary. Only applied when the filter is present
        // AND the model actually has the column, so child/lookup tables without
        // organization_id behave exactly like the generated base.
        $organizationId = $filters['organization_id'] ?? null;
        if ($organizationId !== null && $this->modelHasOrganizationColumn()) {
            $query->where($query->getModel()->getTable().'.organization_id', $organizationId);
        }

        // Mirror the generated base's soft-delete handling — without this the
        // org-scoped override would silently drop with_trashed/only_trashed and
        // break the admin "view trashed / restore" flow.
        if (! empty($filters['only_trashed'])) {
            $query->onlyTrashed();
        } elseif (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        // Mirror the base sort whitelist (incl. deleted_at, used by trashed views).
        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowedSortColumns = ['created_at', 'deleted_at', 'id', 'updated_at'];
        if (! in_array($column, $allowedSortColumns, true)) {
            $column = 'created_at';
        }
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    private function modelHasOrganizationColumn(): bool
    {
        $instance = new $this->model;

        return Schema::connection($instance->getConnectionName())
            ->hasColumn($instance->getTable(), 'organization_id');
    }
}
