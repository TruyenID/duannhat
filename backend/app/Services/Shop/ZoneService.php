<?php

namespace App\Services\Shop;

use App\Models\Table;
use App\Models\Zone;
use App\Services\Order\Contracts\OpenOrderTableUsage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ZoneService
{
    // =========================================================================
    //  CRUD
    // =========================================================================

    /**
     * @param  array{branch_id?: string, search?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Zone::query()->withCount('tables');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
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

    public function findById(string $id): Zone
    {
        return Zone::withCount('tables')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Zone
    {
        $data['is_active'] = $data['is_active'] ?? true;
        $data['display_order'] = $data['display_order'] ?? 0;

        return Zone::create($data)->loadCount('tables');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Zone $zone, array $data): Zone
    {
        $zone->update($data);

        return $zone->loadCount('tables');
    }

    /**
     * Soft-delete the zone and cascade soft-delete all of its tables
     * inside one DB transaction (BR-Z02). Restoring the zone later does
     * NOT auto-restore the tables (BR-Z03) — that's a separate operator
     * action.
     */
    public function delete(Zone $zone): bool
    {
        // issue #890 / BR-Z04: zones copied from an HQ template — or zones
        // still containing HQ-origin tables (the cascade below would sweep
        // those tables away) — cannot be deleted by the shop.
        $holdsHqTables = Table::where('zone_id', $zone->id)
            ->whereNotNull('table_template_id')
            ->exists();

        if ($zone->zone_template_id !== null || $holdsHqTables) {
            abort(response()->json([
                'message' => 'This zone is part of the HQ default layout and cannot be deleted by the shop. Deactivate it instead.',
                'code' => 'ZONE_DELETE_BLOCKED_HQ_TEMPLATE',
            ], 409));
        }

        // plan-042: block deleting a zone when any of its tables has an open
        // order — otherwise the zone→tables cascade below would soft-delete a
        // table out from under a live order. Checked BEFORE the cascade.
        $tableIds = Table::where('zone_id', $zone->id)->pluck('id');
        if ($tableIds->isNotEmpty()) {
            // #962 (7b) — vế "đơn còn mở" hỏi qua cổng Ordering công bố; vế
            // `current_order_id` ở lại vì đó là cột chiếm dụng trên `tables`.
            $hasOpenOrder = Table::whereIn('id', $tableIds)->whereNotNull('current_order_id')->exists()
                || app(OpenOrderTableUsage::class)->anyOpenOrderUsesTables(
                    $tableIds->map(fn ($id): string => (string) $id)->values()->all(),
                );

            if ($hasOpenOrder) {
                abort(response()->json([
                    'message' => 'Cannot delete a zone that has a table with an open order. Close or move those orders first.',
                    'code' => 'ZONE_DELETE_BLOCKED_OPEN_ORDER',
                ], 409));
            }
        }

        return DB::transaction(function () use ($zone) {
            $zone->tables()->delete();

            return $zone->delete();
        });
    }

    public function restore(Zone $zone): Zone
    {
        $zone->restore();

        return $zone->loadCount('tables');
    }

    public function toggleActive(Zone $zone): Zone
    {
        $zone->update(['is_active' => ! $zone->is_active]);

        return $zone->loadCount('tables');
    }

    /**
     * @return array<int, array{id: string, code: string, name: string, display_order: int}>
     */
    public function lookup(string $branchId): array
    {
        return Zone::where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            // #3170 — neither display_order (defaults to 0) nor name is unique,
            // so the lookup needs the id to be a total order.
            ->orderBy('zones.id')
            ->get(['id', 'code', 'name', 'display_order'])
            ->toArray();
    }
}
