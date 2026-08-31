<?php

namespace App\Services\Inventory;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotSourceEnum;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\Concerns\AssertsWarehouseOrganization;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MaterialLotService
 *
 * CRUD + lifecycle workflow for `MaterialLot` rows.
 *
 * Receive flow (lot intake from supplier delivery) lives in `receive()` and
 * delegates the auto stock_in transaction to `StockTransactionService`.
 * Quarantine / Release / Dispose are simple status transitions but each
 * carries an audit log entry via the model's AuditsActivity trait.
 *
 * All write methods wrap in `DB::transaction()` and `lockForUpdate()` the lot
 * row to serialize concurrent state changes (e.g., simultaneous quarantine
 * + dispose attempts).
 */
class MaterialLotService
{
    use AssertsWarehouseOrganization;

    public function __construct(
        private readonly StockTransactionService $stockTransactionService,
        private readonly MaterialLotReservationService $materialLotReservationService,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = MaterialLot::query()
            ->with(['material:id,sku,brand_id', 'warehouse:id,name', 'producedByBatch:id,batch_code']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));
        $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));
        $query->when($filters['material_id'] ?? null, fn ($q, $id) => $q->where('material_id', $id));
        $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $query->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source', $s));

        $query->when($filters['expiring_within_days'] ?? null, function (Builder $q, int $days) use ($filters) {
            // #1091 — "expiring within N days" counts from TODAY AT THE BRANCH,
            // not the UTC calendar: at 07:00 JST the UTC date is still
            // yesterday, which used to include already-expired lots and shift
            // the horizon by a day.
            $today = BusinessClock::now($filters['branch_id'] ?? null);
            $q->where('status', MaterialLotStatusEnum::Active->value)
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [$today->toDateString(), $today->addDays($days)->toDateString()]);
        });

        $query->when($filters['search'] ?? null, function (Builder $q, string $search) {
            // Search spans the lot's own identifiers + the parent material's
            // SKU + translated name. The material join uses `whereHas` so a
            // missing material (shouldn't happen in practice but cheap to
            // guard) just drops the row rather than 500-ing the whole list.
            $q->where(function (Builder $inner) use ($search) {
                $inner->where('lot_code', 'like', "%{$search}%")
                    ->orWhere('supplier_lot_code', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%")
                    ->orWhereHas('material', function (Builder $mat) use ($search) {
                        $mat->where('sku', 'like', "%{$search}%")
                            ->orWhereTranslationLike('name', "%{$search}%");
                    });
            });
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-received_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): MaterialLot
    {
        return MaterialLot::with([
            'material:id,sku,brand_id,requires_temperature_check,temperature_min,temperature_max',
            'warehouse:id,name',
            'producedByBatch:id,batch_code,started_at,completed_at',
        ])->findOrFail($id);
    }

    /**
     * Find the active lot pool for a (material, warehouse) tuple.
     *
     * @return Collection<int, MaterialLot>
     */
    public function lookup(string $organizationId, string $materialId, string $warehouseId): Collection
    {
        return MaterialLot::query()
            ->where('organization_id', $organizationId)
            ->where('material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->where('qty_on_hand', '>', 0)
            // plan-040 H4: null-expiry lots must sort LAST (a plain ORDER BY puts
            // MySQL NULLs first), so FEFO never hands out a no-expiry lot early.
            ->orderByRaw('expiry_date is null, expiry_date asc')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    // =========================================================================
    //  Receive (lot intake from supplier)
    // =========================================================================

    /**
     * Receive a supplier lot. Creates the MaterialLot row and an auto stock_in
     * StockTransaction (sub_type=purchase) referencing the lot. The transaction
     * is submitted immediately; if `warehouse.auto_approve_stock_in` is true
     * it auto-completes (stock_levels increment via StockTransactionService).
     *
     * @param  array{
     *   organization_id: string,
     *   material_id: string,
     *   warehouse_id: string,
     *   supplier_name?: ?string,
     *   supplier_lot_code?: ?string,
     *   received_at?: ?\DateTimeInterface,
     *   expiry_date?: ?string,
     *   received_qty: float,
     *   unit?: ?string,
     *   cost_per_unit?: ?float,
     *   coa_urls?: ?array<string, string>,
     *   created_by_id?: ?string
     * }  $data
     * @return array{lot: MaterialLot, stock_transaction: StockTransaction}
     */
    public function receive(array $data): array
    {
        $this->assertWarehousesBelongToOrganization(
            $data['organization_id'] ?? null,
            [$data['warehouse_id'] ?? null],
        );

        return DB::transaction(function () use ($data) {
            $material = Material::with(['allergens:id', 'materialUnits:id,material_id,unit,is_base'])
                ->findOrFail($data['material_id']);
            $warehouse = Warehouse::with('branch.brand:id,console_brand_id')
                ->findOrFail($data['warehouse_id']);

            $this->assertCrossBrand($material, $warehouse);

            $receivedAt = $data['received_at'] ?? now();
            $expiryDate = $data['expiry_date'] ?? null;
            // plan-040 NEW-LOT-8: compare DATE vs DATE, not datetime. expiry_date is a
            // date; received_at carries a time-of-day. A lot received today (14:30)
            // expiring today is valid, but a datetime compare (midnight < 14:30) wrongly
            // rejected it.
            if ($expiryDate !== null
                && Carbon::parse($expiryDate)->toDateString() < Carbon::parse($receivedAt)->toDateString()) {
                throw ValidationException::withMessages([
                    'expiry_date' => 'expiry_date must be on or after received_at.',
                ]);
            }

            $enteredUnit = $data['unit'] ?? $this->resolveBaseUnit($material);
            $this->assertUnitBelongsToMaterial($material, $enteredUnit);

            $enteredQty = (float) $data['received_qty'];
            if ($enteredQty <= 0) {
                throw ValidationException::withMessages([
                    'received_qty' => 'received_qty must be positive.',
                ]);
            }

            // Plan-022 T1.2 — normalise to base unit. Lot/transaction store the
            // canonical-grain value (qty × ratio); the operator-facing input is
            // preserved on the lot via entered_quantity / entered_unit so the
            // receipt slip can render "2 bag_25kg" while reports / FEFO / COGS
            // operate on grams.
            $unit = $this->resolveBaseUnit($material);
            $receivedQty = $this->stockTransactionService
                ->calculateBaseQuantity($enteredQty, $enteredUnit, $material->id);

            // Plan-017 Tier 1.D — temperature CCP validation. When the
            // material requires a temperature check, the receive payload
            // must include received_temperature; out-of-range readings
            // require an explicit override reason.
            $receivedTemp = $data['received_temperature'] ?? null;
            $overrideReason = $data['temperature_override_reason'] ?? null;

            if ($material->requires_temperature_check) {
                if ($receivedTemp === null) {
                    throw ValidationException::withMessages([
                        'received_temperature' => 'A temperature reading is required for this material.',
                    ]);
                }

                $tempFloat = (float) $receivedTemp;
                $min = $material->temperature_min !== null ? (float) $material->temperature_min : null;
                $max = $material->temperature_max !== null ? (float) $material->temperature_max : null;
                $outOfRange = ($min !== null && $tempFloat < $min) || ($max !== null && $tempFloat > $max);

                if ($outOfRange && (! is_string($overrideReason) || trim($overrideReason) === '')) {
                    throw ValidationException::withMessages([
                        'received_temperature' => sprintf(
                            'Temperature %s°C is outside the allowed range [%s, %s]. An override reason is required to proceed.',
                            $tempFloat,
                            $min ?? '−',
                            $max ?? '−',
                        ),
                    ]);
                }
            }

            // Cost handling — operator types `cost_per_unit` per entered_unit
            // (e.g. $50/bag). Keep total_cost = enteredQty × enteredUnitCost
            // (the actual invoice total) and derive the base-unit cost so
            // FEFO/COGS read consistent values regardless of input unit.
            $enteredUnitCost = $data['cost_per_unit'] ?? null;
            $totalCost = $enteredUnitCost !== null ? (float) $enteredUnitCost * $enteredQty : null;
            $baseUnitCost = ($totalCost !== null && $receivedQty > 0) ? $totalCost / $receivedQty : null;

            $lot = MaterialLot::create([
                'lot_code' => $this->generateLotCode($material, $receivedAt, $data['organization_id']),
                'material_id' => $material->id,
                'warehouse_id' => $warehouse->id,
                'source' => MaterialLotSourceEnum::Inbound->value,
                'status' => MaterialLotStatusEnum::Active->value,
                'supplier_name' => $data['supplier_name'] ?? null,
                'supplier_lot_code' => $data['supplier_lot_code'] ?? null,
                'received_at' => $receivedAt,
                'expiry_date' => $expiryDate,
                'received_qty' => $receivedQty,
                'qty_on_hand' => $receivedQty,
                'unit' => $unit,
                'entered_quantity' => $enteredQty,
                'entered_unit' => $enteredUnit,
                'cost_per_unit' => $baseUnitCost,
                'unit_cost' => $baseUnitCost,
                'total_cost' => $totalCost,
                'currency' => $data['currency'] ?? null,
                'cost_basis' => $enteredUnitCost !== null ? 'manual' : null,
                'coa_urls' => $data['coa_urls'] ?? null,
                'received_temperature' => $receivedTemp,
                'temperature_override_reason' => $overrideReason,
                'effective_allergens' => $material->allergens->pluck('id')->all(),
                'organization_id' => $data['organization_id'],
                'brand_id' => $material->brand_id,
            ]);

            $auditMeta = [
                'received_qty' => $receivedQty,
                'unit' => $unit,
                'entered_quantity' => $enteredQty,
                'entered_unit' => $enteredUnit,
                'supplier_name' => $data['supplier_name'] ?? null,
            ];

            if (! empty($data['allergen_override_reason'])) {
                $auditMeta['allergen_override_reason'] = $data['allergen_override_reason'];
            }

            $lot->logAudit('received', $auditMeta);

            $transaction = $this->stockTransactionService->create([
                'organization_id' => $data['organization_id'],
                'warehouse_id' => $warehouse->id,
                'type' => 'stock_in',
                'sub_type' => 'purchase',
                'reference_type' => 'material_lot',
                'reference_id' => $lot->id,
                'note' => sprintf('Auto stock_in for lot %s', $lot->lot_code),
                'created_by_id' => $data['created_by_id'] ?? null,
                'items' => [[
                    'material_id' => $material->id,
                    'material_lot_id' => $lot->id,
                    'quantity' => $receivedQty,
                    'unit' => $unit,
                    'unit_price' => $baseUnitCost,
                ]],
            ]);

            $transaction = $this->stockTransactionService->submit($transaction);

            return [
                'lot' => $lot->fresh()->load([
                    'material:id,sku,requires_temperature_check,temperature_min,temperature_max',
                    'warehouse:id,name',
                ]),
                'stock_transaction' => $transaction,
            ];
        });
    }

    // =========================================================================
    //  Workflow
    // =========================================================================

    public function quarantine(MaterialLot $lot, string $reason): MaterialLot
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Quarantine reason is required.',
            ]);
        }

        return DB::transaction(function () use ($lot, $reason) {
            $locked = MaterialLot::lockForUpdate()->findOrFail($lot->id);

            $this->assertStatus($locked, [
                MaterialLotStatusEnum::Active,
            ], 'quarantine');

            $locked->update([
                'status' => MaterialLotStatusEnum::Quarantined->value,
                'quarantine_reason' => $reason,
            ]);

            $locked->logAudit('quarantined', ['reason' => $reason]);

            return $locked->fresh()->load(['material:id,sku', 'warehouse:id,name']);
        });
    }

    public function release(MaterialLot $lot): MaterialLot
    {
        return DB::transaction(function () use ($lot) {
            $locked = MaterialLot::lockForUpdate()->findOrFail($lot->id);

            $this->assertStatus($locked, [
                MaterialLotStatusEnum::Quarantined,
            ], 'release');

            $previousReason = $locked->quarantine_reason;

            $locked->update([
                'status' => MaterialLotStatusEnum::Active->value,
                'quarantine_reason' => null,
            ]);

            $locked->logAudit('released', ['previous_reason' => $previousReason]);

            return $locked->fresh()->load(['material:id,sku', 'warehouse:id,name']);
        });
    }

    /**
     * Dispose a lot. Without `force`, the lot must already have qty_on_hand = 0.
     * `force=true` allows disposing remaining stock — used by recall workflow.
     */
    public function dispose(MaterialLot $lot, bool $force = false): MaterialLot
    {
        return DB::transaction(function () use ($lot, $force) {
            $locked = MaterialLot::lockForUpdate()->findOrFail($lot->id);

            $this->assertStatus($locked, [
                MaterialLotStatusEnum::Active,
                MaterialLotStatusEnum::Quarantined,
                MaterialLotStatusEnum::Expired,
                MaterialLotStatusEnum::Depleted,
            ], 'dispose');

            $remainingQty = (float) $locked->qty_on_hand;

            if ($remainingQty > 0 && ! $force) {
                throw ValidationException::withMessages([
                    'lot' => "Cannot dispose lot with {$remainingQty} on hand. Pass force=true to override.",
                ]);
            }

            $locked->update([
                'status' => MaterialLotStatusEnum::Disposed->value,
                'qty_on_hand' => 0,
            ]);

            // plan-040 NEW-LOT-3: the lot is dead — cancel any active
            // reservations so they don't orphan on a disposed lot.
            $releasedReservations = $this->materialLotReservationService->releaseAllForLot($locked->id);

            $locked->logAudit('disposed', [
                'force' => $force,
                'discarded_qty' => $remainingQty,
                'released_reservations' => $releasedReservations,
            ]);

            return $locked->fresh()->load(['material:id,sku', 'warehouse:id,name']);
        });
    }

    /**
     * Mark a lot depleted when its qty_on_hand drops to 0 through normal
     * consumption. Called from StockTransactionService after FEFO allocation.
     */
    public function markDepletedIfEmpty(MaterialLot $lot): void
    {
        $lot->refresh();

        if ((float) $lot->qty_on_hand > 0) {
            return;
        }

        if ($lot->status !== MaterialLotStatusEnum::Active->value) {
            return;
        }

        $lot->update(['status' => MaterialLotStatusEnum::Depleted->value]);
        $lot->logAudit('depleted');
    }

    /**
     * Split a lot into N child lots. The parent keeps whatever quantity was not
     * split off (qty_on_hand − Σparts) and stays Active; it is only marked
     * Depleted when the split consumes the whole lot. Children inherit parent
     * metadata and get genealogy edges with source_event_type=split.
     *
     * @param  array<int, array{qty: float, target_warehouse_id?: string|null}>  $parts
     * @return array{parent: MaterialLot, children: array<int, MaterialLot>}
     */
    public function split(MaterialLot $lot, array $parts, ?string $reason = null, ?string $createdById = null): array
    {
        // plan-040 NEW-LOT-5: the split's backing StockTransaction needs a
        // non-null created_by_id. Prefer the explicit caller id, then the
        // authenticated user.
        $createdById ??= auth()->id();

        // #851 — a cross-warehouse split must not push stock into a warehouse
        // owned by another org.
        $this->assertWarehousesBelongToOrganization(
            (string) $lot->organization_id,
            array_column($parts, 'target_warehouse_id'),
        );

        return DB::transaction(function () use ($lot, $parts, $reason, $createdById) {
            $locked = MaterialLot::lockForUpdate()->findOrFail($lot->id);

            $this->assertStatus($locked, [MaterialLotStatusEnum::Active], 'split');

            $totalSplitQty = array_sum(array_column($parts, 'qty'));

            // plan-040 NEW-LOT-1: validate against availability NET OF active
            // reservations, not raw qty_on_hand. A fully-reserved lot must not let
            // reserved stock escape into an unreserved child (the child carries no
            // reservation, so the reserved quantity would silently become free
            // again). Splitting only the un-reserved remainder is allowed.
            $availableQty = $this->materialLotReservationService->computeAvailableQty($locked);

            if ($totalSplitQty > $availableQty) {
                throw ValidationException::withMessages([
                    'parts' => "Split total ({$totalSplitQty}) exceeds available qty net of reservations ({$availableQty}).",
                ]);
            }

            // plan-040 NEW-LOT-5: the parent's per-lot stock_level mirrors its
            // qty_on_hand, so its pre-split quantity is the `quantity_before` of
            // the balancing `out` movement recorded once all children are minted.
            $parentQtyBefore = (float) $locked->qty_on_hand;

            $children = [];
            // plan-040 NEW-LOT-5: collect balanced movements (one `in` per child)
            // so a single backing StockTransaction can carry the whole split's
            // ledger. The parent `out` is appended after the loop.
            $splitMovements = [];
            // Child codes are `{parent}-S{n}`. The sequence must continue across
            // re-splits (a partial split leaves the parent Active, so it can be
            // split again) — restarting at S1 would collide with the prior
            // split's children on the (organization_id, lot_code) unique index.
            // The unique index ignores deleted_at, so check trashed rows too.
            $seq = 1;
            foreach ($parts as $part) {
                do {
                    $childLotCode = $locked->lot_code.'-S'.$seq;
                    $seq++;
                } while (
                    MaterialLot::withTrashed()
                        ->where('organization_id', $locked->organization_id)
                        ->where('lot_code', $childLotCode)
                        ->exists()
                );
                $targetWarehouseId = $part['target_warehouse_id'] ?? $locked->warehouse_id;

                $child = MaterialLot::create([
                    'lot_code' => $childLotCode,
                    'organization_id' => $locked->organization_id,
                    'brand_id' => $locked->brand_id,
                    'material_id' => $locked->material_id,
                    'warehouse_id' => $targetWarehouseId,
                    'source' => $locked->source,
                    'status' => MaterialLotStatusEnum::Active->value,
                    'qty_on_hand' => $part['qty'],
                    'received_qty' => $part['qty'],
                    'received_at' => $locked->received_at,
                    'expiry_date' => $locked->expiry_date,
                    'unit' => $locked->unit,
                    'unit_cost' => $locked->unit_cost,
                    'total_cost' => $locked->unit_cost ? $locked->unit_cost * $part['qty'] : null,
                    'currency' => $locked->currency,
                    'cost_basis' => $locked->cost_basis,
                    'supplier_name' => $locked->supplier_name,
                    'supplier_lot_code' => $locked->supplier_lot_code,
                    'cost_per_unit' => $locked->cost_per_unit,
                    'effective_allergens' => $locked->effective_allergens,
                    'received_temperature' => $locked->received_temperature,
                    // #1238 — the reason travels with the reading it justifies.
                    // receive() enforces the pair as one unit (an out-of-range
                    // temperature cannot be accepted without a non-empty reason),
                    // so copying the figure alone turned a cold-chain exception
                    // that had been formally accepted into an unexplained one on
                    // every child — and MaterialLotResource serialises the whole
                    // model, so that is exactly what an auditor reads.
                    'temperature_override_reason' => $locked->temperature_override_reason,
                    // #1238 — Certificate of Analysis links: same material, same
                    // delivery, same certificate. Losing them on split leaves the
                    // children undocumented.
                    'coa_urls' => $locked->coa_urls,
                    // Deliberately NOT copied: entered_quantity / entered_unit
                    // record what a human typed at goods-in. A split child was
                    // produced by the system, so asserting someone entered these
                    // figures would be a quiet fiction in the audit trail.
                    // plan-040 NEW-LOT-7: dropped dead 'temperature_unit' — no such
                    // column on material_lots (silently ignored on create).
                ]);

                GenealogyLink::create([
                    'parent_lot_id' => $locked->id,
                    'child_lot_id' => $child->id,
                    'qty_consumed' => $part['qty'],
                    'unit' => $locked->unit,
                    'consumed_at' => now(),
                    'source_event_type' => 'split',
                    'source_event_id' => $locked->id,
                ]);

                // Mirror the new child lot into stock_levels. Without this the
                // lot exists in material_lots (FEFO can pick it) but the
                // per-lot stock_level guard in StockTransactionService sees no
                // row → treats it as qty 0 → spurious INSUFFICIENT_STOCK on the
                // next consumption that touches the child lot.
                $this->setLotStockLevel(
                    warehouseId: $targetWarehouseId,
                    materialId: $locked->material_id,
                    lotId: $child->id,
                    quantity: (float) $part['qty'],
                    unit: $locked->unit,
                );

                // plan-040 NEW-LOT-5: an `in` movement on the freshly-minted
                // child lot's stock_level (before=0 → after=slice). Cross-
                // warehouse splits land the `in` in the target warehouse.
                $splitMovements[] = [
                    'material_id' => (string) $locked->material_id,
                    'material_lot_id' => (string) $child->id,
                    'warehouse_id' => (string) $targetWarehouseId,
                    'movement_type' => 'in',
                    'quantity' => (float) $part['qty'],
                    'quantity_before' => 0.0,
                    'quantity_after' => (float) $part['qty'],
                    'unit' => $locked->unit,
                ];

                $children[] = $child;
            }

            // The parent retains the un-split remainder. Only a full split
            // (remainder == 0) depletes it — a partial split leaves the lot
            // Active with the leftover qty (matches the "Remaining" the operator
            // sees in the split dialog).
            $remainingQty = (float) $locked->qty_on_hand - $totalSplitQty;
            $parentDepleted = $remainingQty <= 0;

            $locked->update([
                'qty_on_hand' => $remainingQty,
                // plan-040 NEW-LOT-4: conserve SUM(received_qty) across the lineage.
                // Each child takes received_qty = its slice; without shrinking the
                // parent's received_qty by the same total the lineage double-counts
                // (parent's original 100 + children's 100 = 200 instead of 100).
                'received_qty' => max(0.0, (float) $locked->received_qty - $totalSplitQty),
                'status' => $parentDepleted
                    ? MaterialLotStatusEnum::Depleted->value
                    : MaterialLotStatusEnum::Active->value,
            ]);

            // Reconcile the parent's per-lot stock_level to the post-split
            // remainder. The split moved qty out into child lots, so the
            // parent row must shrink by exactly that amount — otherwise the
            // stock ledger over-counts (the depleted parent keeps showing its
            // pre-split quantity) and totals drift from physical reality.
            $this->setLotStockLevel(
                warehouseId: $locked->warehouse_id,
                materialId: $locked->material_id,
                lotId: $locked->id,
                quantity: max(0.0, $remainingQty),
                unit: $locked->unit,
            );

            // plan-040 NEW-LOT-5: emit balanced StockMovement rows for the split.
            // One `out` on the parent lot (qty_before → remainder) plus the `in`
            // rows collected per child, all tied to one backing StockTransaction
            // so the stock_movements FK is satisfied and replaying movements
            // reconciles to the stock_levels setLotStockLevel just wrote.
            if ($splitMovements !== [] || $totalSplitQty > 0) {
                array_unshift($splitMovements, [
                    'material_id' => (string) $locked->material_id,
                    'material_lot_id' => (string) $locked->id,
                    'warehouse_id' => (string) $locked->warehouse_id,
                    'movement_type' => 'out',
                    'quantity' => (float) $totalSplitQty,
                    'quantity_before' => $parentQtyBefore,
                    'quantity_after' => max(0.0, $remainingQty),
                    'unit' => $locked->unit,
                ]);

                $this->stockTransactionService->recordSplitMovements([
                    'organization_id' => (string) $locked->organization_id,
                    'warehouse_id' => (string) $locked->warehouse_id,
                    'reference_type' => 'material_lot',
                    'reference_id' => (string) $locked->id,
                    'created_by_id' => $createdById,
                    'note' => "Lot split of {$locked->lot_code}",
                    'movements' => $splitMovements,
                ]);
            }

            $locked->logAudit('split', [
                'parts' => count($parts),
                'reason' => $reason,
                'split_qty' => $totalSplitQty,
                'remaining_qty' => max(0.0, $remainingQty),
                'children' => array_map(fn ($c) => $c->id, $children),
            ]);

            return [
                'parent' => $locked->fresh(),
                'children' => array_map(fn ($c) => $c->fresh(), $children),
            ];
        });
    }

    /**
     * Set the per-lot stock_levels row for (warehouse, material, lot) to an
     * exact quantity, creating it if absent. Per-lot stock_levels are an
     * invariant mirror of `MaterialLot::qty_on_hand`; "set" (not increment)
     * keeps them in lock-step and self-heals any prior drift. The row is
     * locked for update to serialize against concurrent consumption.
     */
    private function setLotStockLevel(string $warehouseId, string $materialId, string $lotId, float $quantity, ?string $unit): void
    {
        $level = StockLevel::where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->where('material_lot_id', $lotId)
            ->lockForUpdate()
            ->first();

        if ($level !== null) {
            $level->update(['quantity' => $quantity]);

            return;
        }

        StockLevel::create([
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'material_lot_id' => $lotId,
            'quantity' => $quantity,
            'unit' => $unit,
            // plan-040 TF.7 (NEW-STK-7): default alerts ON on auto-created
            // StockLevel rows so a configured min_stock can fire low-stock
            // alerts (was hard-coded false, silently muting them).
            'alert_enabled' => true,
        ]);
    }

    /**
     * Return qty back to a lot (e.g. production leftover or customer return).
     * Lot must be active or depleted. Depleted lots revert to active.
     *
     * plan-018 audit fix (Finding 3) — the shipped version bumped qty_on_hand
     * and logged an audit line but wrote NO StockTransaction, so the movement
     * had no ledger entry and the lot's StockLevel row drifted below its
     * qty_on_hand. Mirror `receive()`: raise a `stock_in` transaction with
     * `sub_type=return` against the lot so the movement is auditable and the
     * StockLevel re-syncs (stock_in never re-touches qty_on_hand — that is set
     * here — so the qty is not double counted).
     */
    public function returnQty(MaterialLot $lot, float $qty, string $reason, ?string $createdById = null): MaterialLot
    {
        return DB::transaction(function () use ($lot, $qty, $reason, $createdById) {
            $locked = MaterialLot::lockForUpdate()->findOrFail($lot->id);

            $this->assertStatus($locked, [MaterialLotStatusEnum::Active, MaterialLotStatusEnum::Depleted], 'return');

            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    'qty' => 'Return qty must be positive.',
                ]);
            }

            // plan-040 L2 / plan-018 Finding 3 — a lot can never hold more than
            // was received into it. Because qty_on_hand only ever drops via
            // consumption, `received_qty - qty_on_hand` equals the net quantity
            // consumed-and-not-yet-returned, so this ceiling also enforces the
            // design's "cannot return more than was consumed from this lot"
            // guard rather than silently dropping it.
            $newQty = (float) $locked->qty_on_hand + $qty;

            if ($newQty > (float) $locked->received_qty) {
                throw ValidationException::withMessages([
                    'qty' => "Return would push qty_on_hand ({$newQty}) above the lot's received_qty ({$locked->received_qty}).",
                ]);
            }

            $newStatus = $newQty > 0 ? MaterialLotStatusEnum::Active->value : $locked->status;

            $locked->update([
                'qty_on_hand' => $newQty,
                'status' => $newStatus,
            ]);

            $locked->logAudit('returned', [
                'qty_returned' => $qty,
                'new_qty_on_hand' => $newQty,
                'reason' => $reason,
            ]);

            // Ledger entry — auditable stock_in that re-syncs the lot StockLevel.
            $transaction = $this->stockTransactionService->create([
                'organization_id' => $locked->organization_id,
                'warehouse_id' => $locked->warehouse_id,
                'type' => 'stock_in',
                'sub_type' => 'return',
                'reference_type' => 'material_lot',
                'reference_id' => $locked->id,
                'note' => sprintf('Return to lot %s: %s', $locked->lot_code, $reason),
                'created_by_id' => $createdById,
                'items' => [[
                    'material_id' => $locked->material_id,
                    'material_lot_id' => $locked->id,
                    'quantity' => $qty,
                    'unit' => $locked->unit,
                    'unit_price' => $locked->unit_cost,
                ]],
            ]);

            $this->stockTransactionService->submit($transaction);

            return $locked->fresh();
        });
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function assertCrossBrand(Material $material, Warehouse $warehouse): void
    {
        // Branch links to brand via `console_brand_id` (legacy static UUID),
        // so we must resolve to the actual `brands.id` (UUID v7) via the
        // Branch::brand() relation before comparing with `material.brand_id`.
        $warehouseBrandId = $warehouse->branch?->brand?->id;

        if ($warehouseBrandId === null) {
            return;
        }

        if ((string) $warehouseBrandId !== (string) $material->brand_id) {
            throw ValidationException::withMessages([
                'warehouse_id' => sprintf(
                    'Cross-brand mismatch: material brand_id=%s but warehouse branch brand=%s.',
                    $material->brand_id,
                    $warehouseBrandId,
                ),
            ]);
        }
    }

    private function assertUnitBelongsToMaterial(Material $material, string $unit): void
    {
        if ($material->materialUnits->isEmpty()) {
            return;
        }

        $allowed = $material->materialUnits->pluck('unit')->all();

        if (! in_array($unit, $allowed, true)) {
            throw ValidationException::withMessages([
                'unit' => sprintf(
                    "Unit '%s' is not registered for material %s. Allowed: %s",
                    $unit,
                    $material->sku ?? $material->id,
                    implode(', ', $allowed),
                ),
            ]);
        }
    }

    private function resolveBaseUnit(Material $material): string
    {
        $base = $material->materialUnits->firstWhere('is_base', true);

        return $base?->unit ?? 'unit';
    }

    private function generateLotCode(Material $material, \DateTimeInterface|string $receivedAt, string $organizationId): string
    {
        $sku = strtoupper((string) ($material->sku ?? 'GEN'));
        $date = $receivedAt instanceof \DateTimeInterface
            ? $receivedAt->format('Ymd')
            : date('Ymd', (int) strtotime((string) $receivedAt));

        $prefix = "L-{$sku}-{$date}-";
        // plan-040 L1: lock the per-(org, material, day-prefix) rows so concurrent
        // receives serialize on the max read and cannot mint duplicate lot codes.
        // (value() can't carry the lock, so select the row then read the column.)
        $last = MaterialLot::where('organization_id', $organizationId)
            ->where('lot_code', 'like', "{$prefix}%")
            ->orderByDesc('lot_code')
            ->lockForUpdate()
            ->first()?->lot_code;

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  MaterialLotStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(MaterialLot $lot, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn (MaterialLotStatusEnum $s) => $s->value, $allowedStatuses);
        $currentValue = $lot->status instanceof MaterialLotStatusEnum
            ? $lot->status->value
            : (string) $lot->status;

        if (! in_array($currentValue, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: lot status is '{$currentValue}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }
}
