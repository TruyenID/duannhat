<?php

namespace App\Services\Shop;

use App\Models\ZoneTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ZoneTemplateService — brand-scoped zone templates (issue #890).
 * Mirrors App\Services\Shop\ZoneService but for HQ default-layout templates.
 */
class ZoneTemplateService
{
    // =========================================================================
    //  CRUD
    // =========================================================================

    /**
     * @param  array{organization_id?: string, brand_id?: string, search?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ZoneTemplate::query()->with('branch:id,name')->withCount('tableTemplates');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        });

        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? 'display_order';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): ZoneTemplate
    {
        return ZoneTemplate::with('branch:id,name')->withCount('tableTemplates')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ZoneTemplate
    {
        $data['is_active'] = $data['is_active'] ?? true;
        $data['display_order'] = $data['display_order'] ?? 0;

        return ZoneTemplate::create($data)->load('branch:id,name')->loadCount('tableTemplates');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ZoneTemplate $zoneTemplate, array $data): ZoneTemplate
    {
        $zoneTemplate->update($data);

        return $zoneTemplate->load('branch:id,name')->loadCount('tableTemplates');
    }

    /**
     * Soft-delete the zone template and cascade soft-delete all of its table
     * templates inside one DB transaction (BR-ZT02). Restoring the zone
     * template later does NOT auto-restore the table templates (BR-ZT03).
     */
    public function delete(ZoneTemplate $zoneTemplate): bool
    {
        return DB::transaction(function () use ($zoneTemplate) {
            $zoneTemplate->tableTemplates()->delete();

            return $zoneTemplate->delete();
        });
    }

    public function restore(ZoneTemplate $zoneTemplate): ZoneTemplate
    {
        $zoneTemplate->restore();

        return $zoneTemplate->loadCount('tableTemplates');
    }

    public function toggleActive(ZoneTemplate $zoneTemplate): ZoneTemplate
    {
        $zoneTemplate->update(['is_active' => ! $zoneTemplate->is_active]);

        return $zoneTemplate->loadCount('tableTemplates');
    }

    /**
     * @return array<int, array{id: string, code: string, name: string, display_order: int}>
     */
    public function lookup(string $organizationId, string $brandId): array
    {
        return ZoneTemplate::where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            // #3170 — display_order defaults to 0 and name is not unique either.
            ->orderBy('zone_templates.id')
            ->get(['id', 'code', 'name', 'display_order'])
            ->toArray();
    }
}
