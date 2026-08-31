<?php

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialBatchService;
use App\Services\Inventory\TraceService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'auto_approve_batch' => true,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->batches = app(MaterialBatchService::class);
});

/**
 * #634 — This helper FABRICATES a state the public API cannot reach: an
 * in_progress batch that already carries a completed stock_out. In the real
 * flow start() only preview-stamps material_lot_id (no deduction) and
 * stock_out_transaction_id is written solely inside complete(), which
 * atomically flips the batch to the uncancellable Completed status. We stamp
 * the stock_out by hand purely to EXERCISE the defensive reversal branch of
 * cancel() as a unit. The genuine production path is asserted by
 * 'real start()->cancel() ... is a status-only flip' below — that test proves
 * an in_progress batch never accrues a deduction to reverse.
 */
function inProgressBatchWithStockOut(string $orgId, string $brandId, string $warehouseId, string $materialId): array
{
    $lot = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => $materialId,
        'warehouse_id' => $warehouseId,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'received_qty' => 1000,
        'qty_on_hand' => 950, // 50 already consumed by start
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouseId,
        'material_id' => $materialId,
        'planned_yield' => 100,
        'yield_unit' => 'g',
        'status' => 'in_progress',
    ]);

    $stockOut = StockTransaction::factory()->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouseId,
        'type' => 'stock_out',
        'sub_type' => 'production',
        'reference_type' => 'material_batch',
        'reference_id' => $batch->id,
        'status' => 'completed',
    ]);
    StockTransactionItem::factory()->create([
        'stock_transaction_id' => $stockOut->id,
        'material_id' => $materialId,
        'material_lot_id' => $lot->id,
        'quantity' => 50,
        'base_quantity' => 50,
        'unit' => 'g',
    ]);

    $batch->update(['stock_out_transaction_id' => $stockOut->id]);

    return [$batch->fresh(), $lot, $stockOut];
}

it('cancel from in_progress restores lot qty and writes a reversal edge', function () {
    [$batch, $lot] = inProgressBatchWithStockOut($this->orgId, $this->brand->id, $this->warehouse->id, $this->material->id);

    $this->batches->cancel($batch, (string) Str::uuid());

    $reversalEdges = GenealogyLink::query()
        ->where('parent_lot_id', $lot->id)
        ->where('source_event_type', 'reversal')
        ->where('source_event_id', $batch->id)
        ->get();

    expect($batch->fresh()->status->value)->toEqual('cancelled')
        ->and($batch->fresh()->stock_out_transaction_id)->toBeNull()
        ->and($reversalEdges)->toHaveCount(1)
        ->and((float) $reversalEdges->first()->qty_consumed)->toEqual(50.0);
});

it('cancel from draft is a status-only flip (no stock movement)', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'status' => 'draft',
        'stock_out_transaction_id' => null,
    ]);

    $this->batches->cancel($batch);

    expect($batch->fresh()->status->value)->toEqual('cancelled')
        ->and(StockTransaction::where('reference_id', $batch->id)->where('sub_type', 'adjustment_in')->count())->toEqual(0);
});

it('cancel from pending is a status-only flip', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'status' => 'pending',
        'stock_out_transaction_id' => null,
    ]);

    $this->batches->cancel($batch);

    expect($batch->fresh()->status->value)->toEqual('cancelled');
});

it('cancel from in_progress restores the stock_level (reversal stock_in path)', function () {
    [$batch, $lot] = inProgressBatchWithStockOut($this->orgId, $this->brand->id, $this->warehouse->id, $this->material->id);

    // Mirror the pre-start StockLevel snapshot the FEFO path decremented.
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 950, // 50 consumed of the 1000 received
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $this->batches->cancel($batch, (string) Str::uuid());

    // The reversal stock_in adds the consumed 50 back onto the lot's stock_level.
    $level = StockLevel::where('material_lot_id', $lot->id)->first();
    expect((float) $level->quantity)->toEqual(1000.0);

    // A +50 reversal stock movement was written (audit trail for the restore).
    $reversalMovements = StockMovement::where('material_lot_id', $lot->id)
        ->where('movement_type', 'in')
        ->get();
    expect($reversalMovements)->toHaveCount(1)
        ->and((float) $reversalMovements->first()->quantity)->toEqual(50.0);
});

it('KNOWN GAP: cancel reversal does NOT restore MaterialLot.qty_on_hand', function () {
    // Documents current behaviour (see StockTransactionService completeTransaction:
    // "only stock_out drains qty_on_hand ... stock_in must NOT double-count it").
    // The cancel-reversal is a stock_in, so the lot's qty_on_hand is left at its
    // post-consumption value even though the stock_level is restored — a real
    // lot/level divergence. If the reversal is ever taught to re-credit
    // qty_on_hand, flip this expectation to 1000.0.
    [$batch, $lot] = inProgressBatchWithStockOut($this->orgId, $this->brand->id, $this->warehouse->id, $this->material->id);

    $this->batches->cancel($batch, (string) Str::uuid());

    expect((float) $lot->fresh()->qty_on_hand)->toEqual(950.0);
});

it('cancel from in_progress with no stock_out is a status-only flip (no reversal edges)', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'status' => 'in_progress',
        'stock_out_transaction_id' => null,
    ]);

    $this->batches->cancel($batch, (string) Str::uuid());

    expect($batch->fresh()->status->value)->toEqual('cancelled')
        ->and(GenealogyLink::where('source_event_type', 'reversal')->where('source_event_id', $batch->id)->count())->toEqual(0);
});

it('cancel from approved is allowed (status flip)', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'status' => 'approved',
        'stock_out_transaction_id' => null,
    ]);

    $this->batches->cancel($batch, (string) Str::uuid());

    expect($batch->fresh()->status->value)->toEqual('cancelled');
});

it('rejects cancel on a completed batch (output lot already minted)', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'status' => 'completed',
    ]);

    expect(fn () => $this->batches->cancel($batch, (string) Str::uuid()))
        ->toThrow(InvalidStatusTransitionException::class);

    expect($batch->fresh()->status->value)->toEqual('completed');
});

it('rejects cancel on an already-cancelled batch', function () {
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'status' => 'cancelled',
    ]);

    expect(fn () => $this->batches->cancel($batch, (string) Str::uuid()))
        ->toThrow(InvalidStatusTransitionException::class);
});

it('real start()->cancel() of an in_progress batch is a status-only flip (no deduction, no reversal) — #634', function () {
    // Exercise the GENUINE production path end-to-end (create → submit →
    // start → cancel) to prove the auditor's premise: start() does NOT
    // decrement stock, so an in_progress batch never carries a
    // stock_out_transaction_id and cancel() has nothing to reverse.
    $ingredient = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    Recipe::create([
        'sku' => 'R-'.Str::upper(Str::random(8)),
        'name' => 'Sauce',
        'material_id' => $this->material->id,
        'output_quantity' => 1,
        'output_unit' => 'g',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $ingredient->id, 'quantity' => 40, 'unit' => 'g'],
        ],
        'is_active' => true,
        'approval_status' => 'approved',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $ingredient->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
        'expiry_date' => now()->addDays(10)->toDateString(),
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $ingredient->id,
        'material_lot_id' => $lot->id,
        'quantity' => 1000,
        'unit' => 'g',
        'alert_enabled' => false,
    ]);

    $batch = $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'multiplier' => 1,
        'planned_yield' => 1,
        'yield_unit' => 'g',
        'created_by_id' => (string) Str::uuid(),
    ]);
    $this->batches->submit($batch);              // auto_approve_batch → Approved
    $this->batches->start($batch->fresh());      // → in_progress, preview-stamp only

    // After a REAL start(): batch is in_progress, the item has a preview
    // material_lot_id stamp, but NO stock was deducted and there is NO
    // stock_out_transaction_id — the state the "with stock_out" helper cannot
    // occur through the public API.
    $started = $batch->fresh();
    $item = MaterialBatchItem::where('material_batch_id', $batch->id)
        ->whereNotNull('material_id')->first();
    expect($started->status->value)->toEqual('in_progress')
        ->and($started->stock_out_transaction_id)->toBeNull()
        ->and((string) $item->material_lot_id)->toEqual($lot->id)
        ->and((float) $lot->fresh()->qty_on_hand)->toEqual(1000.0);

    // Cancel the genuinely-in_progress batch.
    $this->batches->cancel($started, (string) Str::uuid());

    // Pure status flip: no reversal stock_in, no reversal genealogy edge, lot
    // and stock_level untouched.
    expect($batch->fresh()->status->value)->toEqual('cancelled')
        ->and((float) $lot->fresh()->qty_on_hand)->toEqual(1000.0)
        ->and((float) StockLevel::where('material_lot_id', $lot->id)->first()->quantity)->toEqual(1000.0)
        ->and(StockTransaction::where('reference_id', $batch->id)->where('sub_type', 'adjustment_in')->count())->toEqual(0)
        ->and(GenealogyLink::where('source_event_type', 'reversal')->where('source_event_id', $batch->id)->count())->toEqual(0);
});

it('TraceService excludes reversal edges from forward blast radius', function () {
    [$batch, $lot] = inProgressBatchWithStockOut($this->orgId, $this->brand->id, $this->warehouse->id, $this->material->id);

    $this->batches->cancel($batch, (string) Str::uuid());

    $trace = app(TraceService::class)
        ->traceLot($lot->id, direction: 'forward');

    // Children should not include the reversal edge despite the row existing.
    $hasReversal = collect($trace['children'] ?? [])
        ->contains(fn ($child) => ($child['edge']['source_event_type'] ?? null) === 'reversal');

    expect($hasReversal)->toBeFalse();
});
