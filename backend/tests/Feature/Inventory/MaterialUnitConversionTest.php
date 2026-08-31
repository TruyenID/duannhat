<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\StockTransactionItem;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialBatchService;
use App\Services\Inventory\MaterialLotService;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'sku' => 'FLOUR',
    ]);

    // Base unit g, plus a 25kg-bag that converts at ratio 25_000.
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'g',
        'ratio' => 1,
        'is_base' => true,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'bag_25kg',
        'ratio' => 25000,
        'is_base' => false,
    ]);

    $this->stockTx = app(StockTransactionService::class);
    $this->lots = app(MaterialLotService::class);
});

it('converts non-base unit qty using MaterialUnit ratio', function () {
    $base = $this->stockTx->calculateBaseQuantity(2, 'bag_25kg', $this->material->id);

    expect($base)->toEqual(50000.0);
});

it('returns qty unchanged for base unit', function () {
    $base = $this->stockTx->calculateBaseQuantity(750, 'g', $this->material->id);

    expect($base)->toEqual(750.0);
});

it('rejects unknown unit when material has registered units', function () {
    expect(fn () => $this->stockTx->calculateBaseQuantity(1, 'lbs', $this->material->id))
        ->toThrow(ValidationException::class);
});

it('falls back to 1:1 when material has no registered units (legacy)', function () {
    $legacyMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $base = $this->stockTx->calculateBaseQuantity(7, 'kg', $legacyMaterial->id);

    expect($base)->toEqual(7.0);
});

it('persists receive in base unit and stamps entered quantity/unit', function () {
    $result = $this->lots->receive([
        'organization_id' => $this->orgId,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 2,
        'unit' => 'bag_25kg',
        'cost_per_unit' => 50,
        'created_by_id' => (string) Str::uuid(),
    ]);

    /** @var MaterialLot $lot */
    $lot = $result['lot'];

    expect((float) $lot->qty_on_hand)->toEqual(50000.0)
        ->and((float) $lot->received_qty)->toEqual(50000.0)
        ->and($lot->unit)->toEqual('g')
        ->and((float) $lot->entered_quantity)->toEqual(2.0)
        ->and($lot->entered_unit)->toEqual('bag_25kg')
        // total_cost preserves the invoice ($50/bag × 2 bags = $100)
        ->and((float) $lot->total_cost)->toEqual(100.0)
        // base unit cost = $100 / 50000g = $0.002/g
        ->and((float) $lot->unit_cost)->toEqual(0.002);
});

it('round-trip — receive in non-base then consume in base reduces lot qty in base', function () {
    $result = $this->lots->receive([
        'organization_id' => $this->orgId,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 2,
        'unit' => 'bag_25kg',
        'created_by_id' => (string) Str::uuid(),
    ]);
    $lot = $result['lot'];

    $txn = $this->stockTx->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $this->material->id,
            'quantity' => 30000,
            'unit' => 'g',
        ]],
    ]);
    $this->stockTx->submit($txn);

    $lot->refresh();
    $stockLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $this->material->id)
        ->where('material_lot_id', $lot->id)
        ->first();

    expect((float) $lot->qty_on_hand)->toEqual(20000.0)
        ->and($stockLevel)->not->toBeNull()
        ->and((float) $stockLevel->quantity)->toEqual(20000.0);
});

it('converts stock_out item base_quantity from non-base unit', function () {
    // seed lot first
    $this->lots->receive([
        'organization_id' => $this->orgId,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 5,
        'unit' => 'bag_25kg',
        'created_by_id' => (string) Str::uuid(),
    ]);

    $txn = $this->stockTx->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $this->material->id,
            'quantity' => 1,
            'unit' => 'bag_25kg',
        ]],
    ]);

    $item = StockTransactionItem::where('stock_transaction_id', $txn->id)->first();

    expect((float) $item->quantity)->toEqual(1.0)
        ->and((float) $item->base_quantity)->toEqual(25000.0);
});

it('mints production output lot in base unit with entered fields stamped', function () {
    // Material with yield_unit=L base=ml ratio=1000.
    $produced = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'sku' => 'DASHI',
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $produced->id,
        'unit' => 'ml',
        'ratio' => 1,
        'is_base' => true,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $produced->id,
        'unit' => 'L',
        'ratio' => 1000,
        'is_base' => false,
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $produced->id,
        'planned_yield' => 9.5,
        'yield_unit' => 'L',
        'status' => 'in_progress',
    ]);

    $batches = app(MaterialBatchService::class);
    $batches->complete($batch, 9.5);

    $output = MaterialLot::where('produced_by_batch_id', $batch->id)->first();

    expect($output)->not->toBeNull()
        ->and((float) $output->qty_on_hand)->toEqual(9500.0)
        ->and($output->unit)->toEqual('ml')
        ->and((float) $output->entered_quantity)->toEqual(9.5)
        ->and($output->entered_unit)->toEqual('L');
});

it("treats the legacy 'unit' no-base-unit sentinel as already-base (no crash)", function () {
    // plan-040 fix: many seeded StockLevel / MaterialLot / stock_count_item rows
    // carry unit='unit' (the MaterialLotService::resolveBaseUnit fallback for a
    // material that had no base unit at the time). Even though THIS material has
    // real units (g, bag_25kg), passing the 'unit' sentinel must NOT throw
    // "Unit 'unit' is not defined" — it is treated as already-base (1:1), so
    // stock-count approve / receive / batch flows that inherited it don't crash.
    expect($this->stockTx->calculateBaseQuantity(10.0, 'unit', $this->material->id))
        ->toEqual(10.0);
});

it('still rejects a genuinely unknown unit for a material that has units', function () {
    expect(fn () => $this->stockTx->calculateBaseQuantity(10.0, 'parsec', $this->material->id))
        ->toThrow(ValidationException::class);
});

it('converts a fractional quantity without rounding loss', function () {
    // 1.5 bag_25kg × 25000 = 37500 exactly. No float drift at the column scale.
    expect($this->stockTx->calculateBaseQuantity(1.5, 'bag_25kg', $this->material->id))
        ->toEqual(37500.0);
});

it('preserves 4-dp precision on a fine-grained ratio', function () {
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'pinch',
        'ratio' => 0.3125, // fine sub-gram unit
        'is_base' => false,
    ]);

    // 3 × 0.3125 = 0.9375 — exact at the decimal(15,4) column scale.
    expect($this->stockTx->calculateBaseQuantity(3, 'pinch', $this->material->id))
        ->toEqual(0.9375);
});

it('rejects a unit that belongs to a DIFFERENT material (material isolation)', function () {
    $other = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $other->id,
        'unit' => 'g',
        'ratio' => 1,
        'is_base' => true,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $other->id,
        'unit' => 'crate',
        'ratio' => 12,
        'is_base' => false,
    ]);

    // 'crate' is defined for $other, NOT for $this->material — must not leak.
    expect(fn () => $this->stockTx->calculateBaseQuantity(1, 'crate', $this->material->id))
        ->toThrow(ValidationException::class);
});

it('leaves an already-converted lot untouched when the MaterialUnit ratio later changes', function () {
    // Receive 2 bag_25kg → lot stored as 50000g at the ratio in force NOW.
    $result = $this->lots->receive([
        'organization_id' => $this->orgId,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'received_qty' => 2,
        'unit' => 'bag_25kg',
        'created_by_id' => (string) Str::uuid(),
    ]);
    $lot = $result['lot'];

    expect((float) $lot->fresh()->qty_on_hand)->toEqual(50000.0);

    // HQ re-defines the bag as 30kg. History must not retro-mutate.
    MaterialUnit::where('material_id', $this->material->id)
        ->where('unit', 'bag_25kg')
        ->update(['ratio' => 30000]);

    expect((float) $lot->fresh()->qty_on_hand)->toEqual(50000.0);

    // A NEW receive uses the NEW ratio (2 × 30000 = 60000).
    expect($this->stockTx->calculateBaseQuantity(2, 'bag_25kg', $this->material->id))
        ->toEqual(60000.0);
});
