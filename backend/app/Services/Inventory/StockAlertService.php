<?php

namespace App\Services\Inventory;

use App\Models\StockAlert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StockAlertService
{
    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, warehouse_id?: string, status?: string, alert_type?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = StockAlert::query()
            ->with(['warehouse', 'productSku', 'material']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['alert_type'] ?? null, fn ($q, $type) => $q->where('alert_type', $type));

        // Free-text search over the alerted item — matches against the related
        // ProductSku (sku or name) or the Material (sku or name). The legacy
        // Inertia page exposed this as "Search by name or SKU".
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($outer) use ($term) {
                $outer->whereHas('productSku', function ($q) use ($term) {
                    $q->where('sku', 'like', $term)->orWhere('name', 'like', $term);
                })->orWhereHas('material', function ($q) use ($term) {
                    $q->where('sku', 'like', $term)->orWhere('name', 'like', $term);
                });
            });
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        // plan-040 TF.4 (L10): whitelist sortable columns. An unknown `?sort=`
        // column would otherwise reach the DB and 500 on an invalid identifier.
        $sortable = ['created_at', 'resolved_at', 'current_quantity', 'min_stock', 'alert_type', 'status'];
        if (! in_array($column, $sortable, true)) {
            $column = 'created_at';
            $direction = 'desc';
        }

        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    // =========================================================================
    //  Summary
    // =========================================================================

    /**
     * Aggregate counts used by the dashboard summary cards.
     *
     * @return array{total_active: int, low_stock_count: int, out_of_stock_count: int, resolved_count: int}
     */
    public function summary(string $organizationId): array
    {
        $rows = StockAlert::where('organization_id', $organizationId)
            ->selectRaw('status, alert_type, count(*) as total')
            ->groupBy('status', 'alert_type')
            ->get();

        $totalActive = 0;
        $lowStock = 0;
        $outOfStock = 0;
        $resolved = 0;

        foreach ($rows as $row) {
            $count = (int) $row->total;
            // Status + alert_type are enum-cast on the model. Compare via
            // ->value (or fall back to the raw string when no cast hit).
            $status = is_object($row->status) ? $row->status->value : $row->status;
            $alertType = is_object($row->alert_type) ? $row->alert_type->value : $row->alert_type;

            if ($status === 'active') {
                $totalActive += $count;
                if ($alertType === 'low_stock') {
                    $lowStock += $count;
                } elseif ($alertType === 'out_of_stock') {
                    $outOfStock += $count;
                }
            } elseif ($status === 'resolved') {
                $resolved += $count;
            }
        }

        return [
            // Short keys consumed by the StockAlertSummaryCard / tests.
            'active' => $totalActive,
            'resolved' => $resolved,
            // Verbose keys preserved for any existing dashboard binding.
            'total_active' => $totalActive,
            'low_stock_count' => $lowStock,
            'out_of_stock_count' => $outOfStock,
            'resolved_count' => $resolved,
        ];
    }
}
