<?php

namespace App\Services\Inventory;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\MaterialBatchStatusEnum;
use App\Omnify\Enums\MaterialLotSourceEnum;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Omnify\Enums\StockTransactionStatusEnum;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Services\Inventory\Concerns\AssertsWarehouseOrganization;
use App\Services\Product\Contracts\RecipeDirectory;
use App\Services\Product\Contracts\RecipeSnapshot;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialBatchService
{
    /**
     * #1567 — cổng đọc công thức của Catalog.
     *
     * Resolve qua container thay vì tiêm constructor: class này được `new` trực
     * tiếp ở vài chỗ test và command, nên thêm tham số constructor là đổi mọi
     * chỗ đó cho một lý do không liên quan.
     */
    private function recipes(): RecipeDirectory
    {
        return app(RecipeDirectory::class);
    }

    use AssertsWarehouseOrganization;

    public function __construct(
        private readonly StockTransactionService $transactionService,
        private readonly GenealogyLinkService $genealogyLinkService,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, warehouse_id?: string, status?: string, date_from?: string, date_to?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = MaterialBatch::query()
            ->with(['warehouse', 'material'])
            ->withCount('items');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

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
            $q->where('batch_code', 'like', "%{$search}%");
        });

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): MaterialBatch
    {
        return MaterialBatch::with([
            'warehouse',
            'items.productSku',
            'items.material',
            'recipe',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): MaterialBatch
    {
        $this->assertWarehousesBelongToOrganization(
            $data['organization_id'] ?? null,
            [$data['warehouse_id'] ?? null],
        );

        return DB::transaction(function () use ($data) {
            $data['batch_code'] = $this->generateCode($data['organization_id']);
            $data['status'] = MaterialBatchStatusEnum::Draft->value;

            // Plan-022 T3.5 — Recipe is now an explicit input on batch
            // creation. When the caller omits recipe_id we still fall back to
            // "latest active recipe for material" (legacy behaviour), but
            // resolveRecipeForBatch() asserts: belongs to this material,
            // is_active, approved. The chosen recipe is snapshotted on the
            // batch row so audit can trace which BOM revision was used.
            $recipe = $this->resolveRecipeForBatch(
                materialId: $data['material_id'],
                recipeId: $data['recipe_id'] ?? null,
            );
            $data['recipe_id'] = $recipe->id;

            $items = $data['items'] ?? [];
            unset($data['items']);

            $batch = MaterialBatch::create($data);

            // If the caller didn't supply explicit items, expand them from
            // the resolved Recipe × multiplier. This is the common path —
            // shops just pick a material + recipe + multiplier and expect the
            // BOM to materialise automatically. Custom items (rare: ad-hoc
            // batches diverging from the snapshot) flow through when the
            // caller sends `items` explicitly.
            if (empty($items)) {
                $items = $this->expandRecipeIngredients(
                    recipe: $recipe,
                    multiplier: (float) ($data['multiplier'] ?? 1.0),
                );
            }

            foreach ($items as $itemData) {
                $batch->items()->create($itemData);
            }

            return $this->loadRelations($batch);
        });
    }

    /**
     * Plan-022 T3.5 — resolve the Recipe to snapshot on a new batch.
     *
     * If the caller supplied `recipe_id`, validate ownership (must belong to
     * the same material) and gating (is_active + approved). If omitted, fall
     * back to "latest active+approved recipe for this material" so legacy
     * callers (Plan017DemoSeeder, tests written before T3.5) keep working.
     *
     * Throws ValidationException with field-scoped messages so the form can
     * highlight the offending input (recipe_id vs material_id).
     */
    private function resolveRecipeForBatch(string $materialId, ?string $recipeId): RecipeSnapshot
    {
        $material = Material::find($materialId);
        if ($material === null) {
            throw ValidationException::withMessages([
                'material_id' => 'Material not found.',
            ]);
        }

        if ($recipeId !== null && $recipeId !== '') {
            $recipe = $this->recipes()->find($recipeId);

            if ($recipe === null) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'Recipe not found.',
                ]);
            }

            if ((string) $recipe->materialId !== (string) $materialId) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'Recipe does not belong to the selected material.',
                ]);
            }

            if (! $recipe->isActive) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'Recipe is inactive — pick an active recipe or activate this one first.',
                ]);
            }

            $status = $recipe->approvalStatus;

            if ($status !== ApprovalStatusEnum::Approved) {
                throw ValidationException::withMessages([
                    'recipe_id' => sprintf(
                        'Recipe must be approved before batching (current: %s).',
                        $status->value,
                    ),
                ]);
            }

            return $recipe;
        }

        // No explicit recipe_id — fall back to latest active+approved recipe.
        $recipe = $this->recipes()->latestActiveApprovedForMaterial($materialId);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'material_id' => sprintf(
                    'Material %s has no active approved recipe — create or approve one in HQ admin before batching.',
                    $material->sku ?? $materialId,
                ),
            ]);
        }

        return $recipe;
    }

    /**
     * Plan-022 T3.5 — expand a snapshotted Recipe into MaterialBatchItem rows,
     * scaled by the batch multiplier. Recipe.ingredients (NOT Material.components)
     * is the canonical BOM source after T4.
     *
     * Ingredient shape (per Recipe.yaml + RecipeService::normalizeIngredients):
     *   {type: "material"|"variant"|"raw", material_id|variant_id, quantity, unit}
     *
     * @return array<int, array{component_type: string, product_sku_id: ?string, material_id: ?string, planned_quantity: float, unit: ?string}>
     */
    private function expandRecipeIngredients(RecipeSnapshot $recipe, float $multiplier): array
    {
        $ingredients = $recipe->ingredients;
        if ($ingredients === []) {
            return [];
        }

        $outputQty = $recipe->outputQuantity ?: 1.0;
        $scale = $multiplier / max($outputQty, 1e-9);

        $items = [];
        foreach ($ingredients as $ingredient) {
            $type = $ingredient['type'] ?? 'material';
            $qty = (float) ($ingredient['quantity'] ?? $ingredient['qty'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            // Variants stay as-is; raw + material both map onto the material
            // pipeline so FEFO can pick lots for them.
            $componentType = $type === 'variant' ? 'variant' : 'material';

            $unit = $ingredient['unit'] ?? null;
            if ($componentType === 'material' && $unit === null && ! empty($ingredient['material_id'])) {
                $childMaterial = Material::with('materialUnits:id,material_id,unit,is_base')
                    ->find($ingredient['material_id']);
                $unit = $childMaterial?->materialUnits->firstWhere('is_base', true)?->unit
                    ?? $childMaterial?->yield_unit;
            }

            $items[] = [
                'component_type' => $componentType,
                'product_sku_id' => $componentType === 'variant' ? ($ingredient['variant_id'] ?? null) : null,
                'material_id' => $componentType === 'material' ? ($ingredient['material_id'] ?? null) : null,
                'planned_quantity' => $qty * $scale,
                'unit' => $unit,
            ];
        }

        return $items;
    }

    /**
     * Plan-022 T3.2/T3.3/T3.5 — guard the **snapshotted** recipe at gate
     * transitions (submit, approve). The recipe id is now stored on the batch
     * row, so we re-check the exact revision the batch will use rather than
     * "latest active" — the latter would silently swap recipes mid-workflow.
     *
     * Falls back to the legacy "material → latest active recipe" lookup when
     * batch.recipe_id is null (pre-T3.5 batches that haven't been backfilled).
     */
    private function assertRecipeApproved(MaterialBatch $batch): void
    {
        if ($batch->recipe_id !== null) {
            $recipe = $this->recipes()->find((string) $batch->recipe_id);

            if ($recipe === null) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'Snapshotted recipe no longer exists — recreate the batch.',
                ]);
            }

            if (! $recipe->isActive) {
                throw ValidationException::withMessages([
                    'recipe_id' => 'Snapshotted recipe is inactive — reactivate it or recreate the batch with an active recipe.',
                ]);
            }
        } else {
            $recipe = $this->recipes()->latestActiveForMaterial((string) $batch->material_id);

            if ($recipe === null) {
                throw ValidationException::withMessages([
                    'material_id' => 'Material does not have an active recipe.',
                ]);
            }
        }

        $status = $recipe->approvalStatus;

        if ($status !== ApprovalStatusEnum::Approved) {
            throw ValidationException::withMessages([
                'recipe' => sprintf(
                    'Recipe must be approved before batch can proceed (current: %s).',
                    $status->value,
                ),
            ]);
        }
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(MaterialBatch $batch, array $data): MaterialBatch
    {
        $this->assertStatus($batch, [MaterialBatchStatusEnum::Draft], 'update');

        return DB::transaction(function () use ($batch, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);

            // plan-040 NEW-BP-4 (TH.9): detect a multiplier change BEFORE the
            // update so we can re-expand the BOM when the operator bumps the
            // multiplier without re-sending items.
            $multiplierChanged = array_key_exists('multiplier', $data)
                && (float) $data['multiplier'] !== (float) ($batch->multiplier ?? 1.0);

            $batch->update($data);

            if ($items !== null) {
                // Explicit items always win (ad-hoc batch overriding the BOM).
                $batch->items()->delete();

                foreach ($items as $itemData) {
                    $batch->items()->create($itemData);
                }
            } elseif ($multiplierChanged && $batch->recipe_id !== null) {
                // plan-040 NEW-BP-4 (TH.9): a multiplier change alone must
                // re-expand the BOM from the snapshotted recipe, otherwise
                // complete() would consume the stale (pre-change) item
                // quantities — silently scaling consumption wrong.
                $recipe = $this->recipes()->find((string) $batch->recipe_id);
                if ($recipe !== null) {
                    $batch->items()->delete();
                    $expanded = $this->expandRecipeIngredients(
                        recipe: $recipe,
                        multiplier: (float) ($batch->multiplier ?? 1.0),
                    );
                    foreach ($expanded as $itemData) {
                        $batch->items()->create($itemData);
                    }
                }
            }

            return $this->loadRelations($batch);
        });
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    public function delete(MaterialBatch $batch): bool
    {
        $this->assertStatus($batch, [
            MaterialBatchStatusEnum::Draft,
            MaterialBatchStatusEnum::Cancelled,
        ], 'delete');

        return DB::transaction(function () use ($batch) {
            $batch->items()->delete();

            return $batch->delete();
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submit(MaterialBatch $batch): MaterialBatch
    {
        $this->assertStatus($batch, [MaterialBatchStatusEnum::Draft], 'submit');

        if ($batch->items()->count() === 0) {
            throw new InvalidStatusTransitionException(
                'Cannot submit: material batch must have at least one item.'
            );
        }

        // Plan-022 T3.2 — guard recipe approval at the gate. The recipe could
        // have been approved at create() then un-approved before submit, so
        // re-check here.
        $this->assertRecipeApproved($batch);

        $warehouse = $batch->warehouse ?? Warehouse::findOrFail($batch->warehouse_id);

        if ($warehouse->auto_approve_batch) {
            $batch->update([
                'status' => MaterialBatchStatusEnum::Approved->value,
                'approved_at' => now(),
            ]);

            return $this->loadRelations($batch);
        }

        $batch->update([
            'status' => MaterialBatchStatusEnum::Pending->value,
        ]);

        return $this->loadRelations($batch);
    }

    public function approve(MaterialBatch $batch, string $approverId): MaterialBatch
    {
        $this->assertStatus($batch, [MaterialBatchStatusEnum::Pending], 'approve');

        // Plan-022 T3.2 — defence in depth. Recipe approval can be revoked
        // between submit and approve.
        $this->assertRecipeApproved($batch);

        $batch->update([
            'status' => MaterialBatchStatusEnum::Approved->value,
            'approved_by_id' => $approverId,
            'approved_at' => now(),
        ]);

        return $this->loadRelations($batch);
    }

    public function start(MaterialBatch $batch): MaterialBatch
    {
        $this->assertStatus($batch, [MaterialBatchStatusEnum::Approved], 'start');

        return DB::transaction(function () use ($batch) {
            // Plan-017 T3.5 — preview-stamp the FEFO-picked material_lot_id
            // on each material-typed MaterialBatchItem. The stamp is best-
            // effort (the actual FEFO pick happens again at complete() to
            // serialize against concurrent batches), but it gives the
            // production batch detail UI a concrete "we'll consume from
            // these lots" preview while the batch is in_progress.
            //
            // For each material item without a manually set material_lot_id,
            // pick the FEFO-oldest active lot in the warehouse with enough
            // qty_on_hand to cover planned_quantity. We don't decrement the
            // lot here — that's still complete()'s job via FEFO + lockForUpdate.
            $items = $batch->items()->where('component_type', 'material')->get();

            foreach ($items as $item) {
                if ($item->material_lot_id !== null || ! $item->material_id) {
                    continue;
                }

                // Plan-022 T9.1 — multi-lot FEFO preview. Replace single-lot
                // `firstWhere(qty>=planned)` (which returned NULL when no
                // single lot covered the item, even if total stock did) with
                // a greedy walk via previewLotsForConsumption. The first lot
                // is stamped on the original item; remaining lots are stored
                // as JSON metadata so the production batch detail UI can
                // render the planned split. The actual lock + decrement
                // still happens in complete() via pickLotsForConsumption.
                $allocations = $this->transactionService->previewLotsForConsumption(
                    materialId: (string) $item->material_id,
                    warehouseId: (string) $batch->warehouse_id,
                    qtyNeeded: (float) ($item->planned_quantity ?? 0),
                    unit: $item->unit,
                );

                $primary = collect($allocations)
                    ->first(fn ($a) => $a['material_lot_id'] !== null);

                if ($primary !== null) {
                    $item->update(['material_lot_id' => $primary['material_lot_id']]);
                }
            }

            $batch->update([
                'status' => MaterialBatchStatusEnum::InProgress->value,
                'started_at' => now(),
            ]);

            return $this->loadRelations($batch);
        });
    }

    public function complete(MaterialBatch $batch, string $completedById, ?float $actualYield = null, ?string $varianceReason = null): MaterialBatch
    {
        $this->assertStatus($batch, [MaterialBatchStatusEnum::InProgress], 'complete');

        // Plan-022 (yield variance) — when actual yield drifts beyond the
        // recipe's tolerance %, the shop must supply a reason. Recipe is
        // looked up via batch.recipe_id when present, otherwise via
        // batch.material_id → most recently approved recipe. tolerance=0
        // (default) means any non-zero drift requires a reason; HQ raises
        // it on the recipe to skip the prompt for small spoilage.
        $plannedYield = (float) ($batch->planned_yield ?? 0);
        $proposedYield = $actualYield ?? $plannedYield;
        $tolerancePct = $this->resolveVarianceTolerancePct($batch);
        // plan-040 M8 (TH.6): measure variance against the recipe baseline
        // (output_quantity × multiplier), not the operator's free-typed
        // planned_yield — a mistyped planned_yield must not mask a real yield
        // loss. Fall back to planned_yield only when no recipe baseline is
        // resolvable (legacy batches with no recipe link).
        $baselineYield = $this->resolveBaselineYield($batch);
        $referenceYield = $baselineYield > 0 ? $baselineYield : $plannedYield;
        $variancePct = $referenceYield > 0
            ? abs($proposedYield - $referenceYield) / $referenceYield * 100
            : ($proposedYield > 0 ? 100.0 : 0.0);
        $varianceReason = $varianceReason !== null ? trim($varianceReason) : null;

        if ($variancePct > $tolerancePct && ($varianceReason === null || $varianceReason === '')) {
            $tolStr = rtrim(rtrim(number_format($tolerancePct, 2), '0'), '.');
            $varStr = rtrim(rtrim(number_format($variancePct, 2), '0'), '.');
            throw ValidationException::withMessages([
                'yield_variance_reason' => [
                    "Actual yield deviates {$varStr}% from planned (tolerance: {$tolStr}%). A reason is required.",
                ],
            ]);
        }

        // Clear reason when within tolerance — keeps audit data honest. The
        // operator may have typed something, but if BE doesn't need it the
        // row shouldn't carry spurious text.
        if ($variancePct <= $tolerancePct) {
            $varianceReason = null;
        }

        return DB::transaction(function () use ($batch, $completedById, $actualYield, $varianceReason) {
            $batch->load('items');

            // Stamp the consumed amount onto each component. complete() has no
            // per-item actual input, so the consumed quantity equals the
            // operator-set actual_quantity when present, else planned_quantity
            // (which is exactly what the stock_out below drains). Persisting it
            // here keeps the batch detail "actual qty" column truthful instead
            // of showing a perpetual "—" after a successful completion.
            foreach ($batch->items as $item) {
                if ($item->actual_quantity === null) {
                    $item->update(['actual_quantity' => $item->planned_quantity]);
                }
            }

            // Create stock_out for input items (component_type = 'material' or 'variant')
            $inputItems = $batch->items->map(fn ($item) => [
                'product_sku_id' => $item->product_sku_id,
                'material_id' => $item->material_id,
                'quantity' => (float) ($item->actual_quantity ?? $item->planned_quantity),
                'unit' => $item->unit,
            ])->toArray();

            if (! empty($inputItems)) {
                $stockOut = $this->transactionService->create([
                    'organization_id' => $batch->organization_id,
                    'warehouse_id' => $batch->warehouse_id,
                    'type' => StockTransactionTypeEnum::StockOut->value,
                    'sub_type' => StockTransactionSubTypeEnum::Production->value,
                    'reference_type' => 'material_batch',
                    'reference_id' => $batch->id,
                    'created_by_id' => $completedById,
                    'note' => "Material consumption for batch {$batch->batch_code}",
                    'items' => $inputItems,
                ]);
                $this->transactionService->submit($stockOut);
                $stockOut->refresh();

                if ($stockOut->status === 'pending') {
                    $this->transactionService->approve($stockOut, $completedById);
                }

                $batch->stock_out_transaction_id = $stockOut->id;
            }

            // Mint the production output lot. Snapshots the UNION of
            // effective_allergens from every consumed lot (read directly off
            // stock_out_transaction_items, which T3.3 FEFO has now stamped
            // with material_lot_id). Allergen drift vs the master Material's
            // allergen set surfaces as a flag on the lot detail page —
            // SafetyEvent integration ships in plan-019 (HACCP).
            $yieldQty = $actualYield ?? (float) $batch->planned_yield;
            $material = Material::with(['allergens:id', 'materialUnits:id,material_id,unit,is_base,ratio'])
                ->findOrFail($batch->material_id);

            // Plan-022 T1.4 — production output lot is normalised to the
            // material's base unit just like inbound lots. The operator-facing
            // yield value (e.g. 9.5 L) is preserved on entered_quantity /
            // entered_unit; qty_on_hand / received_qty / unit are the
            // canonical-grain values (e.g. 9500 ml). When the material has no
            // material_units row at all, fall back to batch.yield_unit (legacy).
            $baseUnitRow = $material->materialUnits->firstWhere('is_base', true);
            $outputUnit = $baseUnitRow?->unit ?? $batch->yield_unit;
            $outputQtyBase = $baseUnitRow !== null && $batch->yield_unit !== null
                ? $this->transactionService->calculateBaseQuantity(
                    $yieldQty,
                    $batch->yield_unit,
                    $batch->material_id,
                )
                : $yieldQty;

            // Plan-022 T5.2 — pick up the existing stock_out transaction id
            // (set by start()) when the local $stockOut doesn't exist. Without
            // this, consumedLots is always empty for the start→complete path
            // and parent-min expiry never participates in resolution.
            $consumedTxnId = isset($stockOut) ? $stockOut->id : $batch->stock_out_transaction_id;

            $consumedItems = $consumedTxnId !== null
                ? StockTransactionItem::query()
                    ->where('stock_transaction_id', $consumedTxnId)
                    ->whereNotNull('material_lot_id')
                    ->get(['material_lot_id', 'base_quantity', 'unit', 'cost_basis_amount'])
                : collect();

            $consumedLots = $consumedItems->isEmpty()
                ? collect()
                : MaterialLot::query()
                    ->whereIn('id', $consumedItems->pluck('material_lot_id'))
                    ->get(['id', 'effective_allergens', 'unit', 'unit_cost', 'currency', 'expiry_date']);

            $allergenUnion = $consumedLots
                ->flatMap(fn (MaterialLot $lot) => $lot->effective_allergens ?? [])
                ->unique()
                ->values()
                ->all();

            // Fall back to master allergens when no consumed lots fed in
            // (e.g. legacy stock case before plan-017 migration).
            if (empty($allergenUnion)) {
                $allergenUnion = $material->allergens->pluck('id')->all();
            }

            // Plan-022 T5.2 — production lot expiry resolution.
            // Take the earliest non-null of:
            //   - operator override (batch.expiry_date set on the form)
            //   - shelf-life policy (today + material.shelf_life_days)
            //   - parent min expiry (the consumed lot that expires soonest)
            // Short-life BTPs (dashi 3h, sauce 2 days) need this for FEFO +
            // ExpiryAlertService to see them.
            // #1091 — shelf life counts from the SHOP's day; a batch produced
            // 08:00 in Tokyo must not inherit yesterday's expiry.
            // The shop's local midnight, NOT its UTC equivalent: the dates below
            // are compared and stored as calendar dates, so the instant has to
            // stay in the branch's frame or `+3 days` lands a day early for any
            // shop east of UTC.
            $branchId = $batch->branch_id === null ? null : (string) $batch->branch_id;
            $today = Carbon::instance(BusinessClock::now($branchId)->startOfDay());
            $candidates = [];

            if ($batch->expiry_date !== null) {
                $candidates[] = Carbon::parse($batch->expiry_date)->startOfDay();
            }

            if (! empty($material->shelf_life_days)) {
                $candidates[] = $today->copy()->addDays((int) $material->shelf_life_days);
            }

            $parentMinExpiry = $consumedLots
                ->whereNotNull('expiry_date')
                ->map(fn ($lot) => Carbon::parse($lot->expiry_date)->startOfDay())
                ->min();
            if ($parentMinExpiry !== null) {
                $candidates[] = $parentMinExpiry;
            }

            $outputExpiry = $candidates === [] ? null : min($candidates)->toDateString();

            // Plan-017 Tier 1.B — weighted-average unit_cost for the output
            // lot. Sums every consumed item's cost_basis_amount (already
            // = lot.unit_cost × qty), divides by yield. NULL when no
            // consumed item carried a cost basis (legacy stock).
            // plan-040 H8 (TH.5): ¥ and $ are not additive — a cross-currency
            // cost basis cannot be summed bare. Assert a single currency across
            // the consumed lots and reject the completion on a mismatch rather
            // than minting a meaningless blended unit_cost.
            $consumedCurrencies = $consumedLots
                ->pluck('currency')
                ->filter()
                ->unique()
                ->values();
            if ($consumedCurrencies->count() > 1) {
                throw ValidationException::withMessages([
                    'currency' => sprintf(
                        'Cannot complete batch: consumed lots span multiple currencies (%s). Normalise costs to one currency first.',
                        $consumedCurrencies->implode(', '),
                    ),
                ]);
            }

            $totalConsumedCost = $consumedItems->sum(
                fn ($item) => $item->cost_basis_amount !== null ? (float) $item->cost_basis_amount : 0.0
            );
            // plan-040 H8 (TH.5): a null lot cost is "unknown", NOT zero —
            // treating it as 0 silently dilutes the output unit_cost. Only
            // derive a cost when EVERY consumed item carries a cost basis;
            // otherwise leave unit_cost null (cost unknown) instead of wrong.
            $allHaveCost = $consumedItems->isNotEmpty()
                && $consumedItems->every(fn ($item) => $item->cost_basis_amount !== null);
            // Plan-022 T1.4 — divide consumed cost by base-unit yield so the
            // resulting unit_cost is "$ per base unit" (consistent with inbound
            // lots). Avoids ledger drift when batch.yield_unit ≠ base unit.
            $outputUnitCost = ($allHaveCost && $outputQtyBase > 0)
                ? round($totalConsumedCost / $outputQtyBase, 4)
                : null;
            $outputCurrency = $consumedCurrencies->first();

            $outputLot = MaterialLot::create([
                'lot_code' => $batch->batch_code,
                'material_id' => $batch->material_id,
                'warehouse_id' => $batch->warehouse_id,
                'source' => MaterialLotSourceEnum::Production->value,
                'status' => MaterialLotStatusEnum::Active->value,
                'received_at' => now(),
                'expiry_date' => $outputExpiry,
                'received_qty' => $outputQtyBase,
                'qty_on_hand' => $outputQtyBase,
                'unit' => $outputUnit,
                'entered_quantity' => $yieldQty,
                'entered_unit' => $batch->yield_unit,
                'unit_cost' => $outputUnitCost,
                'total_cost' => $outputUnitCost !== null ? $outputUnitCost * $outputQtyBase : null,
                'currency' => $outputCurrency,
                'cost_basis' => $outputUnitCost !== null ? 'production_calculated' : null,
                'effective_allergens' => $allergenUnion,
                'produced_by_batch_id' => $batch->id,
                'organization_id' => $batch->organization_id,
                'brand_id' => $material->brand_id,
            ]);
            $outputLot->logAudit('produced', [
                'batch_id' => $batch->id,
                'yield_qty' => $yieldQty,
                'yield_unit' => $batch->yield_unit,
                'base_qty' => $outputQtyBase,
                'base_unit' => $outputUnit,
            ]);

            // Create stock_in tagged with the new output lot's id so the
            // resulting stock_levels row lands at (warehouse, material, lot)
            // grain rather than the legacy NULL bucket.
            $stockIn = $this->transactionService->create([
                'organization_id' => $batch->organization_id,
                'warehouse_id' => $batch->warehouse_id,
                'type' => StockTransactionTypeEnum::StockIn->value,
                'sub_type' => StockTransactionSubTypeEnum::Production->value,
                'reference_type' => 'material_batch',
                'reference_id' => $batch->id,
                'created_by_id' => $completedById,
                'note' => "Material output for batch {$batch->batch_code}",
                'items' => [
                    [
                        'material_id' => $batch->material_id,
                        'material_lot_id' => $outputLot->id,
                        'quantity' => $outputQtyBase,
                        'unit' => $outputUnit,
                    ],
                ],
            ]);
            $this->transactionService->submit($stockIn);
            $stockIn->refresh();

            if ($stockIn->status === 'pending') {
                $this->transactionService->approve($stockIn, $completedById);
            }

            // Genealogy edges — one per consumed lot → output lot. Each row
            // is grain-of-truth for FSMA-204 trace queries.
            foreach ($consumedItems as $consumedItem) {
                $parentLot = $consumedLots->firstWhere('id', $consumedItem->material_lot_id);
                if ($parentLot === null) {
                    continue;
                }
                $this->genealogyLinkService->recordProductionConsumption(
                    parentLot: $parentLot,
                    childLot: $outputLot,
                    qtyConsumed: (float) $consumedItem->base_quantity,
                    unit: $consumedItem->unit,
                    batchId: (string) $batch->id,
                );
            }

            $batch->update([
                'status' => MaterialBatchStatusEnum::Completed->value,
                'actual_yield' => $yieldQty,
                'yield_variance_reason' => $varianceReason,
                'stock_out_transaction_id' => $batch->stock_out_transaction_id,
                'stock_in_transaction_id' => $stockIn->id,
                'output_lot_id' => $outputLot->id,
                'completed_at' => now(),
            ]);

            return $this->loadRelations($batch);
        });
    }

    /**
     * Resolve the variance tolerance % from the recipe the batch was built
     * against. Prefers batch.recipe_id (explicit selection on create), falls
     * back to the most recently approved recipe for the material when the
     * batch was created before recipe_id became required. Returns 0 when no
     * recipe is found — any drift then requires a reason, matching the
     * "strictest possible" default.
     */
    private function resolveVarianceTolerancePct(MaterialBatch $batch): float
    {
        $recipe = null;
        if ($batch->recipe_id !== null) {
            $recipe = $this->recipes()->find((string) $batch->recipe_id);
        }
        if ($recipe === null && $batch->material_id !== null) {
            $recipe = $this->recipes()->latestApprovedForMaterial((string) $batch->material_id);
        }

        return $recipe?->yieldVarianceTolerancePct ?? 0.0;
    }

    /**
     * plan-040 M8 (TH.6): the expected yield baseline for variance = the
     * snapshotted recipe's `output_quantity × multiplier`. Resolves the recipe
     * via batch.recipe_id first, then falls back to the material's most recently
     * approved recipe. Returns 0 when no recipe is resolvable so the caller can
     * fall back to the operator-entered planned_yield (legacy batches).
     */
    private function resolveBaselineYield(MaterialBatch $batch): float
    {
        $recipe = null;
        if ($batch->recipe_id !== null) {
            $recipe = $this->recipes()->find((string) $batch->recipe_id);
        }
        if ($recipe === null && $batch->material_id !== null) {
            $recipe = $this->recipes()->latestApprovedForMaterial((string) $batch->material_id);
        }

        if ($recipe === null) {
            return 0.0;
        }

        return $recipe->outputQuantity * (float) ($batch->multiplier ?? 1);
    }

    public function cancel(MaterialBatch $batch, ?string $cancelledById = null): MaterialBatch
    {
        // Plan-022 T7.1 — Draft / Pending cancel is a status flip; InProgress
        // / Approved cancel must reverse any stock deduction the start →
        // FEFO path stamped on stock_out_transaction_items.
        $this->assertStatus($batch, [
            MaterialBatchStatusEnum::Draft,
            MaterialBatchStatusEnum::Pending,
            MaterialBatchStatusEnum::Approved,
            MaterialBatchStatusEnum::InProgress,
        ], 'cancel');

        return DB::transaction(function () use ($batch, $cancelledById) {
            $stockOutId = $batch->stock_out_transaction_id;

            if ($stockOutId !== null) {
                $consumedItems = StockTransactionItem::query()
                    ->where('stock_transaction_id', $stockOutId)
                    ->whereNotNull('material_lot_id')
                    ->get(['material_id', 'material_lot_id', 'base_quantity', 'unit', 'product_sku_id']);

                if ($consumedItems->isNotEmpty()) {
                    // Build a stock_in/adjustment_in transaction restoring each
                    // consumed lot to its pre-start qty. Routing through
                    // StockTransactionService keeps stock_levels +
                    // stock_movements in sync via the same code path that
                    // handles every other adjustment.
                    $reversal = $this->transactionService->create([
                        'organization_id' => $batch->organization_id,
                        'warehouse_id' => $batch->warehouse_id,
                        'type' => StockTransactionTypeEnum::StockIn->value,
                        'sub_type' => StockTransactionSubTypeEnum::AdjustmentIn->value,
                        'reference_type' => 'material_batch',
                        'reference_id' => $batch->id,
                        'created_by_id' => $cancelledById,
                        'note' => "Reversal of cancelled batch {$batch->batch_code}",
                        'items' => $consumedItems->map(fn ($it) => [
                            'material_id' => $it->material_id,
                            'product_sku_id' => $it->product_sku_id,
                            'material_lot_id' => $it->material_lot_id,
                            'quantity' => (float) $it->base_quantity,
                            'unit' => $it->unit,
                        ])->all(),
                    ]);
                    $this->transactionService->submit($reversal);
                    $reversal->refresh();
                    // plan-040 M1 (TD.5): compare against the enum case — the
                    // `status` column casts to StockTransactionStatusEnum, so the
                    // old `=== 'pending'` string check never matched and the
                    // reversal stayed un-approved when the warehouse had
                    // auto_approve_stock_in=false.
                    if ($cancelledById !== null && $reversal->status === StockTransactionStatusEnum::Pending) {
                        $this->transactionService->approve($reversal, $cancelledById);
                    }

                    // Append-only reversal genealogy edges. TraceService
                    // (T7.2) nets these against original consumption rows so
                    // recall blast radius excludes cancelled flows.
                    $consumedItems->each(function ($item) use ($batch) {
                        $parent = MaterialLot::find($item->material_lot_id);
                        if ($parent !== null) {
                            $this->genealogyLinkService->recordReversal(
                                parentLot: $parent,
                                childLot: null,
                                qtyConsumed: (float) $item->base_quantity,
                                unit: $item->unit,
                                sourceEventId: $batch->id,
                            );
                        }
                    });
                }

                $batch->stock_out_transaction_id = null;
            }

            $batch->status = MaterialBatchStatusEnum::Cancelled->value;
            $batch->save();

            return $this->loadRelations($batch);
        });
    }

    /**
     * Plan-017 Tier 1.B — return the consumed-lot cost breakdown for a
     * completed batch. Reads the stock_out_transaction_items written by
     * FEFO during complete(), each carrying material_lot_id +
     * cost_basis_amount, joins back to the lot for unit_cost / lot_code /
     * supplier metadata.
     *
     * Used by the production-batch detail UI to show:
     *   "10 kg of L-FLOUR-001 × ¥100 = ¥1,000 + 5 kg of L-FLOUR-002
     *   × ¥200 = ¥1,000 → output ¥200/kg × 10 kg yield"
     *
     * Returns NULL if the batch hasn't completed yet (no stock_out).
     *
     * @return array{
     *   currency: ?string,
     *   total_consumed_cost: float,
     *   yield_qty: float,
     *   output_unit_cost: ?float,
     *   lines: array<int, array{
     *     lot_id: ?string,
     *     lot_code: ?string,
     *     supplier_name: ?string,
     *     supplier_lot_code: ?string,
     *     qty_consumed: float,
     *     unit: ?string,
     *     unit_cost: ?float,
     *     line_total: ?float
     *   }>
     * }|null
     */
    public function getCostBreakdown(MaterialBatch $batch): ?array
    {
        if (! $batch->stock_out_transaction_id) {
            return null;
        }

        $items = StockTransactionItem::query()
            ->where('stock_transaction_id', $batch->stock_out_transaction_id)
            ->whereNotNull('material_lot_id')
            ->get(['material_lot_id', 'base_quantity', 'unit', 'cost_basis_amount']);

        $lots = $items->isEmpty()
            ? collect()
            : MaterialLot::whereIn('id', $items->pluck('material_lot_id'))
                ->get(['id', 'lot_code', 'supplier_name', 'supplier_lot_code', 'unit_cost', 'currency'])
                ->keyBy('id');

        $lines = $items->map(function ($item) use ($lots) {
            $lot = $lots->get($item->material_lot_id);

            return [
                'lot_id' => (string) $item->material_lot_id,
                'lot_code' => $lot?->lot_code,
                'supplier_name' => $lot?->supplier_name,
                'supplier_lot_code' => $lot?->supplier_lot_code,
                'qty_consumed' => (float) $item->base_quantity,
                'unit' => $item->unit,
                'unit_cost' => $lot?->unit_cost !== null ? (float) $lot->unit_cost : null,
                'line_total' => $item->cost_basis_amount !== null
                    ? (float) $item->cost_basis_amount
                    : null,
            ];
        })->values()->all();

        $totalConsumed = $items->sum(
            fn ($item) => $item->cost_basis_amount !== null
                ? (float) $item->cost_basis_amount
                : 0.0
        );

        // plan-040 NEW-BP-1 (TH.7): normalise the yield through
        // calculateBaseQuantity exactly as complete() does before persisting
        // the lot unit_cost, so the breakdown panel's output_unit_cost matches
        // the stored lot unit_cost when yield_unit ≠ the material's base unit.
        $rawYield = (float) ($batch->actual_yield ?? $batch->planned_yield ?? 0);
        $baseUnitRow = Material::with('materialUnits:id,material_id,unit,is_base')
            ->find($batch->material_id)
            ?->materialUnits->firstWhere('is_base', true);
        $yieldQtyBase = ($baseUnitRow !== null && $batch->yield_unit !== null)
            ? $this->transactionService->calculateBaseQuantity($rawYield, $batch->yield_unit, $batch->material_id)
            : $rawYield;
        $outputUnitCost = $yieldQtyBase > 0 ? round($totalConsumed / $yieldQtyBase, 4) : null;

        return [
            'currency' => $lots->whereNotNull('currency')->first()?->currency,
            'total_consumed_cost' => $totalConsumed,
            'yield_qty' => $yieldQtyBase,
            'output_unit_cost' => $outputUnitCost,
            'lines' => $lines,
        ];
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function generateCode(string $organizationId): string
    {
        $prefix = 'MB';
        $date = now()->format('Ymd');
        $baseCode = "{$prefix}-{$date}-";

        // plan-040 L1: lock the per-(org, day-prefix) rows so concurrent creates
        // serialize on the max read and cannot mint duplicate batch codes.
        // #531 sub-bug: the suffix is 3-digit zero-padded, so a plain string
        // sort ranks "...-999" above "...-1000" — past the 999th doc/org/day it
        // re-mints an existing number. Order by LENGTH first (more digits = a
        // larger number) then lexically, so 1000+ sorts correctly.
        $last = MaterialBatch::where('organization_id', $organizationId)
            ->where('batch_code', 'like', "{$baseCode}%")
            ->orderByRaw('LENGTH(batch_code) DESC')
            ->orderBy('batch_code', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $last
            ? (int) str_replace($baseCode, '', $last->batch_code) + 1
            : 1;

        return $baseCode.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function loadRelations(MaterialBatch $batch): MaterialBatch
    {
        return $batch->load([
            'warehouse',
            'items.productSku',
            'items.material',
            // Plan-022 (yield variance) — Recipe is needed on the detail view
            // so the shop UI can compute the variance % client-side and prompt
            // for a reason before complete().
            'recipe',
        ])->loadCount('items');
    }

    /**
     * @param  MaterialBatchStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(MaterialBatch $batch, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn (MaterialBatchStatusEnum $s) => $s->value, $allowedStatuses);
        $currentValue = $batch->status instanceof MaterialBatchStatusEnum
            ? $batch->status->value
            : (string) $batch->status;

        if (! in_array($currentValue, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: material batch status is '{$currentValue}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }
}
