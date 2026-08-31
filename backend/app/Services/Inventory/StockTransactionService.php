<?php

namespace App\Services\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\MaterialSubstitutionRule;
use App\Models\MaterialUnit;
use App\Models\StockAlert;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Omnify\Enums\StockTransactionStatusEnum;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Services\Inventory\Concerns\AssertsWarehouseOrganization;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransactionService
{
    use AssertsWarehouseOrganization;

    public function __construct(
        private readonly GenealogyLinkService $genealogyLinkService,
        private readonly MaterialLotReservationService $materialLotReservationService,
        private readonly StockLevelService $stockLevelService,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, warehouse_id?: string, type?: string, sub_type?: string, status?: string|array, date_from?: string, date_to?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = StockTransaction::query()
            ->with(['warehouse', 'createdBy:id,name,email'])
            ->withCount('items');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));
        $query->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));
        $query->when($filters['sub_type'] ?? null, fn ($q, $subType) => $q->where('sub_type', $subType));

        // Status filter accepts a single value, an array, OR a comma-separated
        // string ("draft,pending"). Earlier this only honoured the literal
        // single-value form which silently broke multi-status UIs.
        if (! empty($filters['status'])) {
            $statuses = $this->normalizeListFilter($filters['status']);

            if (count($statuses) === 1) {
                $query->where('status', $statuses[0]);
            } elseif (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            }
        }

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
            $q->where('transaction_code', 'like', "%{$search}%");
        });

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): StockTransaction
    {
        return StockTransaction::with([
            'warehouse',
            'createdBy:id,name,email',
            'approvedBy:id,name,email',
            'items.productSku',
            'items.material',
        ])->findOrFail($id);
    }

    /**
     * Normalise a filter value that may arrive as a string, CSV, or array
     * into a deduped array of trimmed non-empty strings. Used by `status`
     * and other multi-value filters.
     *
     * @param  string|array<int|string, mixed>  $value
     * @return array<int, string>
     */
    private function normalizeListFilter(string|array $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $value = array_map(static fn ($v) => trim((string) $v), $value);
        $value = array_values(array_filter($value, static fn ($v) => $v !== ''));

        return array_values(array_unique($value));
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): StockTransaction
    {
        $this->assertWarehousesBelongToOrganization(
            $data['organization_id'] ?? null,
            [$data['warehouse_id'] ?? null],
        );

        return DB::transaction(function () use ($data) {
            $data['transaction_code'] = $this->generateTransactionCode($data['type'], $data['organization_id']);
            $data['status'] = StockTransactionStatusEnum::Draft->value;

            $items = $data['items'] ?? [];
            unset($data['items']);

            $transaction = StockTransaction::create($data);

            foreach ($items as $itemData) {
                $itemData['base_quantity'] = $this->calculateBaseQuantity(
                    $itemData['quantity'],
                    $itemData['unit'] ?? null,
                    $itemData['material_id'] ?? null,
                );
                $transaction->items()->create($itemData);
            }

            return $transaction->load(['warehouse', 'createdBy:id,name,email', 'approvedBy:id,name,email', 'items.productSku', 'items.material'])
                ->loadCount('items');
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(StockTransaction $transaction, array $data): StockTransaction
    {
        $this->assertStatus($transaction, [StockTransactionStatusEnum::Draft], 'update');

        return DB::transaction(function () use ($transaction, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);

            $transaction->update($data);

            if ($items !== null) {
                $transaction->items()->delete();

                foreach ($items as $itemData) {
                    $itemData['base_quantity'] = $this->calculateBaseQuantity(
                        $itemData['quantity'],
                        $itemData['unit'] ?? null,
                        $itemData['material_id'] ?? null,
                    );
                    $transaction->items()->create($itemData);
                }
            }

            return $transaction->load(['warehouse', 'createdBy:id,name,email', 'approvedBy:id,name,email', 'items.productSku', 'items.material'])
                ->loadCount('items');
        });
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    public function delete(StockTransaction $transaction): bool
    {
        $this->assertStatus($transaction, [
            StockTransactionStatusEnum::Draft,
            StockTransactionStatusEnum::Cancelled,
        ], 'delete');

        return DB::transaction(function () use ($transaction) {
            $transaction->items()->delete();

            return $transaction->delete();
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submit(StockTransaction $transaction): StockTransaction
    {
        $this->assertStatus($transaction, [StockTransactionStatusEnum::Draft], 'submit');

        $warehouse = $transaction->warehouse ?? Warehouse::findOrFail($transaction->warehouse_id);

        $shouldAutoApprove = $this->shouldAutoApprove($warehouse, $transaction->type);

        if ($shouldAutoApprove) {
            return $this->completeTransaction($transaction);
        }

        $transaction->update([
            'status' => StockTransactionStatusEnum::Pending->value,
        ]);

        return $transaction->load(['warehouse', 'items.productSku', 'items.material'])
            ->loadCount('items');
    }

    public function approve(StockTransaction $transaction, string $approverId): StockTransaction
    {
        $this->assertStatus($transaction, [StockTransactionStatusEnum::Pending], 'approve');

        $transaction->update([
            'approved_by_id' => $approverId,
            'approved_at' => now(),
        ]);

        return $this->completeTransaction($transaction);
    }

    public function cancel(StockTransaction $transaction): StockTransaction
    {
        $this->assertStatus($transaction, [
            StockTransactionStatusEnum::Draft,
            StockTransactionStatusEnum::Pending,
        ], 'cancel');

        $transaction->update([
            'status' => StockTransactionStatusEnum::Cancelled->value,
        ]);

        return $transaction->load(['warehouse', 'items.productSku', 'items.material'])
            ->loadCount('items');
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function completeTransaction(StockTransaction $transaction): StockTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $transaction->load('items');
            $isStockIn = $transaction->type === StockTransactionTypeEnum::StockIn;
            $shortages = [];

            // Plan-024 — allow-negative sales-flow gate.
            // When the warehouse opts in via `allow_negative_sales=true`,
            // shortages on sales-flow stock_out transactions do NOT abort
            // the transaction. Instead, the StockLevel is written to the
            // resulting negative quantity and an out_of_stock StockAlert
            // fires. Manual stock_out / disposal / adjustment_out remain
            // strict regardless of the flag.
            $allowNegativeSales = false;
            if (! $isStockIn) {
                $subTypeValue = $transaction->sub_type instanceof \BackedEnum
                    ? $transaction->sub_type->value
                    : (string) $transaction->sub_type;
                $salesSubtypes = ['sales', 'sales_material_consumption'];
                if (in_array($subTypeValue, $salesSubtypes, true)) {
                    $warehouse = $transaction->warehouse ?? Warehouse::find($transaction->warehouse_id);
                    $allowNegativeSales = $warehouse && (bool) $warehouse->allow_negative_sales;
                }
            }

            // FEFO pre-pass — for stock_out items with material_id and no
            // material_lot_id, allocate across active lots oldest-expiry-first
            // and replace the single input row with N split rows. lot rows are
            // locked for update inside the picker so concurrent batches can't
            // both drain the same lot.
            if (! $isStockIn) {
                $this->splitStockOutItemsByFefo($transaction, $allowNegativeSales);
                $transaction->load('items');
            }

            $touchedLotIds = [];

            // plan-040 TF.2 (H15/M3): collect the (warehouse, sku|material) keys
            // touched by this transaction and evaluate alerts ONCE per key against
            // the material TOTAL after every line item is written — not per
            // intermediate FEFO-split item. Per-item evaluation flip-flopped a
            // single stock-out across resolved/active rows, creating duplicate
            // alert rows and double-dispatching the notification.
            $alertKeys = [];
            $forcedOutKeys = [];

            foreach ($transaction->items as $item) {
                $levelQuery = StockLevel::where('warehouse_id', $transaction->warehouse_id);

                if ($item->product_sku_id) {
                    $levelQuery->where('product_sku_id', $item->product_sku_id)
                        ->whereNull('material_lot_id');
                } else {
                    $levelQuery->where('material_id', $item->material_id);
                    $item->material_lot_id
                        ? $levelQuery->where('material_lot_id', $item->material_lot_id)
                        : $levelQuery->whereNull('material_lot_id');
                }

                // plan-040 TA.6 (M14): route lot-less writes through the single
                // canonical (whereNull material_lot_id) row so a (warehouse, key)
                // total can't fragment across NULL-distinct duplicate rows.
                if ($item->material_lot_id === null) {
                    $stockLevel = $this->stockLevelService->canonicalLotLessLevel(
                        warehouseId: (string) $transaction->warehouse_id,
                        productSkuId: $item->product_sku_id,
                        materialId: $item->material_id,
                        unit: $item->unit,
                    );
                } else {
                    $stockLevel = $levelQuery->lockForUpdate()->first();

                    if (! $stockLevel) {
                        $stockLevel = StockLevel::create([
                            'warehouse_id' => $transaction->warehouse_id,
                            'product_sku_id' => $item->product_sku_id,
                            'material_id' => $item->material_id,
                            'material_lot_id' => $item->material_lot_id,
                            'quantity' => 0,
                            'unit' => $item->unit,
                            // plan-040 TF.7 (NEW-STK-7): default alerts ON so a
                            // later min_stock edit can fire low-stock alerts.
                            'alert_enabled' => true,
                        ]);

                        $stockLevel = StockLevel::where('id', $stockLevel->id)->lockForUpdate()->first();
                    }
                }

                $quantityBefore = (float) $stockLevel->quantity;
                $changeQuantity = (float) $item->base_quantity;

                $isShortage = false;
                if (! $isStockIn && $quantityBefore < $changeQuantity) {
                    if (! $allowNegativeSales) {
                        $shortages[] = [
                            'product_sku_id' => $item->product_sku_id,
                            'material_id' => $item->material_id,
                            'requested' => $changeQuantity,
                            'available' => $quantityBefore,
                        ];

                        continue;
                    }
                    // Plan-024 — allow-negative sales: proceed with the
                    // negative write, force out_of_stock alert below.
                    $isShortage = true;
                }

                $quantityAfter = $isStockIn
                    ? $quantityBefore + $changeQuantity
                    : $quantityBefore - $changeQuantity;

                $stockLevel->update(['quantity' => $quantityAfter]);

                StockMovement::create([
                    'warehouse_id' => $transaction->warehouse_id,
                    'product_sku_id' => $item->product_sku_id,
                    'material_id' => $item->material_id,
                    'material_lot_id' => $item->material_lot_id,
                    'stock_transaction_id' => $transaction->id,
                    'movement_type' => $isStockIn ? 'in' : 'out',
                    'quantity' => $changeQuantity,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityAfter,
                    'unit' => $item->unit,
                    'created_at' => now(),
                ]);

                // Lot bookkeeping — only stock_out drains qty_on_hand. stock_in
                // sets qty_on_hand at lot creation (MaterialLotService::receive
                // or MaterialBatchService::complete) and must NOT double-count
                // it here.
                if ($item->material_lot_id) {
                    if (! $isStockIn) {
                        // plan-018 Group A — negative-stock guard at lot grain.
                        // Omnify YAML cannot express a DB CHECK constraint and
                        // hand-written migrations are blocked in this repo, so the
                        // qty_on_hand >= 0 invariant is enforced here in the sole
                        // path that drains a lot. FEFO allocation already caps each
                        // draw at available qty; an explicit-lot line (manual
                        // stock_out, or a stock_level/lot divergence under
                        // allow-negative sales) could still over-draw — reject it
                        // rather than persist a corrupt negative balance. bcsub keeps
                        // the arithmetic exact at the column's 4-dp scale.
                        $lockedLot = MaterialLot::where('id', $item->material_lot_id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        $newLotQty = bcsub((string) $lockedLot->qty_on_hand, (string) $changeQuantity, 4);
                        if (bccomp($newLotQty, '0', 4) < 0) {
                            throw new \RuntimeException(
                                "Refusing to drive material_lot {$lockedLot->id} qty_on_hand negative "
                                ."({$lockedLot->qty_on_hand} - {$changeQuantity} = {$newLotQty})."
                            );
                        }

                        $lockedLot->update(['qty_on_hand' => $newLotQty]);

                        // plan-040 H3a: the goods backing any active reservation on
                        // this lot just left, so draw the reservations down (move to
                        // consumed / decrement) instead of leaving them active to
                        // permanently subtract from FEFO availability. Wired here in
                        // the real consumption path — consume() was never called.
                        $this->materialLotReservationService
                            ->consumeForLot((string) $item->material_lot_id, $changeQuantity);
                    }
                    $touchedLotIds[$item->material_lot_id] = true;
                }

                // plan-040 TF.2 (H15/M3): defer alert fire/resolve to a single
                // post-loop pass keyed on (warehouse, sku|material). Evaluating
                // per intermediate FEFO-split item flapped the same key between
                // resolved/active and created duplicate rows. `$isShortage` (the
                // Plan-024 allow-negative shortage) forces an out_of_stock alert
                // even when the level has no threshold configured.
                $keyHash = ($item->product_sku_id ?? '').'|'.($item->material_id ?? '');
                $alertKeys[$keyHash] = [
                    'product_sku_id' => $item->product_sku_id,
                    'material_id' => $item->material_id,
                    'unit' => $item->unit,
                ];
                if ($isShortage) {
                    $forcedOutKeys[$keyHash] = true;
                }
            }

            if (! empty($shortages)) {
                throw new InsufficientStockException(
                    warehouseId: $transaction->warehouse_id,
                    shortages: $shortages,
                );
            }

            // plan-040 TF.2/TF.3: aggregated alert pass — one evaluation per
            // touched key against the material/sku TOTAL on-hand.
            foreach ($alertKeys as $keyHash => $key) {
                $this->evaluateStockAlert(
                    $transaction,
                    $key['product_sku_id'],
                    $key['material_id'],
                    $key['unit'],
                    $forcedOutKeys[$keyHash] ?? false,
                );
            }

            $transaction->update([
                'status' => StockTransactionStatusEnum::Completed->value,
                'completed_at' => now(),
            ]);

            // Genealogy edges (plan-017 §sales/disposal) — for stock_out
            // transactions that came from a customer order or a disposal,
            // emit a GenealogyLink row per consumed lot so TraceService can
            // walk customer_order_id / disposal_record_id back to the source
            // supplier lots. Only fires for items that picked up a
            // material_lot_id in the FEFO pre-pass; legacy NULL-lot stock
            // skips silently because there's no upstream lot to link.
            if (! $isStockIn) {
                $subTypeValue = $transaction->sub_type instanceof \BackedEnum
                    ? $transaction->sub_type->value
                    : (string) $transaction->sub_type;
                $referenceType = (string) ($transaction->reference_type ?? '');
                $referenceId = (string) ($transaction->reference_id ?? '');

                // plan-040 C8: record the sales-edge genealogy from the LOCKED
                // allocation that pickLotsForConsumption decremented (the items
                // re-stamped with material_lot_id in the FEFO pre-pass), NOT from
                // a post-depletion previewLotsForConsumption call. The made-to-
                // order path closes via the `sales_material_consumption` sub_type,
                // so it must be handled here alongside `sales`; otherwise the edge
                // gets computed after the lots were drained and points at the next
                // FEFO lot instead of the one actually consumed.
                $isCustomerOrderConsumption = in_array($subTypeValue, ['sales', 'sales_material_consumption'], true);

                if ($isCustomerOrderConsumption && $referenceType === 'customer_order' && $referenceId !== '') {
                    foreach ($transaction->fresh()->items as $item) {
                        if (! $item->material_lot_id) {
                            continue;
                        }
                        $parent = MaterialLot::find($item->material_lot_id);
                        if (! $parent) {
                            continue;
                        }
                        $this->genealogyLinkService->recordSalesConsumption(
                            parentLot: $parent,
                            customerOrderId: $referenceId,
                            qtyConsumed: (float) $item->base_quantity,
                            unit: $item->unit,
                            transactionId: (string) $transaction->id,
                        );
                    }
                } elseif ($subTypeValue === 'disposal' && $referenceId !== '') {
                    foreach ($transaction->fresh()->items as $item) {
                        if (! $item->material_lot_id) {
                            continue;
                        }
                        $parent = MaterialLot::find($item->material_lot_id);
                        if (! $parent) {
                            continue;
                        }
                        $this->genealogyLinkService->recordDisposal(
                            parentLot: $parent,
                            qtyConsumed: (float) $item->base_quantity,
                            unit: $item->unit,
                            disposalRecordId: $referenceId,
                        );
                    }
                }
            }

            // Flip Active → Depleted for any lot drained to 0 by this txn.
            // Done after the items loop so a multi-allocation that re-touches
            // the same lot only checks once.
            foreach (array_keys($touchedLotIds) as $lotId) {
                $lot = MaterialLot::find($lotId);
                if ($lot === null) {
                    continue;
                }
                $statusValue = $lot->status instanceof \BackedEnum ? $lot->status->value : (string) $lot->status;
                if ((float) $lot->qty_on_hand <= 0
                    && $statusValue === MaterialLotStatusEnum::Active->value
                ) {
                    $lot->update(['status' => MaterialLotStatusEnum::Depleted->value]);
                    $lot->logAudit('depleted', ['stock_transaction_id' => $transaction->id]);
                }
            }

            return $transaction->load(['warehouse', 'createdBy:id,name,email', 'approvedBy:id,name,email', 'items.productSku', 'items.material'])
                ->loadCount('items');
        });
    }

    /**
     * plan-040 TF.2/TF.3 (H15/M3/L9) — evaluate a single low/out alert for a
     * touched (warehouse, sku|material) key.
     *
     * Keyed on the MATERIAL TOTAL on-hand (SUM across every lot row), not a
     * single FEFO-split line item's intermediate quantity — so an unrelated
     * lot of the same material can't resolve a still-low alert and a single
     * stock-out can't flap the same key into duplicate rows. Threshold +
     * alert_enabled are read from the canonical lot-less row (the row that
     * holds material-level config). `$forcedOut` is the Plan-024 allow-negative
     * shortage: it fires out_of_stock even with no threshold configured.
     *
     * Boundary is unified to `<=` (fire) / `>` (resolve) so it agrees with the
     * `stock_status` display filter in StockLevelService::list (L9).
     */
    private function evaluateStockAlert(
        StockTransaction $transaction,
        ?string $productSkuId,
        ?string $materialId,
        ?string $unit,
        bool $forcedOut,
    ): void {
        $warehouseId = $transaction->warehouse_id;

        $totalQuery = StockLevel::where('warehouse_id', $warehouseId);
        $configQuery = StockLevel::where('warehouse_id', $warehouseId);
        if ($productSkuId !== null) {
            $totalQuery->where('product_sku_id', $productSkuId);
            $configQuery->where('product_sku_id', $productSkuId);
        } else {
            $totalQuery->where('material_id', $materialId);
            $configQuery->where('material_id', $materialId);
        }

        $total = (float) $totalQuery->sum('quantity');

        // Material-level config lives on the canonical lot-less row; fall back
        // to any touched row when there is none (sku rows are always lot-less).
        $configLevel = (clone $configQuery)->whereNull('material_lot_id')->first()
            ?? $configQuery->first();

        $alertEnabled = $configLevel !== null && (bool) $configLevel->alert_enabled;
        $minStock = $configLevel !== null && $configLevel->min_stock !== null
            ? (float) $configLevel->min_stock
            : null;

        $existingAlert = StockAlert::where('warehouse_id', $warehouseId)
            ->where('product_sku_id', $productSkuId)
            ->where('material_id', $materialId)
            ->where('status', 'active')
            ->first();

        $shouldAlert = $forcedOut
            || ($alertEnabled && $minStock !== null && $total <= $minStock);

        if ($shouldAlert) {
            $alertType = ($forcedOut || $total <= 0) ? 'out_of_stock' : 'low_stock';

            // Upgrade an existing low_stock alert to out_of_stock.
            if ($existingAlert !== null
                && $alertType === 'out_of_stock'
                && $existingAlert->alert_type !== 'out_of_stock'
            ) {
                $existingAlert->update(['status' => 'resolved', 'resolved_at' => now()]);
                $existingAlert = null;
            }

            if ($existingAlert === null) {
                StockAlert::create([
                    'organization_id' => $transaction->organization_id,
                    'warehouse_id' => $warehouseId,
                    'product_sku_id' => $productSkuId,
                    'material_id' => $materialId,
                    'alert_type' => $alertType,
                    'current_quantity' => $total,
                    'min_stock' => $minStock,
                    'unit' => $unit,
                    'status' => 'active',
                    'created_at' => now(),
                ]);
            }

            return;
        }

        // Recovered above threshold → resolve the active alert for this key.
        if ($existingAlert !== null
            && $alertEnabled
            && $minStock !== null
            && $total > $minStock
        ) {
            $existingAlert->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }

    /**
     * FEFO pre-pass for stock_out items.
     *
     * For each item that targets a material AND has no explicit
     * material_lot_id, query the active lot pool ordered by expiry_date ASC,
     * then received_at ASC, then id ASC, lock those rows for update, and
     * allocate greedily. If active lots cannot satisfy the demand, try an
     * explicitly configured material substitution. If that is still short,
     * throw InsufficientStockException — the surrounding DB::transaction will
     * roll back the whole completeTransaction.
     *
     * Items that already carry a material_lot_id (e.g. ops chose a specific
     * lot via manual override at request time) are left alone — but the
     * status of that lot is asserted to be Active so quarantined / expired
     * lots cannot be drained.
     *
     * Plan-024: when `$allowNegativeSales = true`, any residual demand that
     * lots + substitutions cannot cover is emitted as a
     * `material_lot_id=null` line carrying the unmet quantity. That residual
     * hits the per-item shortage check in `completeTransaction`, which then
     * drives the material-level StockLevel negative and fires the out_of_stock
     * alert. Without this branch, FEFO would silently under-allocate the demand
     * and the resulting StockLevel writes would not reflect the shortfall.
     */
    private function splitStockOutItemsByFefo(StockTransaction $transaction, bool $allowNegativeSales = false): void
    {
        $items = $transaction->items;
        $warehouseId = $transaction->warehouse_id;

        foreach ($items as $item) {
            if ($item->product_sku_id || ! $item->material_id) {
                continue;
            }

            if ($item->material_lot_id) {
                $manualLot = MaterialLot::lockForUpdate()->find($item->material_lot_id);
                $statusValue = $manualLot?->status instanceof \BackedEnum
                    ? $manualLot->status->value
                    : (string) ($manualLot?->status ?? '');
                if (! $manualLot || $statusValue !== MaterialLotStatusEnum::Active->value) {
                    throw ValidationException::withMessages([
                        'material_lot_id' => "Manual lot override invalid: lot {$item->material_lot_id} is not active.",
                    ]);
                }

                continue;
            }

            $qtyNeeded = (float) $item->base_quantity;
            $allocations = $this->pickLotsForConsumption(
                materialId: (string) $item->material_id,
                warehouseId: (string) $warehouseId,
                qtyNeeded: $qtyNeeded,
                unit: $item->unit,
                allowShort: $allowNegativeSales,
            );

            if ($allocations === []) {
                continue;
            }

            $original = $item;
            $original->delete();

            foreach ($allocations as $alloc) {
                // Plan-017 Tier 1.B — stamp cost_basis_amount from the
                // picked lot's unit_cost so COGS reports use lot-grain
                // figures over material.calculated_cost.
                $costBasisAmount = null;
                $allocLot = null;
                if ($alloc['material_lot_id'] !== null) {
                    $allocLot = MaterialLot::find($alloc['material_lot_id']);
                    if ($allocLot && $allocLot->unit_cost !== null) {
                        $costBasisAmount = (float) $allocLot->unit_cost * (float) $alloc['qty'];
                    }
                }

                // plan-018 audit fix (Finding 1) — a substitution allocation
                // must be stamped with the SUBSTITUTE material_id, not the
                // primary. Otherwise the item points at a (primary_material,
                // substitute_lot) StockLevel row that never exists, the FEFO
                // decrement lands on the wrong ledger row, and stock totals
                // silently corrupt.
                $isSubstitution = ! empty($alloc['substituted']);
                $itemMaterialId = $isSubstitution
                    ? (string) $alloc['material_id']
                    : (string) $original->material_id;

                StockTransactionItem::create([
                    'stock_transaction_id' => $transaction->id,
                    'material_id' => $itemMaterialId,
                    'material_lot_id' => $alloc['material_lot_id'],
                    'quantity' => $alloc['qty'],
                    'base_quantity' => $alloc['qty'],
                    'cost_basis_amount' => $costBasisAmount,
                    'unit' => $original->unit,
                    'unit_price' => $original->unit_price,
                    'note' => $original->note,
                ]);

                // plan-018 audit fix (Finding 2) — substitution traceability.
                // The shipped code swapped materials with zero audit trail. Emit
                // a genealogy edge (source_event_type='substitution') tying the
                // substitute lot to the transaction, and log an audit event on
                // the substitute lot naming the primary it stood in for. (A
                // dedicated SafetyEvent entity does not exist yet — it is
                // deferred to plan-019 HACCP per MaterialBatchService — so the
                // AuditLog carries the safety record for now.)
                if ($isSubstitution && $allocLot !== null) {
                    $this->genealogyLinkService->recordSubstitution(
                        substituteLot: $allocLot,
                        primaryMaterialId: (string) $original->material_id,
                        qtyConsumed: (float) $alloc['qty'],
                        unit: $original->unit,
                        transactionId: (string) $transaction->id,
                    );

                    $allocLot->logAudit('substituted', [
                        'primary_material_id' => (string) $original->material_id,
                        'substitute_material_id' => (string) ($alloc['substitute_material_id'] ?? $itemMaterialId),
                        'substitution_rule_id' => $alloc['substitution_rule_id'] ?? null,
                        'qty' => (float) $alloc['qty'],
                        'stock_transaction_id' => (string) $transaction->id,
                    ]);
                }
            }
        }
    }

    /**
     * Greedy FEFO allocation across the active lot pool. Locks every lot row
     * it inspects so concurrent stock_out transactions serialize on the lot
     * set rather than on the stock_levels rows.
     *
     * Plan-024: when `$allowShort = true` (warehouse opted into
     * `allow_negative_sales` and the transaction is in the sales family),
     * a residual demand that exceeds lots + substitution is NOT
     * thrown. Instead a `material_lot_id=null` residual allocation
     * carrying the unmet quantity is appended, so the caller's per-item
     * shortage check drives the material-level StockLevel negative + fires
     * out_of_stock alert. Without this branch the strict throw would
     * abort the order close even when the warehouse opted in.
     *
     * plan-018 audit fix (Finding 1) — substitution auto-fallback used to be
     * gated on a `$autoSubstitute` method argument that no production caller
     * ever set, so the whole block was dead code. The opt-in now lives on the
     * rule itself (`auto_substitute` + `valid_from`/`valid_until`), which is
     * where the design intended it, so the fallback runs automatically for any
     * opted-in, in-window rule.
     *
     * @return array<int, array{material_lot_id: ?string, qty: float, material_id?: string, substituted?: bool, substitution_rule_id?: string, substitute_material_id?: string}>
     */
    private function pickLotsForConsumption(
        string $materialId,
        string $warehouseId,
        float $qtyNeeded,
        ?string $unit,
        bool $allowShort = false,
    ): array {
        if ($qtyNeeded <= 0) {
            return [];
        }

        $allocations = [];
        $remaining = $qtyNeeded;

        $activeLots = MaterialLot::query()
            ->where('material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->where('qty_on_hand', '>', 0)
            // plan-040 H4: a plain ORDER BY expiry_date sorts NULLs FIRST in MySQL,
            // which would draw no-expiry lots before expiring ones. Push null-expiry last.
            ->orderByRaw('expiry_date is null, expiry_date asc')
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($activeLots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $reservedQty = (float) MaterialLotReservation::where('material_lot_id', $lot->id)
                ->where('status', 'active')
                ->sum('qty_reserved');
            $available = max(0, (float) $lot->qty_on_hand - $reservedQty);
            $take = min($available, $remaining);

            if ($take <= 0) {
                continue;
            }

            $allocations[] = ['material_lot_id' => (string) $lot->id, 'qty' => $take];
            $remaining -= $take;
        }

        // plan-018 audit fix (Finding 1 + 4) — material substitution fallback.
        // Only rules that are active, explicitly opted in (auto_substitute) and
        // inside their temporal validity window participate. Chain depth is 1
        // hop (DESIGN D4): a substitute lacking its own stock is NOT recursively
        // substituted — the residual falls through to the throw/allow-short path.
        if ($remaining > 0) {
            $now = now();
            $substitutionRules = MaterialSubstitutionRule::query()
                ->where('primary_material_id', $materialId)
                ->where('is_active', true)
                ->where('auto_substitute', true)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
                ->orderBy('priority')
                ->get();

            foreach ($substitutionRules as $rule) {
                if ($remaining <= 0) {
                    break;
                }

                $ratio = (float) $rule->conversion_factor;
                if ($ratio <= 0) {
                    // A non-positive ratio is nonsensical (would divide by zero
                    // when converting substitute → primary units). Skip the rule.
                    continue;
                }

                $adjustedQty = $remaining * $ratio;

                $subLots = MaterialLot::query()
                    ->where('material_id', $rule->substitute_material_id)
                    ->where('warehouse_id', $warehouseId)
                    ->where('status', MaterialLotStatusEnum::Active->value)
                    ->where('qty_on_hand', '>', 0)
                    // plan-040 H4: null-expiry lots sort last (FEFO), not first.
                    ->orderByRaw('expiry_date is null, expiry_date asc')
                    ->orderBy('received_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($subLots as $subLot) {
                    if ($adjustedQty <= 0) {
                        break;
                    }

                    $reservedQty = (float) MaterialLotReservation::where('material_lot_id', $subLot->id)
                        ->where('status', 'active')
                        ->sum('qty_reserved');
                    $available = max(0, (float) $subLot->qty_on_hand - $reservedQty);
                    $take = min($available, $adjustedQty);

                    if ($take <= 0) {
                        continue;
                    }

                    // Tag the allocation so splitStockOutItemsByFefo stamps the
                    // SUBSTITUTE material_id on the item (draining the substitute
                    // lot's own StockLevel row) and records the substitution
                    // traceability edge (Finding 2).
                    $allocations[] = [
                        'material_lot_id' => (string) $subLot->id,
                        'qty' => $take,
                        'material_id' => (string) $rule->substitute_material_id,
                        'substitute_material_id' => (string) $rule->substitute_material_id,
                        'substituted' => true,
                        'substitution_rule_id' => (string) $rule->id,
                    ];
                    $adjustedQty -= $take;
                    $remaining -= $take / $ratio;
                }
            }
        }

        if ($remaining > 0) {
            // Plan-024 — allow-negative sales: emit the unmet residual as a
            // material-level line. `completeTransaction` then applies it to the
            // (warehouse, material, NULL-lot) StockLevel like any other line —
            // it DECREMENTS that row, it does not write the residual as a
            // negative outright.
            //
            // So the row only goes negative, and only then forces out_of_stock
            // + notification, when the residual EXCEEDS whatever that row
            // already holds: the shortage flag is `$quantityBefore <
            // $changeQuantity`, nothing more. A warehouse carrying a positive
            // NULL-lot balance draws it down and finishes with no alert at all
            // — see `StockTransactionAllowNegativeTest`, which pins both halves.
            //
            // Worth stating because the strict path reads the opposite way:
            // there a positive NULL-lot balance is never an allocation source,
            // FEFO walks active lots only and a shortfall throws.
            if ($allowShort) {
                $allocations[] = ['material_lot_id' => null, 'qty' => $remaining];

                return $allocations;
            }

            throw new InsufficientStockException(
                warehouseId: $warehouseId,
                shortages: [[
                    'material_id' => $materialId,
                    'requested' => $qtyNeeded,
                    'available' => $qtyNeeded - $remaining,
                ]],
            );
        }

        return $allocations;
    }

    private function shouldAutoApprove(Warehouse $warehouse, StockTransactionTypeEnum|string $type): bool
    {
        $value = $type instanceof StockTransactionTypeEnum ? $type->value : $type;

        return match ($value) {
            StockTransactionTypeEnum::StockIn->value => (bool) $warehouse->auto_approve_stock_in,
            StockTransactionTypeEnum::StockOut->value => (bool) $warehouse->auto_approve_stock_out,
            default => false,
        };
    }

    private function generateTransactionCode(string $type, string $organizationId): string
    {
        $prefix = match ($type) {
            StockTransactionTypeEnum::StockIn->value => 'SI',
            StockTransactionTypeEnum::StockOut->value => 'SO',
            default => 'ST',
        };

        $date = now()->format('Ymd');
        $baseCode = "{$prefix}-{$date}-";

        // plan-040 L1: lock the per-(org, day-prefix) code rows so two concurrent
        // creates inside their surrounding DB::transaction serialize on the max
        // read instead of both seeing the same last code and minting a duplicate.
        // #531 sub-bug: the suffix is 3-digit zero-padded, so a plain string
        // sort ranks "...-999" above "...-1000" — past the 999th doc/org/day it
        // re-mints an existing number. Order by LENGTH first (more digits = a
        // larger number) then lexically, so 1000+ sorts correctly.
        $lastTransaction = StockTransaction::where('organization_id', $organizationId)
            ->where('transaction_code', 'like', "{$baseCode}%")
            ->orderByRaw('LENGTH(transaction_code) DESC')
            ->orderBy('transaction_code', 'desc')
            ->lockForUpdate()
            ->first();

        if ($lastTransaction) {
            $lastNumber = (int) str_replace($baseCode, '', $lastTransaction->transaction_code);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $baseCode.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Plan-022 T8 — read-only preview of FEFO allocation. Mirrors
     * pickLotsForConsumption but skips the lockForUpdate so callers (sales
     * close, batch start preview) can pre-stamp lot ids without serialising
     * against in-flight consumption. The actual allocate-and-decrement still
     * happens in pickLotsForConsumption inside completeTransaction.
     *
     * @return array<int, array{material_lot_id: ?string, qty: float}>
     */
    public function previewLotsForConsumption(
        string $materialId,
        string $warehouseId,
        float $qtyNeeded,
        ?string $unit = null,
    ): array {
        if ($qtyNeeded <= 0) {
            return [];
        }

        $allocations = [];
        $remaining = $qtyNeeded;

        $activeLots = MaterialLot::query()
            ->where('material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->where('qty_on_hand', '>', 0)
            // plan-040 H4: a plain ORDER BY expiry_date sorts NULLs FIRST in MySQL,
            // which would draw no-expiry lots before expiring ones. Push null-expiry last.
            ->orderByRaw('expiry_date is null, expiry_date asc')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        foreach ($activeLots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $reservedQty = (float) MaterialLotReservation::where('material_lot_id', $lot->id)
                ->where('status', 'active')
                ->sum('qty_reserved');
            $available = max(0, (float) $lot->qty_on_hand - $reservedQty);
            $take = min($available, $remaining);

            if ($take <= 0) {
                continue;
            }

            $allocations[] = ['material_lot_id' => (string) $lot->id, 'qty' => $take];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            // Legacy pre-lot bucket — record the residual demand against
            // material_lot_id=null so callers can write the same NULL-lot
            // genealogy edge that pickLotsForConsumption produces.
            $allocations[] = ['material_lot_id' => null, 'qty' => $remaining];
        }

        return $allocations;
    }

    /**
     * plan-040 NEW-LOT-5 — record balanced internal-adjustment movements for a
     * lot split (an `out` on the parent lot's stock_level and an `in` on each
     * child lot's stock_level), tied to a real backing StockTransaction so the
     * NOT-NULL `stock_transaction_id` FK on stock_movements is satisfied.
     *
     * This reuses the movement-owning service (StockMovement rows live here, not
     * in MaterialLotService) and the same locked stock_level write pattern as
     * completeTransaction, but WITHOUT the FEFO pick / qty_on_hand bookkeeping —
     * the split has already moved qty_on_hand between lots and set the per-lot
     * stock_levels via MaterialLotService::setLotStockLevel(). Here we only emit
     * the ledger movements that make those stock_level deltas replayable.
     *
     * @param  array{
     *   organization_id: string,
     *   warehouse_id: string,
     *   reference_type?: ?string,
     *   reference_id?: ?string,
     *   created_by_id?: ?string,
     *   note?: ?string,
     *   movements: array<int, array{
     *     material_id: string,
     *     material_lot_id: ?string,
     *     warehouse_id: string,
     *     movement_type: string,
     *     quantity: float,
     *     quantity_before: float,
     *     quantity_after: float,
     *     unit: ?string
     *   }>
     * }  $data
     */
    public function recordSplitMovements(array $data): StockTransaction
    {
        return DB::transaction(function () use ($data) {
            $movements = $data['movements'] ?? [];
            unset($data['movements']);

            // A backing stock_out transaction satisfies the movement FK. The
            // split is an internal rebalance, so use the adjustment_out sub_type
            // rather than a sales/transfer family value. It is recorded directly
            // in Completed state — the stock_levels are already reconciled by
            // the caller (MaterialLotService::split → setLotStockLevel).
            $transaction = StockTransaction::create([
                'transaction_code' => $this->generateTransactionCode(
                    StockTransactionTypeEnum::StockOut->value,
                    $data['organization_id'],
                ),
                'organization_id' => $data['organization_id'],
                'warehouse_id' => $data['warehouse_id'],
                'type' => StockTransactionTypeEnum::StockOut->value,
                'sub_type' => StockTransactionSubTypeEnum::AdjustmentOut->value,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'created_by_id' => $data['created_by_id'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => StockTransactionStatusEnum::Completed->value,
                'completed_at' => now(),
            ]);

            foreach ($movements as $movement) {
                StockMovement::create([
                    'warehouse_id' => $movement['warehouse_id'],
                    'product_sku_id' => null,
                    'material_id' => $movement['material_id'],
                    'material_lot_id' => $movement['material_lot_id'] ?? null,
                    'stock_transaction_id' => $transaction->id,
                    'movement_type' => $movement['movement_type'],
                    'quantity' => $movement['quantity'],
                    'quantity_before' => $movement['quantity_before'],
                    'quantity_after' => $movement['quantity_after'],
                    'unit' => $movement['unit'] ?? null,
                    'created_at' => now(),
                ]);
            }

            return $transaction;
        });
    }

    /**
     * Convert a quantity expressed in `$unit` to the material's base unit using
     * `MaterialUnit.ratio`. When `$materialId` or `$unit` is null the value is
     * returned untouched (non-material rows / no-unit rows skip conversion).
     *
     * Throws when the material has rows in `material_units` but none matching
     * `$unit` — that means the caller passed an unknown unit for the material
     * and silently writing 1:1 would corrupt the ledger.
     */
    public function calculateBaseQuantity(float $quantity, ?string $unit, ?string $materialId = null): float
    {
        if ($materialId === null || $unit === null) {
            return $quantity;
        }

        // plan-040 fix: 'unit' is the legacy NO-BASE-UNIT sentinel returned by
        // MaterialLotService::resolveBaseUnit when a material has no base unit. It
        // is never a real unit name (zero material_units rows use it) but it got
        // persisted onto many StockLevel / MaterialLot / stock_count_item rows
        // before those materials had a base unit. Treat it as "already base" (1:1)
        // so it cannot crash the flows that inherited it (stock count approve,
        // receive, batch, transfer) with a confusing "Unit 'unit' is not defined".
        // A genuinely wrong unit (e.g. 'kg' on a g-only material) still throws below.
        if ($unit === 'unit') {
            return $quantity;
        }

        // Legacy materials with no material_units rows pre-date Plan-022 unit
        // conversion. Skip rather than throw — same fallback as
        // MaterialLotService::assertUnitBelongsToMaterial. New materials seeded
        // with a base unit go through the strict path below.
        $hasUnits = MaterialUnit::query()
            ->where('material_id', $materialId)
            ->exists();

        if (! $hasUnits) {
            return $quantity;
        }

        $row = MaterialUnit::query()
            ->where('material_id', $materialId)
            ->where('unit', $unit)
            ->first(['ratio', 'is_base']);

        if ($row === null) {
            throw ValidationException::withMessages([
                'unit' => "Unit '{$unit}' is not defined for material {$materialId}.",
            ]);
        }

        if ($row->is_base) {
            return $quantity;
        }

        return $quantity * (float) $row->ratio;
    }

    /**
     * Assert transaction is in one of the allowed statuses.
     *
     * @param  StockTransactionStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(StockTransaction $transaction, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn (StockTransactionStatusEnum $s) => $s->value, $allowedStatuses);
        $currentValue = $transaction->status instanceof StockTransactionStatusEnum
            ? $transaction->status->value
            : (string) $transaction->status;

        if (! in_array($currentValue, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: transaction status is '{$currentValue}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }
}
