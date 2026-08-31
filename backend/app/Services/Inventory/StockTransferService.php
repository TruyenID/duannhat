<?php

namespace App\Services\Inventory;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\StockTransactionItem;
use App\Models\StockTransfer;
use App\Omnify\Enums\StockTransactionStatusEnum;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Omnify\Enums\StockTransferStatusEnum;
use App\Services\Inventory\Concerns\AssertsWarehouseOrganization;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    use AssertsWarehouseOrganization;

    public function __construct(
        private readonly StockTransactionService $transactionService,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, source_warehouse_id?: string, destination_warehouse_id?: string, status?: string, date_from?: string, date_to?: string, search?: string, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = StockTransfer::query()
            ->with(['sourceWarehouse', 'destinationWarehouse'])
            ->withCount('items');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        $query->when($filters['source_warehouse_id'] ?? null, fn ($q, $id) => $q->where('source_warehouse_id', $id));
        $query->when($filters['destination_warehouse_id'] ?? null, fn ($q, $id) => $q->where('destination_warehouse_id', $id));
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
            $q->where('transfer_code', 'like', "%{$search}%");
        });

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): StockTransfer
    {
        return StockTransfer::with([
            'sourceWarehouse',
            'destinationWarehouse',
            'items.productSku',
            'items.material',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): StockTransfer
    {
        $this->assertWarehousesBelongToOrganization(
            $data['organization_id'] ?? null,
            [$data['source_warehouse_id'] ?? null, $data['destination_warehouse_id'] ?? null],
        );

        return DB::transaction(function () use ($data) {
            $data['transfer_code'] = $this->generateCode($data['organization_id']);
            $data['status'] = StockTransferStatusEnum::Draft->value;

            $items = $data['items'] ?? [];
            unset($data['items']);

            $transfer = StockTransfer::create($data);

            foreach ($items as $itemData) {
                // plan-040 NEW-STK-2 (TD.6): convert unit→base so
                // sent_base_quantity matches the stock_out ledger written at
                // approve(). For product-sku lines (no material_id) the helper
                // returns the quantity unchanged.
                $itemData['sent_base_quantity'] = $this->transactionService->calculateBaseQuantity(
                    (float) $itemData['sent_quantity'],
                    $itemData['unit'] ?? null,
                    $itemData['material_id'] ?? null,
                );
                $transfer->items()->create($itemData);
            }

            return $this->loadRelations($transfer);
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(StockTransfer $transfer, array $data): StockTransfer
    {
        $this->assertStatus($transfer, [StockTransferStatusEnum::Draft], 'update');

        return DB::transaction(function () use ($transfer, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);

            $transfer->update($data);

            if ($items !== null) {
                $transfer->items()->delete();

                foreach ($items as $itemData) {
                    // plan-040 NEW-STK-2 (TD.6): unit→base conversion, same as create().
                    $itemData['sent_base_quantity'] = $this->transactionService->calculateBaseQuantity(
                        (float) $itemData['sent_quantity'],
                        $itemData['unit'] ?? null,
                        $itemData['material_id'] ?? null,
                    );
                    $transfer->items()->create($itemData);
                }
            }

            return $this->loadRelations($transfer);
        });
    }

    // =========================================================================
    //  Delete
    // =========================================================================

    public function delete(StockTransfer $transfer): bool
    {
        $this->assertStatus($transfer, [
            StockTransferStatusEnum::Draft,
            StockTransferStatusEnum::Cancelled,
        ], 'delete');

        return DB::transaction(function () use ($transfer) {
            $transfer->items()->delete();

            return $transfer->delete();
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submit(StockTransfer $transfer): StockTransfer
    {
        $this->assertStatus($transfer, [StockTransferStatusEnum::Draft], 'submit');

        $transfer->update([
            'status' => StockTransferStatusEnum::Pending->value,
        ]);

        return $this->loadRelations($transfer);
    }

    public function approve(StockTransfer $transfer, string $approverId): StockTransfer
    {
        $this->assertStatus($transfer, [StockTransferStatusEnum::Pending], 'approve');

        return DB::transaction(function () use ($transfer, $approverId) {
            $transfer->load('items');

            // Create stock_out transaction from source warehouse
            $stockOutTransaction = $this->transactionService->create([
                'organization_id' => $transfer->organization_id,
                'warehouse_id' => $transfer->source_warehouse_id,
                'type' => StockTransactionTypeEnum::StockOut->value,
                'sub_type' => StockTransactionSubTypeEnum::TransferOut->value,
                'reference_type' => 'stock_transfer',
                'reference_id' => $transfer->id,
                'created_by_id' => $approverId,
                'note' => "Stock out for transfer {$transfer->transfer_code}",
                'items' => $transfer->items->map(fn ($item) => [
                    'product_sku_id' => $item->product_sku_id,
                    'material_id' => $item->material_id,
                    'quantity' => (float) $item->sent_quantity,
                    'unit' => $item->unit,
                ])->toArray(),
            ]);

            // Submit + approve the stock_out transaction to deduct stock
            $this->transactionService->submit($stockOutTransaction);
            $stockOutTransaction->refresh();

            if ($stockOutTransaction->status === StockTransactionStatusEnum::Pending) {
                $this->transactionService->approve($stockOutTransaction, $approverId);
            }

            $transfer->update([
                'status' => StockTransferStatusEnum::Approved->value,
                'approved_by_id' => $approverId,
                'approved_at' => now(),
                'stock_out_transaction_id' => $stockOutTransaction->id,
            ]);

            return $this->loadRelations($transfer);
        });
    }

    public function receive(StockTransfer $transfer, string $receiverId, ?array $receivedItems = null): StockTransfer
    {
        $this->assertStatus($transfer, [StockTransferStatusEnum::Approved], 'receive');

        return DB::transaction(function () use ($transfer, $receiverId, $receivedItems) {
            $transfer->load('items');

            // Update received quantities on items if provided
            if ($receivedItems !== null) {
                $receivedById = collect($receivedItems)->keyBy('id');

                foreach ($transfer->items as $item) {
                    $receivedItem = $receivedById->get($item->id);
                    if ($receivedItem === null) {
                        // Line not addressed by the payload — default to sent.
                        $item->update([
                            'received_quantity' => $item->sent_quantity,
                            'received_base_quantity' => $item->sent_base_quantity,
                        ]);

                        continue;
                    }

                    // plan-040 NEW-STK-2 (TD.6): convert the received unit→base
                    // so received_base_quantity matches the destination stock_in
                    // ledger (same conversion used on the send side).
                    $item->update([
                        'received_quantity' => $receivedItem['received_quantity'],
                        'received_base_quantity' => $this->transactionService->calculateBaseQuantity(
                            (float) $receivedItem['received_quantity'],
                            $item->unit,
                            $item->material_id,
                        ),
                    ]);
                }
            } else {
                // Default: received = sent
                foreach ($transfer->items as $item) {
                    $item->update([
                        'received_quantity' => $item->sent_quantity,
                        'received_base_quantity' => $item->sent_base_quantity,
                    ]);
                }
            }

            $transfer->load('items');

            // Create stock_in transaction to destination warehouse. The
            // stock_in always books the full SENT quantity (what physically
            // left the source); any in-transit shrinkage is then written off by
            // an explicit adjustment_out below (TD.2) so the destination nets to
            // the genuinely received amount AND the discrepancy is auditable.
            $stockInTransaction = $this->transactionService->create([
                'organization_id' => $transfer->organization_id,
                'warehouse_id' => $transfer->destination_warehouse_id,
                'type' => StockTransactionTypeEnum::StockIn->value,
                'sub_type' => StockTransactionSubTypeEnum::TransferIn->value,
                'reference_type' => 'stock_transfer',
                'reference_id' => $transfer->id,
                'created_by_id' => $receiverId,
                'note' => "Stock in for transfer {$transfer->transfer_code}",
                'items' => $transfer->items->map(fn ($item) => [
                    'product_sku_id' => $item->product_sku_id,
                    'material_id' => $item->material_id,
                    'quantity' => (float) $item->sent_quantity,
                    'unit' => $item->unit,
                ])->toArray(),
            ]);

            // Submit + approve the stock_in transaction to add stock
            $this->transactionService->submit($stockInTransaction);
            $stockInTransaction->refresh();

            if ($stockInTransaction->status === StockTransactionStatusEnum::Pending) {
                $this->transactionService->approve($stockInTransaction, $receiverId);
            }

            // plan-040 TD.2 (H1): when goods shrink in transit (received < sent),
            // write off the lost delta as a shrinkage `adjustment_out` against the
            // destination. Net effect: dest = +sent (stock_in) − delta (shrink) =
            // +received, and the source's full sent_quantity stays conserved
            // across the ledger.
            $this->recordShrinkageAdjustment($transfer, $receiverId);

            $transfer->update([
                'status' => StockTransferStatusEnum::Completed->value,
                'received_by_id' => $receiverId,
                'received_at' => now(),
                'completed_at' => now(),
                'stock_in_transaction_id' => $stockInTransaction->id,
            ]);

            return $this->loadRelations($transfer);
        });
    }

    /**
     * plan-040 TD.2 (H1) — record an `adjustment_out` for every line whose
     * received_quantity fell short of sent_quantity. The shrinkage is the
     * in-transit loss; deducting it from the destination right after the
     * stock_in keeps the destination on-hand at the genuinely received amount
     * while leaving an auditable adjustment row for the discrepancy.
     *
     * No-op when every line was received in full.
     */
    private function recordShrinkageAdjustment(StockTransfer $transfer, string $receiverId): void
    {
        $shrinkItems = [];

        foreach ($transfer->items as $item) {
            $sent = (float) $item->sent_quantity;
            $received = $item->received_quantity !== null ? (float) $item->received_quantity : $sent;
            $delta = $sent - $received;

            if ($delta <= 0) {
                continue;
            }

            $shrinkItems[] = [
                'product_sku_id' => $item->product_sku_id,
                'material_id' => $item->material_id,
                'quantity' => $delta,
                'unit' => $item->unit,
            ];
        }

        if ($shrinkItems === []) {
            return;
        }

        $adjustment = $this->transactionService->create([
            'organization_id' => $transfer->organization_id,
            'warehouse_id' => $transfer->destination_warehouse_id,
            'type' => StockTransactionTypeEnum::StockOut->value,
            'sub_type' => StockTransactionSubTypeEnum::AdjustmentOut->value,
            'reference_type' => 'stock_transfer',
            'reference_id' => $transfer->id,
            'created_by_id' => $receiverId,
            'note' => "Transfer shrinkage for {$transfer->transfer_code} (received < sent)",
            'items' => $shrinkItems,
        ]);

        $this->transactionService->submit($adjustment);
        $adjustment->refresh();

        if ($adjustment->status === StockTransactionStatusEnum::Pending) {
            $this->transactionService->approve($adjustment, $receiverId);
        }
    }

    public function cancel(StockTransfer $transfer, ?string $cancelledById = null): StockTransfer
    {
        // plan-040 TD.3 (H2) — Draft / Pending cancel is a status flip;
        // Approved / InTransit cancel must reverse the stock_out the approve()
        // path already booked against the source warehouse.
        $this->assertStatus($transfer, [
            StockTransferStatusEnum::Draft,
            StockTransferStatusEnum::Pending,
            StockTransferStatusEnum::Approved,
            StockTransferStatusEnum::InTransit,
        ], 'cancel');

        return DB::transaction(function () use ($transfer, $cancelledById) {
            $stockOutId = $transfer->stock_out_transaction_id;

            if ($stockOutId !== null) {
                // plan-040 TD.3 (H2): emit a compensating reversal stock_in
                // restoring the source warehouse to its pre-approve qty. Routed
                // through StockTransactionService (mirrors
                // MaterialBatchService::cancel) so stock_levels + stock_movements
                // stay in sync via the same adjustment code path.
                $consumedItems = StockTransactionItem::query()
                    ->where('stock_transaction_id', $stockOutId)
                    ->get(['material_id', 'product_sku_id', 'material_lot_id', 'quantity', 'base_quantity', 'unit']);

                if ($consumedItems->isNotEmpty()) {
                    $reversal = $this->transactionService->create([
                        'organization_id' => $transfer->organization_id,
                        'warehouse_id' => $transfer->source_warehouse_id,
                        'type' => StockTransactionTypeEnum::StockIn->value,
                        'sub_type' => StockTransactionSubTypeEnum::AdjustmentIn->value,
                        'reference_type' => 'stock_transfer',
                        'reference_id' => $transfer->id,
                        'created_by_id' => $cancelledById,
                        'note' => "Reversal of cancelled transfer {$transfer->transfer_code}",
                        // Restore the originally-entered quantity + unit so
                        // StockTransactionService::create reconverts to the same
                        // base_quantity that was deducted (no double conversion).
                        'items' => $consumedItems->map(fn ($it) => [
                            'material_id' => $it->material_id,
                            'product_sku_id' => $it->product_sku_id,
                            'material_lot_id' => $it->material_lot_id,
                            // plan-040 L3 (open question): destination lot identity
                            // unchanged pending ruling. This reversal restores the
                            // SOURCE qty only — not a destination-lot concern.
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

                $transfer->stock_out_transaction_id = null;
            }

            $transfer->status = StockTransferStatusEnum::Cancelled->value;
            $transfer->save();

            return $this->loadRelations($transfer);
        });
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    private function generateCode(string $organizationId): string
    {
        $prefix = 'TR';
        $date = now()->format('Ymd');
        $baseCode = "{$prefix}-{$date}-";

        // plan-040 L1: lock the per-(org, day-prefix) rows so concurrent creates
        // serialize on the max read and cannot mint duplicate transfer codes.
        // #531 sub-bug: the suffix is 3-digit zero-padded, so a plain string
        // sort ranks "...-999" above "...-1000" — past the 999th doc/org/day it
        // re-mints an existing number. Order by LENGTH first (more digits = a
        // larger number) then lexically, so 1000+ sorts correctly.
        $last = StockTransfer::where('organization_id', $organizationId)
            ->where('transfer_code', 'like', "{$baseCode}%")
            ->orderByRaw('LENGTH(transfer_code) DESC')
            ->orderBy('transfer_code', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $last
            ? (int) str_replace($baseCode, '', $last->transfer_code) + 1
            : 1;

        return $baseCode.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function loadRelations(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load([
            'sourceWarehouse',
            'destinationWarehouse',
            'items.productSku',
            'items.material',
        ])->loadCount('items');
    }

    /**
     * @param  StockTransferStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(StockTransfer $transfer, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn (StockTransferStatusEnum $s) => $s->value, $allowedStatuses);
        $currentValue = $transfer->status instanceof StockTransferStatusEnum
            ? $transfer->status->value
            : (string) $transfer->status;

        if (! in_array($currentValue, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: transfer status is '{$currentValue}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }
}
