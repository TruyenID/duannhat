<?php

namespace App\Services\Inventory;

use App\Models\StockAlert;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StockLevelService
{
    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{warehouse_id?: string, organization_id?: string, search?: string, alert_enabled?: bool, stock_status?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = StockLevel::query()
            ->with([
                'warehouse',
                'productSku',
                'material',
                // Plan-017 MOD-4 — surface lot identity + expiry on the
                // stock_levels list so the FE can group rows by material
                // and render expiry chips next to each lot bucket.
                'materialLot:id,lot_code,expiry_date,status',
            ]);

        $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));

        if (! empty($filters['organization_id'])) {
            $query->whereHas('warehouse', fn ($q) => $q->where('organization_id', $filters['organization_id']));
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->whereHas('productSku', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('material', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        });

        $query->when(isset($filters['alert_enabled']), fn ($q) => $q->where('alert_enabled', $filters['alert_enabled']));

        // Server-side `stock_status` filter — `out` (qty <= 0), `low`
        // (qty > 0 and qty <= min_stock), `normal` (everything else). Without
        // this the client can only filter the current page after the fact,
        // which is wrong as soon as you have more than one page of data.
        if (! empty($filters['stock_status'])) {
            $status = $filters['stock_status'];
            $query->where(function ($q) use ($status) {
                if ($status === 'out') {
                    $q->where('quantity', '<=', 0);
                } elseif ($status === 'low') {
                    $q->where('quantity', '>', 0)
                        ->whereNotNull('min_stock')
                        ->whereColumn('quantity', '<=', 'min_stock');
                } elseif ($status === 'normal') {
                    $q->where('quantity', '>', 0)
                        ->where(function ($q) {
                            $q->whereNull('min_stock')
                                ->orWhereColumn('quantity', '>', 'min_stock');
                        });
                }
            });
        }

        $sort = $filters['sort'] ?? '-updated_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): StockLevel
    {
        return StockLevel::with(['warehouse', 'productSku', 'material'])->findOrFail($id);
    }

    /**
     * plan-040 TA.6 (M14) — resolve the single canonical lot-less StockLevel
     * row for a (warehouse, sku|material) bucket under a row lock,
     * select-or-create.
     *
     * MySQL treats NULLs as distinct in the unique index, so a plain create on
     * the lot-less (`material_lot_id IS NULL`) bucket can fragment a
     * (warehouse, material) total across several NULL rows. Routing every
     * lot-less write through this single select-or-create keeps the total on
     * one canonical row.
     */
    public function canonicalLotLessLevel(string $warehouseId, ?string $productSkuId, ?string $materialId, ?string $unit): StockLevel
    {
        $query = StockLevel::where('warehouse_id', $warehouseId)
            ->whereNull('material_lot_id');

        if ($productSkuId !== null) {
            $query->where('product_sku_id', $productSkuId);
        } else {
            $query->where('material_id', $materialId);
        }

        $level = $query->lockForUpdate()->first();

        if ($level !== null) {
            return $level;
        }

        $level = StockLevel::create([
            'warehouse_id' => $warehouseId,
            'product_sku_id' => $productSkuId,
            'material_id' => $materialId,
            'material_lot_id' => null,
            'quantity' => 0,
            'unit' => $unit,
            // plan-040 TF.7 (NEW-STK-7): default alerts ON so a later min_stock
            // edit can fire low-stock alerts on the canonical row.
            'alert_enabled' => true,
        ]);

        return StockLevel::whereKey($level->id)->lockForUpdate()->first() ?? $level;
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(StockLevel $stockLevel, array $data): StockLevel
    {
        $allowed = ['min_stock', 'max_stock', 'alert_enabled'];
        $stockLevel->update(array_intersect_key($data, array_flip($allowed)));

        // Plan-024 — when a threshold is edited from the alerts page, the
        // user expects the affected active alert to auto-resolve (or a new
        // one to fire) without having to wait for the next stock movement.
        $this->reEvaluateActiveAlertForLevel($stockLevel->fresh());

        return $stockLevel->fresh(['warehouse', 'productSku', 'material']);
    }

    /**
     * Plan-024 — recompute active alert state for this level against the
     * (possibly just-updated) threshold + alert_enabled config.
     *
     * Three transitions:
     *   - quantity >= min_stock AND active alert exists → resolve it.
     *   - quantity < min_stock AND alert_enabled AND no active alert → fire one.
     *     StockAlertNotificationObserver (plan-023 M1) handles the
     *     dispatch side-effect on `created` — no explicit call needed here.
     *   - otherwise → no-op.
     *
     * Helper is `public` so the alerts page UI mutation path (and tests)
     * can drive it directly; in normal use `update()` calls it.
     */
    public function reEvaluateActiveAlertForLevel(StockLevel $stockLevel): void
    {
        $alertQuery = StockAlert::where('warehouse_id', $stockLevel->warehouse_id)
            ->where('status', 'active');

        if ($stockLevel->product_sku_id) {
            $alertQuery->where('product_sku_id', $stockLevel->product_sku_id);
        } else {
            $alertQuery->where('material_id', $stockLevel->material_id);
        }

        $existingAlert = $alertQuery->first();
        // plan-040 TF.2 (H15/M3): compare against the MATERIAL/SKU TOTAL on-hand
        // across every lot row, not this single row's quantity — so an
        // unrelated lot of the same material can't resolve a still-low alert.
        $totalQuery = StockLevel::where('warehouse_id', $stockLevel->warehouse_id);
        if ($stockLevel->product_sku_id) {
            $totalQuery->where('product_sku_id', $stockLevel->product_sku_id);
        } else {
            $totalQuery->where('material_id', $stockLevel->material_id);
        }
        $quantity = (float) $totalQuery->sum('quantity');
        $minStock = $stockLevel->min_stock !== null
            ? (float) $stockLevel->min_stock
            : null;

        // Resolve when quantity has recovered above the (possibly relaxed)
        // threshold, OR when alerts are turned off entirely, OR when min_stock
        // is cleared. plan-040 TF.3 (L9): boundary unified to `>` (resolve) /
        // `<=` (fire) so it agrees with the `stock_status` display filter.
        if ($existingAlert !== null) {
            $shouldResolve = ! $stockLevel->alert_enabled
                || $minStock === null
                || $quantity > $minStock;

            if ($shouldResolve) {
                $existingAlert->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                ]);

                return;
            }
        }

        // Fire a fresh alert when the new threshold reveals a level that
        // should have been alerted. Skip if alerts are disabled or the
        // threshold is not configured.
        if ($existingAlert === null
            && $stockLevel->alert_enabled
            && $minStock !== null
            && $quantity <= $minStock
        ) {
            $organizationId = $stockLevel->warehouse?->organization_id
                ?? Warehouse::whereKey($stockLevel->warehouse_id)->value('organization_id');

            StockAlert::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $stockLevel->warehouse_id,
                'product_sku_id' => $stockLevel->product_sku_id,
                'material_id' => $stockLevel->material_id,
                'alert_type' => $quantity <= 0 ? 'out_of_stock' : 'low_stock',
                'current_quantity' => $quantity,
                'min_stock' => $minStock,
                'unit' => $stockLevel->unit,
                'status' => 'active',
                'created_at' => now(),
            ]);
        }
    }

    // =========================================================================
    //  Movements
    // =========================================================================

    /**
     * @param  array{sort?: string, per_page?: int}  $filters
     */
    public function getMovements(StockLevel $stockLevel, array $filters = []): LengthAwarePaginator
    {
        $query = StockMovement::where('warehouse_id', $stockLevel->warehouse_id);

        if ($stockLevel->product_sku_id) {
            $query->where('product_sku_id', $stockLevel->product_sku_id);
        } else {
            $query->where('material_id', $stockLevel->material_id);
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }
}
