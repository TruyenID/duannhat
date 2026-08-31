<?php

namespace App\Services\Inventory;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\MaterialUnit;
use App\Models\StockCount;
use App\Models\StockLevel;
use App\Omnify\Enums\StockCountScopeEnum;
use App\Omnify\Enums\StockCountStatusEnum;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Services\Inventory\Concerns\AssertsWarehouseOrganization;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockCountService
{
    use AssertsWarehouseOrganization;

    public function __construct(
        private readonly StockTransactionService $transactionService,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, warehouse_id?: string, status?: string, scope?: string, date_from?: string, date_to?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = StockCount::query()
            ->with(['warehouse'])
            ->withCount('items');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['scope'] ?? null, fn ($q, $scope) => $q->where('scope', $scope));

        // #1091 — filter dates are BRANCH-local; convert to UTC instant
        // bounds instead of whereDate (which compares in the DB's UTC day).
        [$dateFrom, $dateUntil] = BusinessClock::utcRangeForBusinessDates(
            $filters['branch_id'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );
        $query->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom));
        $query->when($dateUntil, fn ($q) => $q->where('created_at', '<', $dateUntil));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where('count_code', 'like', "%{$search}%");
        });

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): StockCount
    {
        return StockCount::with([
            'warehouse',
            'items.productSku',
            'items.material',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): StockCount
    {
        $this->assertWarehousesBelongToOrganization(
            $data['organization_id'] ?? null,
            [$data['warehouse_id'] ?? null],
        );

        return DB::transaction(function () use ($data) {
            $data['count_code'] = $this->generateCode($data['organization_id']);
            $data['status'] = StockCountStatusEnum::Draft->value;

            $count = StockCount::create($data);

            // Full-scope counts snapshot every StockLevel row in the warehouse
            // (partial-scope counts add items lazily via addItems()). The UI
            // label "chụp toàn bộ khi tạo" promises this behaviour.
            if (($count->scope?->value ?? $count->scope) === StockCountScopeEnum::Full->value) {
                $this->snapshotFullScope($count);
            }

            return $this->loadRelations($count);
        });
    }

    /**
     * Snapshot every StockLevel row in the warehouse into StockCountItem rows
     * with system_quantity captured at create time. Skips rows with quantity=0
     * because a zero balance carries no meaningful information for kiểm kê.
     *
     * plan-040 NEW-STK-4: a material spread across N lots has N per-lot
     * StockLevel rows (each carrying `material_lot_id`) plus an optional legacy
     * NULL-lot bucket. Aggregate them into ONE count item per material so the
     * operator counts the material once against its total on-hand — not once
     * per lot. Product-SKU rows (lot-less by design) still snapshot per SKU.
     */
    private function snapshotFullScope(StockCount $count): void
    {
        $levels = StockLevel::where('warehouse_id', $count->warehouse_id)
            ->get();

        // Aggregate per material (sum across all lot rows). The first non-null
        // unit seen for the material is used as the count item's unit.
        $materialTotals = [];
        foreach ($levels as $level) {
            if ($level->material_id === null) {
                continue;
            }
            $materialTotals[$level->material_id] ??= ['quantity' => 0.0, 'unit' => null];
            $materialTotals[$level->material_id]['quantity'] += (float) $level->quantity;
            $materialTotals[$level->material_id]['unit'] ??= $level->unit;
        }

        foreach ($materialTotals as $materialId => $agg) {
            if ($agg['quantity'] <= 0) {
                continue;
            }
            $count->items()->create([
                'material_id' => $materialId,
                'unit' => $agg['unit'],
                'system_quantity' => $agg['quantity'],
            ]);
        }

        // Product-SKU rows are not lot-partitioned; snapshot each non-zero row.
        foreach ($levels as $level) {
            if ($level->product_sku_id === null || (float) $level->quantity <= 0) {
                continue;
            }
            $count->items()->create([
                'product_sku_id' => $level->product_sku_id,
                'unit' => $level->unit,
                'system_quantity' => (float) $level->quantity,
            ]);
        }
    }

    /**
     * plan-040 NEW-STK-3: aggregate the current on-hand for a count item's
     * subject (material across ALL its lot StockLevel rows, or a single SKU
     * row) in the count's warehouse. This is the absolute physical baseline a
     * count reconciles against — never one arbitrary lot row.
     */
    private function aggregateSystemQuantity(StockCount $count, ?string $productSkuId, ?string $materialId, bool $forUpdate = false): float
    {
        $query = StockLevel::where('warehouse_id', $count->warehouse_id);

        if ($productSkuId !== null) {
            $query->where('product_sku_id', $productSkuId);
        } else {
            $query->where('material_id', $materialId);
        }

        // plan-040 W7: when approving, lock the subject's StockLevel rows so a
        // concurrent sale serializes against the count approval. `SELECT SUM(..)
        // ... FOR UPDATE` holds row locks on the matched StockLevel rows for the
        // life of the approval transaction, so the posted difference stays
        // consistent with the counted absolute (no sale slips in between this read
        // and the adjustment's completeTransaction).
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return (float) $query->sum('quantity');
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(StockCount $count, array $data): StockCount
    {
        $this->assertStatus($count, [StockCountStatusEnum::Draft], 'update');

        $count->update($data);

        return $this->loadRelations($count);
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    public function delete(StockCount $count): bool
    {
        $this->assertStatus($count, [
            StockCountStatusEnum::Draft,
            StockCountStatusEnum::Cancelled,
        ], 'delete');

        return DB::transaction(function () use ($count) {
            $count->items()->delete();

            return $count->delete();
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    /**
     * Add items with system_quantity snapshot from current stock levels.
     *
     * @param  array<int, array{product_sku_id?: string, material_id?: string, unit?: string}>  $items
     */
    public function addItems(StockCount $count, array $items): StockCount
    {
        $this->assertStatus($count, [
            StockCountStatusEnum::Draft,
            StockCountStatusEnum::InProgress,
        ], 'add items to');

        DB::transaction(function () use ($count, $items) {
            foreach ($items as $itemData) {
                // plan-040 NEW-STK-3: snapshot system_quantity as the SUM of all
                // StockLevel rows for this subject (a material spread across N
                // lots has N rows) — not one arbitrary lot via ->first().
                $itemData['system_quantity'] = $this->aggregateSystemQuantity(
                    $count,
                    $itemData['product_sku_id'] ?? null,
                    $itemData['material_id'] ?? null,
                );

                $count->items()->create($itemData);
            }
        });

        return $this->loadRelations($count);
    }

    public function start(StockCount $count): StockCount
    {
        $this->assertStatus($count, [StockCountStatusEnum::Draft], 'start');

        $count->update([
            'status' => StockCountStatusEnum::InProgress->value,
        ]);

        return $this->loadRelations($count);
    }

    /**
     * Update counted quantities and auto-calculate differences.
     *
     * @param  array<int, array{id: string, counted_quantity: float, note?: string}>  $items
     */
    public function updateItems(StockCount $count, array $items): StockCount
    {
        $this->assertStatus($count, [StockCountStatusEnum::InProgress], 'update items on');

        DB::transaction(function () use ($count, $items) {
            foreach ($items as $itemData) {
                // plan-040 M2/NEW-STK-6: lock the count item row for the life of
                // the transaction so a concurrent update-items/approve can't read
                // a half-written difference. Re-snapshot system_quantity from the
                // current StockLevels under the same lock so a sale interleaved
                // since the snapshot is reflected (not silently overwritten by a
                // stale baseline).
                $item = $count->items()->lockForUpdate()->findOrFail($itemData['id']);

                $item->system_quantity = $this->aggregateSystemQuantity(
                    $count,
                    $item->product_sku_id,
                    $item->material_id,
                );

                $countedQty = (float) $itemData['counted_quantity'];

                // Plan-022 T1.8 — operator may count in any registered unit
                // (e.g. "5 bag"); system_quantity is always base-unit
                // (sum of lot.qty_on_hand). Convert before subtracting.
                $countedQtyBase = $item->material_id !== null && $item->unit !== null
                    ? $this->transactionService->calculateBaseQuantity(
                        $countedQty,
                        $item->unit,
                        $item->material_id,
                    )
                    : $countedQty;

                $difference = $countedQtyBase - (float) $item->system_quantity;

                $updateData = [
                    'system_quantity' => (float) $item->system_quantity,
                    'counted_quantity' => $countedQty,
                    'difference' => $difference,
                ];

                if (isset($itemData['note'])) {
                    $updateData['note'] = $itemData['note'];
                }

                $item->update($updateData);
            }
        });

        return $this->loadRelations($count);
    }

    public function submit(StockCount $count): StockCount
    {
        $this->assertStatus($count, [StockCountStatusEnum::InProgress], 'submit');

        $count->update([
            'status' => StockCountStatusEnum::PendingApproval->value,
        ]);

        return $this->loadRelations($count);
    }

    public function approve(StockCount $count, string $approverId): StockCount
    {
        $this->assertStatus($count, [StockCountStatusEnum::PendingApproval], 'approve');

        return DB::transaction(function () use ($count, $approverId) {
            // plan-040 NEW-STK-6: lock the count items for the life of the
            // approval so a concurrent update-items can't interleave a
            // half-written difference. Locking here (not loading unlocked)
            // pairs with the lock in updateItems.
            $items = $count->items()->lockForUpdate()->get();

            // Create adjustment transactions for items with differences
            $adjustInItems = [];
            $adjustOutItems = [];

            foreach ($items as $item) {
                // plan-040 NEW-STK-5 (Decision 4): the count is absolute physical
                // truth. Re-derive the difference against the CURRENT aggregate
                // on-hand (re-snapshot under lock — plan-040 M2) so a sale
                // interleaved between count create and approve is reconciled to,
                // not silently overwritten by, a stale baseline. The posted
                // adjustment then drives SUM(on-hand) to the counted total.
                $currentSystem = $this->aggregateSystemQuantity(
                    $count,
                    $item->product_sku_id,
                    $item->material_id,
                    forUpdate: true,
                );

                $countedBase = $item->material_id !== null && $item->unit !== null
                    ? $this->transactionService->calculateBaseQuantity(
                        (float) $item->counted_quantity,
                        $item->unit,
                        $item->material_id,
                    )
                    : (float) $item->counted_quantity;

                $difference = $countedBase - $currentSystem;

                // Persist the reconciled snapshot + difference so the record
                // reflects the absolute baseline the adjustment was posted from.
                $item->update([
                    'system_quantity' => $currentSystem,
                    'difference' => $difference,
                ]);

                // plan-040 info-7: tolerance compare instead of a strict `=== 0.0`.
                // A non-integer unit `ratio` through calculateBaseQuantity can leave
                // a sub-epsilon residue that would otherwise post a spurious
                // micro-adjustment.
                if (abs($difference) < 1e-6) {
                    continue;
                }

                // Plan-022 T1.8 — `difference` is already in the material's
                // base unit. Pass the base unit on the txn item so
                // StockTransactionService::create's conversion is a no-op (×1)
                // and the ledger does not double-convert.
                $txnUnit = $item->unit;
                if ($item->material_id !== null) {
                    $base = MaterialUnit::query()
                        ->where('material_id', $item->material_id)
                        ->where('is_base', true)
                        ->value('unit');
                    if ($base !== null) {
                        $txnUnit = $base;
                    }
                }

                $entry = [
                    'product_sku_id' => $item->product_sku_id,
                    'material_id' => $item->material_id,
                    'quantity' => abs($difference),
                    'unit' => $txnUnit,
                ];

                if ($difference > 0) {
                    $adjustInItems[] = $entry;
                } else {
                    $adjustOutItems[] = $entry;
                }
            }

            if (! empty($adjustInItems)) {
                $txn = $this->transactionService->create([
                    'organization_id' => $count->organization_id,
                    'warehouse_id' => $count->warehouse_id,
                    'type' => StockTransactionTypeEnum::StockIn->value,
                    'sub_type' => StockTransactionSubTypeEnum::AdjustmentIn->value,
                    'reference_type' => 'stock_count',
                    'reference_id' => $count->id,
                    'created_by_id' => $approverId,
                    'note' => "Adjustment in from stock count {$count->count_code}",
                    'items' => $adjustInItems,
                ]);
                $this->transactionService->submit($txn);
                $txn->refresh();

                if ($txn->status === 'pending') {
                    $this->transactionService->approve($txn, $approverId);
                }
            }

            if (! empty($adjustOutItems)) {
                $txn = $this->transactionService->create([
                    'organization_id' => $count->organization_id,
                    'warehouse_id' => $count->warehouse_id,
                    'type' => StockTransactionTypeEnum::StockOut->value,
                    'sub_type' => StockTransactionSubTypeEnum::AdjustmentOut->value,
                    'reference_type' => 'stock_count',
                    'reference_id' => $count->id,
                    'created_by_id' => $approverId,
                    'note' => "Adjustment out from stock count {$count->count_code}",
                    'items' => $adjustOutItems,
                ]);
                $this->transactionService->submit($txn);
                $txn->refresh();

                if ($txn->status === 'pending') {
                    $this->transactionService->approve($txn, $approverId);
                }
            }

            $count->update([
                'status' => StockCountStatusEnum::Approved->value,
                'approved_by_id' => $approverId,
                'approved_at' => now(),
                'completed_at' => now(),
            ]);

            return $this->loadRelations($count);
        });
    }

    public function cancel(StockCount $count): StockCount
    {
        $this->assertStatus($count, [
            StockCountStatusEnum::Draft,
            StockCountStatusEnum::InProgress,
            StockCountStatusEnum::PendingApproval,
        ], 'cancel');

        $count->update([
            'status' => StockCountStatusEnum::Cancelled->value,
        ]);

        return $this->loadRelations($count);
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function generateCode(string $organizationId): string
    {
        $prefix = 'SC';
        $date = now()->format('Ymd');
        $baseCode = "{$prefix}-{$date}-";

        // plan-040 L1: lock the per-(org, day-prefix) rows so concurrent creates
        // serialize on the max read and cannot mint duplicate count codes.
        // #531 sub-bug: the suffix is 3-digit zero-padded, so a plain string
        // sort ranks "...-999" above "...-1000" — past the 999th doc/org/day it
        // re-mints an existing number. Order by LENGTH first (more digits = a
        // larger number) then lexically, so 1000+ sorts correctly.
        $last = StockCount::where('organization_id', $organizationId)
            ->where('count_code', 'like', "{$baseCode}%")
            ->orderByRaw('LENGTH(count_code) DESC')
            ->orderBy('count_code', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $last
            ? (int) str_replace($baseCode, '', $last->count_code) + 1
            : 1;

        return $baseCode.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function loadRelations(StockCount $count): StockCount
    {
        return $count->load([
            'warehouse',
            'items.productSku',
            'items.material',
        ])->loadCount('items');
    }

    /**
     * @param  StockCountStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(StockCount $count, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn (StockCountStatusEnum $s) => $s->value, $allowedStatuses);
        $currentValue = $count->status instanceof StockCountStatusEnum
            ? $count->status->value
            : (string) $count->status;

        if (! in_array($currentValue, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: stock count status is '{$currentValue}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }
}
