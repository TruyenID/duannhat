<?php

namespace App\Services\Shop;

use App\Models\TableTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * TableTemplateService — brand-scoped table templates (issue #890).
 * Mirrors App\Services\Shop\TableService minus all runtime concerns
 * (status, QR, orders) — templates only carry layout data.
 */
class TableTemplateService
{
    // =========================================================================
    //  CRUD
    // =========================================================================

    /**
     * @param  array{organization_id?: string, brand_id?: string, zone_template_id?: string, is_active?: bool, search?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = TableTemplate::query()->with(['zoneTemplate:id,code,name', 'branch:id,name']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        $query->when($filters['zone_template_id'] ?? null, fn ($q, $id) => $q->where('zone_template_id', $id));
        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? 'code';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): TableTemplate
    {
        return TableTemplate::with(['zoneTemplate:id,code,name', 'branch:id,name'])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TableTemplate
    {
        $data['is_active'] = $data['is_active'] ?? true;
        $data['seat_count'] = $data['seat_count'] ?? 2;

        return TableTemplate::create($data)->load(['zoneTemplate:id,code,name', 'branch:id,name']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TableTemplate $tableTemplate, array $data): TableTemplate
    {
        $tableTemplate->update($data);

        return $tableTemplate->load(['zoneTemplate:id,code,name', 'branch:id,name']);
    }

    public function delete(TableTemplate $tableTemplate): bool
    {
        return (bool) $tableTemplate->delete();
    }

    public function restore(TableTemplate $tableTemplate): TableTemplate
    {
        $tableTemplate->restore();

        return $tableTemplate->load(['zoneTemplate:id,code,name', 'branch:id,name']);
    }

    public function toggleActive(TableTemplate $tableTemplate): TableTemplate
    {
        $tableTemplate->update(['is_active' => ! $tableTemplate->is_active]);

        return $tableTemplate->load(['zoneTemplate:id,code,name', 'branch:id,name']);
    }
}
