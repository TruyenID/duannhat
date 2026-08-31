<?php

namespace App\Services\Inventory;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Material;
use App\Models\ProductionOrder;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Omnify\Enums\ProductionOrderStatusEnum;
use App\Omnify\Enums\StockTransactionStatusEnum;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Services\Inventory\Concerns\AssertsWarehouseOrganization;
use App\Services\Product\Contracts\SkuDirectory;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionOrderService
{
    /** #1567 — cổng đọc SKU + công thức của Catalog. */
    private function skus(): SkuDirectory
    {
        return app(SkuDirectory::class);
    }

    use AssertsWarehouseOrganization;

    public function __construct(
        private readonly StockTransactionService $transactionService,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, warehouse_id?: string, status?: string, date_from?: string, date_to?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ProductionOrder::query()
            ->with(['warehouse', 'outputVariant'])
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
            $q->where('order_code', 'like', "%{$search}%");
        });

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): ProductionOrder
    {
        return ProductionOrder::with([
            'warehouse',
            'outputVariant',
            'items.productSku',
            'items.material',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): ProductionOrder
    {
        $this->assertWarehousesBelongToOrganization(
            $data['organization_id'] ?? null,
            [$data['warehouse_id'] ?? null],
        );

        return DB::transaction(function () use ($data) {
            $data['order_code'] = $this->generateCode($data['organization_id']);
            $data['status'] = ProductionOrderStatusEnum::Draft->value;

            $items = $data['items'] ?? [];
            unset($data['items']);

            // Backfill recipe_multiplier from the output SKU when the caller
            // didn't override it. The column has a `default: 1` in the
            // schema; we only need this hop because the omnify-generated
            // store request marks the field present-but-not-defaulted, and
            // the create FE doesn't surface it.
            if (! isset($data['recipe_multiplier']) && ! empty($data['output_variant_id'])) {
                $sku = $this->skus()->findWithRecipe((string) $data['output_variant_id']);
                $data['recipe_multiplier'] = $sku?->recipeMultiplier ?? 1.0;
            }

            // BR-02 — when the caller didn't pre-populate items (the standard
            // FE flow at /shop/.../production/orders/new only sends warehouse +
            // output_variant + planned_qty), derive them from the output SKU's
            // recipe. Recipe-less SKUs leave items empty and submit() will
            // surface the "must have at least one item" error.
            if ($items === [] && ! empty($data['output_variant_id']) && ! empty($data['planned_quantity'])) {
                // plan-040 NEW-BP-2 (TH.8): pass the RESOLVED recipe_multiplier
                // (caller override > SKU default, backfilled above) so a
                // production order that overrides the SKU's multiplier scales
                // its derived item quantities accordingly.
                $items = $this->deriveItemsFromRecipe(
                    skuId: $data['output_variant_id'],
                    plannedQuantity: (float) $data['planned_quantity'],
                    multiplier: (float) ($data['recipe_multiplier'] ?? 1.0),
                );
            }

            $order = ProductionOrder::create($data);

            foreach ($items as $itemData) {
                $order->items()->create($itemData);
            }

            return $this->loadRelations($order);
        });
    }

    /**
     * Derive ProductionOrderItem rows from `outputVariant.recipe.ingredients`.
     * Scaling matches plan-024 Decision 7 (and OrderClosingService) for ledger
     * consistency:
     *
     *     planned_quantity = ingredient.quantity / recipe.output_quantity
     *                        × order.planned_quantity × resolved recipe_multiplier
     *
     * Skips silently when the SKU has no recipe, no ingredients, or the
     * recipe's output_quantity is non-positive (would divide by zero) — the
     * submit() gate will catch the missing items in that case.
     *
     * @return list<array{component_type: string, material_id?: string|null, product_sku_id?: string|null, planned_quantity: float, unit: string|null}>
     */
    private function deriveItemsFromRecipe(string $skuId, float $plannedQuantity, float $multiplier): array
    {
        $sku = $this->skus()->findWithRecipe($skuId);
        if ($sku === null || $sku->recipe === null) {
            return [];
        }

        $recipe = $sku->recipe;
        $ingredients = $recipe->ingredients;
        $outputQty = $recipe->outputQuantity;
        if ($ingredients === [] || $outputQty <= 0) {
            return [];
        }

        // plan-040 NEW-BP-2 (TH.8): use the resolved order multiplier passed in
        // by the caller (which already prefers a caller override over the SKU
        // default) rather than re-reading sku.recipe_multiplier here.
        $scale = ($plannedQuantity * $multiplier) / $outputQty;

        $items = [];
        foreach ($ingredients as $ing) {
            $type = $ing['type'] ?? 'material';
            $qty = (float) ($ing['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $row = [
                'component_type' => $type,
                'planned_quantity' => $qty * $scale,
                'unit' => $ing['unit'] ?? null,
            ];

            // Recipe ingredients store either material_id or variant_id —
            // mirror that into the matching FK column.
            if ($type === 'material' && ! empty($ing['material_id'])) {
                $row['material_id'] = $ing['material_id'];
                $row['product_sku_id'] = null;
                if (empty($row['unit'])) {
                    $row['unit'] = Material::find($ing['material_id'])?->yield_unit;
                }
            } elseif ($type === 'variant' && ! empty($ing['variant_id'])) {
                $row['product_sku_id'] = $ing['variant_id'];
                $row['material_id'] = null;
            } else {
                Log::warning('production-order: skipped malformed recipe ingredient', [
                    'sku_id' => $skuId,
                    'recipe_id' => $recipe->id,
                    'ingredient' => $ing,
                ]);

                continue;
            }

            $items[] = $row;
        }

        return $items;
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(ProductionOrder $order, array $data): ProductionOrder
    {
        $this->assertStatus($order, [ProductionOrderStatusEnum::Draft], 'update');

        return DB::transaction(function () use ($order, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);

            $order->update($data);

            if ($items !== null) {
                $order->items()->delete();

                foreach ($items as $itemData) {
                    $order->items()->create($itemData);
                }
            }

            return $this->loadRelations($order);
        });
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    public function delete(ProductionOrder $order): bool
    {
        $this->assertStatus($order, [
            ProductionOrderStatusEnum::Draft,
            ProductionOrderStatusEnum::Cancelled,
        ], 'delete');

        return DB::transaction(function () use ($order) {
            $order->items()->delete();

            return $order->delete();
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submit(ProductionOrder $order): ProductionOrder
    {
        $this->assertStatus($order, [ProductionOrderStatusEnum::Draft], 'submit');

        if ($order->items()->count() === 0) {
            throw new InvalidStatusTransitionException(
                'Cannot submit: production order must have at least one item.'
            );
        }

        $warehouse = $order->warehouse ?? Warehouse::findOrFail($order->warehouse_id);

        if ($warehouse->auto_approve_batch) {
            $order->update([
                'status' => ProductionOrderStatusEnum::Approved->value,
                'approved_at' => now(),
            ]);

            return $this->loadRelations($order);
        }

        $order->update([
            'status' => ProductionOrderStatusEnum::Pending->value,
        ]);

        return $this->loadRelations($order);
    }

    public function approve(ProductionOrder $order, string $approverId): ProductionOrder
    {
        $this->assertStatus($order, [ProductionOrderStatusEnum::Pending], 'approve');

        $order->update([
            'status' => ProductionOrderStatusEnum::Approved->value,
            'approved_by_id' => $approverId,
            'approved_at' => now(),
        ]);

        return $this->loadRelations($order);
    }

    /**
     * Approved → InProgress. Matches the schema doc semantic that "starting
     * production = ingredients leave the shelf" — emit the NVL stock_out
     * here, not at complete(). Operators who haven't actually pulled
     * ingredients out yet must cancel before start.
     *
     * Item quantities use `planned_quantity` (auto-derived from the SKU
     * recipe at create). If the actual run consumes more/less, that delta
     * is reconciled at complete() against `actual_quantity` on each item.
     */
    public function start(ProductionOrder $order, string $startedById): ProductionOrder
    {
        $this->assertStatus($order, [ProductionOrderStatusEnum::Approved], 'start');

        return DB::transaction(function () use ($order, $startedById) {
            $order->load('items');

            $inputItems = $order->items->map(fn ($item) => [
                'product_sku_id' => $item->product_sku_id,
                'material_id' => $item->material_id,
                'quantity' => (float) $item->planned_quantity,
                'unit' => $item->unit,
            ])->toArray();

            if (! empty($inputItems)) {
                $stockOut = $this->transactionService->create([
                    'organization_id' => $order->organization_id,
                    'warehouse_id' => $order->warehouse_id,
                    'type' => StockTransactionTypeEnum::StockOut->value,
                    'sub_type' => StockTransactionSubTypeEnum::Production->value,
                    'reference_type' => 'production_order',
                    'reference_id' => $order->id,
                    'created_by_id' => $startedById,
                    'note' => "Material consumption for production order {$order->order_code}",
                    'items' => $inputItems,
                ]);
                $this->transactionService->submit($stockOut);
                $stockOut->refresh();

                if ($stockOut->status === 'pending') {
                    $this->transactionService->approve($stockOut, $startedById);
                }

                $order->stock_out_transaction_id = $stockOut->id;
            }

            $order->update([
                'status' => ProductionOrderStatusEnum::InProgress->value,
                'started_at' => now(),
                'stock_out_transaction_id' => $order->stock_out_transaction_id,
            ]);

            return $this->loadRelations($order);
        });
    }

    /**
     * InProgress → Completed. Stock-in for the finished SKU based on the
     * operator's reported `actual_quantity` (falls back to planned). NVL
     * stock_out already landed at start(), so this step only adds the
     * output and stamps the timestamps.
     */
    public function complete(ProductionOrder $order, string $completedById, ?float $actualQuantity = null): ProductionOrder
    {
        $this->assertStatus($order, [ProductionOrderStatusEnum::InProgress], 'complete');

        return DB::transaction(function () use ($order, $completedById, $actualQuantity) {
            $outputQty = $actualQuantity ?? (float) $order->planned_quantity;

            $stockIn = $this->transactionService->create([
                'organization_id' => $order->organization_id,
                'warehouse_id' => $order->warehouse_id,
                'type' => StockTransactionTypeEnum::StockIn->value,
                'sub_type' => StockTransactionSubTypeEnum::Production->value,
                'reference_type' => 'production_order',
                'reference_id' => $order->id,
                'created_by_id' => $completedById,
                'note' => "Production output for order {$order->order_code}",
                'items' => [
                    [
                        'product_sku_id' => $order->output_variant_id,
                        'quantity' => $outputQty,
                        'unit' => $order->output_unit,
                    ],
                ],
            ]);
            $this->transactionService->submit($stockIn);
            $stockIn->refresh();

            if ($stockIn->status === 'pending') {
                $this->transactionService->approve($stockIn, $completedById);
            }

            $order->update([
                'status' => ProductionOrderStatusEnum::Completed->value,
                'actual_quantity' => $outputQty,
                'stock_in_transaction_id' => $stockIn->id,
                'completed_at' => now(),
            ]);

            return $this->loadRelations($order);
        });
    }

    public function cancel(ProductionOrder $order, ?string $cancelledById = null): ProductionOrder
    {
        // plan-040 TD.4 (H5) — Draft / Pending / Approved cancel is a status
        // flip; InProgress cancel (after start() consumed the inputs) must
        // reverse the stock_out the start() path booked. Mirrors
        // MaterialBatchService::cancel.
        $this->assertStatus($order, [
            ProductionOrderStatusEnum::Draft,
            ProductionOrderStatusEnum::Pending,
            ProductionOrderStatusEnum::Approved,
            ProductionOrderStatusEnum::InProgress,
        ], 'cancel');

        return DB::transaction(function () use ($order, $cancelledById) {
            $stockOutId = $order->stock_out_transaction_id;

            if ($stockOutId !== null) {
                // plan-040 TD.4 (H5): emit a reversal stock_in restoring the
                // consumed materials. Routed through StockTransactionService so
                // stock_levels + stock_movements stay in sync.
                $consumedItems = StockTransactionItem::query()
                    ->where('stock_transaction_id', $stockOutId)
                    ->get(['material_id', 'product_sku_id', 'material_lot_id', 'quantity', 'base_quantity', 'unit']);

                if ($consumedItems->isNotEmpty()) {
                    $reversal = $this->transactionService->create([
                        'organization_id' => $order->organization_id,
                        'warehouse_id' => $order->warehouse_id,
                        'type' => StockTransactionTypeEnum::StockIn->value,
                        'sub_type' => StockTransactionSubTypeEnum::AdjustmentIn->value,
                        'reference_type' => 'production_order',
                        'reference_id' => $order->id,
                        'created_by_id' => $cancelledById,
                        'note' => "Reversal of cancelled production order {$order->order_code}",
                        // Restore the originally-entered quantity + unit so create()
                        // reconverts to the same base_quantity that was deducted.
                        'items' => $consumedItems->map(fn ($it) => [
                            'material_id' => $it->material_id,
                            'product_sku_id' => $it->product_sku_id,
                            'material_lot_id' => $it->material_lot_id,
                            'quantity' => (float) $it->quantity,
                            'unit' => $it->unit,
                        ])->all(),
                    ]);
                    $this->transactionService->submit($reversal);
                    $reversal->refresh();
                    if ($cancelledById !== null && $reversal->status === StockTransactionStatusEnum::Pending) {
                        $this->transactionService->approve($reversal, $cancelledById);
                    }
                }

                $order->stock_out_transaction_id = null;
            }

            $order->status = ProductionOrderStatusEnum::Cancelled->value;
            $order->save();

            return $this->loadRelations($order);
        });
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function generateCode(string $organizationId): string
    {
        $prefix = 'PO';
        $date = now()->format('Ymd');
        $baseCode = "{$prefix}-{$date}-";

        // #531 sub-bug: the suffix is 3-digit zero-padded, so a plain string
        // sort ranks "...-999" above "...-1000" — past the 999th doc/org/day it
        // re-mints an existing number. Order by LENGTH first (more digits = a
        // larger number) then lexically, so 1000+ sorts correctly. lockForUpdate
        // serializes concurrent creates on the max read (create() is wrapped in
        // a DB::transaction), matching the other inventory code generators.
        $last = ProductionOrder::where('organization_id', $organizationId)
            ->where('order_code', 'like', "{$baseCode}%")
            ->orderByRaw('LENGTH(order_code) DESC')
            ->orderBy('order_code', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $last
            ? (int) str_replace($baseCode, '', $last->order_code) + 1
            : 1;

        return $baseCode.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function loadRelations(ProductionOrder $order): ProductionOrder
    {
        return $order->load([
            'warehouse',
            'outputVariant',
            'items.productSku',
            'items.material',
        ])->loadCount('items');
    }

    /**
     * @param  ProductionOrderStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(ProductionOrder $order, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn (ProductionOrderStatusEnum $s) => $s->value, $allowedStatuses);
        $currentValue = $order->status instanceof ProductionOrderStatusEnum
            ? $order->status->value
            : (string) $order->status;

        if (! in_array($currentValue, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: production order status is '{$currentValue}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }
}
