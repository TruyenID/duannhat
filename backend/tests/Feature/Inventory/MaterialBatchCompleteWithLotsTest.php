<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialBatchStatusEnum;
use App\Omnify\Enums\MaterialLotSourceEnum;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\MaterialBatchService;
use Illuminate\Support\Str;

function seedCompALotStock(object $ctx, float $qty): MaterialLot
{
    $lot = MaterialLot::factory()->create([
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'material_id' => $ctx->compA->id,
        'warehouse_id' => $ctx->warehouse->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'received_qty' => $qty,
        'qty_on_hand' => $qty,
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    StockLevel::create([
        'warehouse_id' => $ctx->warehouse->id,
        'material_id' => $ctx->compA->id,
        'material_lot_id' => $lot->id,
        'quantity' => $qty,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    return $lot;
}

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

    // Two component materials + one output material.
    $this->compA = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->compB = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->outputMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->service = app(MaterialBatchService::class);
});

it('mints output lot + writes genealogy_links + sets batch.output_lot_id on complete()', function () {
    // Inbound supplier lots for both components.
    $lotA = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->compA->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'received_qty' => 100,
        'qty_on_hand' => 100,
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    $lotB = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->compB->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'received_qty' => 50,
        'qty_on_hand' => 50,
        'expiry_date' => now()->addDays(60)->toDateString(),
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->compA->id,
        'material_lot_id' => $lotA->id,
        'quantity' => 100,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->compB->id,
        'material_lot_id' => $lotB->id,
        'quantity' => 50,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'multiplier' => 1,
        'planned_yield' => 30,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compA->id,
        'planned_quantity' => 60,
        'actual_quantity' => 60,
        'unit' => 'kg',
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compB->id,
        'planned_quantity' => 20,
        'actual_quantity' => 20,
        'unit' => 'kg',
    ]);

    $completedById = (string) Str::uuid();
    $this->service->complete($batch, $completedById, actualYield: 30);

    $batch->refresh();

    // 1. Output lot was minted
    expect($batch->output_lot_id)->not->toBeNull();
    $outputLot = MaterialLot::find($batch->output_lot_id);
    expect($outputLot->source->value)->toBe(MaterialLotSourceEnum::Production->value)
        ->and($outputLot->status->value)->toBe(MaterialLotStatusEnum::Active->value)
        ->and((float) $outputLot->qty_on_hand)->toBe(30.0)
        ->and((float) $outputLot->received_qty)->toBe(30.0)
        ->and($outputLot->lot_code)->toBe($batch->batch_code)
        ->and((string) $outputLot->produced_by_batch_id)->toBe((string) $batch->id);

    // 2. genealogy_links rows exist for both consumed lots → output lot
    $links = GenealogyLink::where('child_lot_id', $outputLot->id)->get();
    expect($links)->toHaveCount(2);
    $byParent = $links->keyBy('parent_lot_id');
    expect($byParent)->toHaveKey($lotA->id)
        ->and($byParent)->toHaveKey($lotB->id);
    expect($byParent[$lotA->id]->source_event_type)->toBe('material_batch')
        ->and((string) $byParent[$lotA->id]->source_event_id)->toBe((string) $batch->id);

    // 3. Consumed lots' qty_on_hand decremented + status flipped if drained
    $lotA->refresh();
    $lotB->refresh();
    expect((float) $lotA->qty_on_hand)->toBe(40.0) // 100 - 60
        ->and((float) $lotB->qty_on_hand)->toBe(30.0); // 50 - 20

    // 4. Batch status flipped to Completed
    expect($batch->status->value)->toBe(MaterialBatchStatusEnum::Completed->value)
        ->and((float) $batch->actual_yield)->toBe(30.0);

    // 5. Output stock_levels row at the new lot's grain
    $outputLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $this->outputMaterial->id)
        ->where('material_lot_id', $outputLot->id)
        ->first();
    expect($outputLevel)->not->toBeNull()
        ->and((float) $outputLevel->quantity)->toBe(30.0);
});

it('computes weighted-average unit_cost on the output lot from consumed lots cost_basis_amount', function () {
    // Lot A: 10 kg @ ¥100/kg = ¥1000. Lot B: 5 kg @ ¥200/kg = ¥1000.
    // Yield 10 kg → expected unit_cost = (10*100 + 5*200) / 10 = 200.
    $lotA = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->compA->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 10,
        'qty_on_hand' => 10,
        'unit_cost' => 100,
        'total_cost' => 1000,
        'currency' => 'JPY',
        'cost_basis' => 'manual',
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    $lotB = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->compB->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 5,
        'qty_on_hand' => 5,
        'unit_cost' => 200,
        'total_cost' => 1000,
        'currency' => 'JPY',
        'cost_basis' => 'manual',
        'expiry_date' => now()->addDays(60)->toDateString(),
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->compA->id,
        'material_lot_id' => $lotA->id,
        'quantity' => 10,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->compB->id,
        'material_lot_id' => $lotB->id,
        'quantity' => 5,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'multiplier' => 1,
        'planned_yield' => 10,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compA->id,
        'planned_quantity' => 10,
        'actual_quantity' => 10,
        'unit' => 'kg',
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compB->id,
        'planned_quantity' => 5,
        'actual_quantity' => 5,
        'unit' => 'kg',
    ]);

    $this->service->complete($batch, (string) Str::uuid(), actualYield: 10);

    $batch->refresh();
    $outputLot = MaterialLot::find($batch->output_lot_id);
    expect((float) $outputLot->unit_cost)->toBe(200.0)
        ->and((float) $outputLot->total_cost)->toBe(2000.0)
        ->and($outputLot->currency)->toBe('JPY')
        ->and($outputLot->cost_basis)->toBe('production_calculated');
});

it('stamps item actual_quantity from planned_quantity on complete when left null', function () {
    // Regression: complete() consumed at planned_quantity but never wrote
    // actual_quantity back onto the batch items, so the detail UI showed a
    // perpetual "—" in the actual-qty column after a successful completion.
    seedCompALotStock($this, 500);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'multiplier' => 1,
        'planned_yield' => 10,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    $item = MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compA->id,
        'planned_quantity' => 20,
        'actual_quantity' => null,
        'unit' => 'kg',
    ]);

    $this->service->complete($batch, (string) Str::uuid(), actualYield: 10);

    expect((float) $item->fresh()->actual_quantity)->toBe(20.0);
});

it('preserves an operator-set item actual_quantity on complete', function () {
    seedCompALotStock($this, 500);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'multiplier' => 1,
        'planned_yield' => 10,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    $item = MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compA->id,
        'planned_quantity' => 20,
        'actual_quantity' => 18,
        'unit' => 'kg',
    ]);

    $this->service->complete($batch, (string) Str::uuid(), actualYield: 10);

    // Pre-set actual is left untouched (not overwritten by planned).
    expect((float) $item->fresh()->actual_quantity)->toBe(18.0);
});

it('refuses batch complete when stock exists only on the pre-lot bucket (#2416)', function () {
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->compA->id,
        'material_lot_id' => null,
        'quantity' => 500,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'multiplier' => 1,
        'planned_yield' => 10,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $this->compA->id,
        'planned_quantity' => 20,
        'actual_quantity' => 20,
        'unit' => 'kg',
    ]);

    expect(fn () => $this->service->complete($batch, (string) Str::uuid(), actualYield: 10))
        ->toThrow(InsufficientStockException::class);
});
