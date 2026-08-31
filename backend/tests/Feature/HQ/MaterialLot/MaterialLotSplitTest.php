<?php

/**
 * Plan-018 Group B.2 — Lot splitting tests.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\MaterialLotService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'spl-'.Str::random(4),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->warehouse2 = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    // plan-040 NEW-LOT-5: split() now emits a backing StockTransaction whose
    // created_by_id is NOT NULL; the service falls back to auth()->id().
    $this->actingAs(User::factory()->create(['console_organization_id' => $this->orgId]));

    $this->service = app(MaterialLotService::class);
});

it('splits a lot into 3 children with correct genealogy', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 25,
        'received_qty' => 25,
        'lot_code' => 'LOT-SPLIT-001',
        'effective_allergens' => ['peanut', 'soy'],
    ]);

    $result = $this->service->split($lot, [
        ['qty' => 10],
        ['qty' => 10],
        ['qty' => 5],
    ], 'Repackaging from 25kg sack');

    expect($result['parent']->qty_on_hand)->toBe('0.0000');
    expect($result['parent']->status->value)->toBe(MaterialLotStatusEnum::Depleted->value);
    expect($result['children'])->toHaveCount(3);

    expect($result['children'][0]->lot_code)->toBe('LOT-SPLIT-001-S1');
    expect((float) $result['children'][0]->qty_on_hand)->toBe(10.0);
    expect($result['children'][1]->lot_code)->toBe('LOT-SPLIT-001-S2');
    expect($result['children'][2]->lot_code)->toBe('LOT-SPLIT-001-S3');
    expect((float) $result['children'][2]->qty_on_hand)->toBe(5.0);

    $links = GenealogyLink::where('parent_lot_id', $lot->id)
        ->where('source_event_type', 'split')
        ->get();
    expect($links)->toHaveCount(3);
});

it('keeps the remainder on the parent when only part of the lot is split off', function () {
    // Regression: a 450 lot split into 300 + 100 = 400 must leave 50 on the
    // parent (Active), not deplete it to 0 and lose the remainder.
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 450,
        'received_qty' => 450,
    ]);

    $result = $this->service->split($lot, [
        ['qty' => 300],
        ['qty' => 100],
    ]);

    expect((float) $result['parent']->qty_on_hand)->toBe(50.0);
    expect($result['parent']->status->value)->toBe(MaterialLotStatusEnum::Active->value);
    expect((float) $result['children'][0]->qty_on_hand)->toBe(300.0);
    expect((float) $result['children'][1]->qty_on_hand)->toBe(100.0);
});

it('continues the child code sequence when a lot is split again', function () {
    // Regression: re-splitting the leftover (now that the parent stays Active)
    // restarted child codes at -S1 and collided on the (org, lot_code) unique.
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
        'received_qty' => 100,
        'lot_code' => 'LOT-RESPLIT-001',
    ]);

    $first = $this->service->split($lot, [['qty' => 20], ['qty' => 30]]);
    expect($first['children'][0]->lot_code)->toBe('LOT-RESPLIT-001-S1');
    expect($first['children'][1]->lot_code)->toBe('LOT-RESPLIT-001-S2');
    expect((float) $first['parent']->qty_on_hand)->toBe(50.0);

    // Split the still-Active parent again — codes must continue at S3/S4.
    $second = $this->service->split($first['parent'], [['qty' => 10], ['qty' => 15]]);
    expect($second['children'][0]->lot_code)->toBe('LOT-RESPLIT-001-S3');
    expect($second['children'][1]->lot_code)->toBe('LOT-RESPLIT-001-S4');
    expect((float) $second['parent']->qty_on_hand)->toBe(25.0);
});

it('rejects split when sum exceeds qty_on_hand', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 20,
    ]);

    expect(fn () => $this->service->split($lot, [
        ['qty' => 15],
        ['qty' => 10],
    ]))->toThrow(ValidationException::class);
});

it('supports cross-warehouse split via target_warehouse_id', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 20,
    ]);

    $result = $this->service->split($lot, [
        ['qty' => 10],
        ['qty' => 10, 'target_warehouse_id' => $this->warehouse2->id],
    ]);

    expect($result['children'][0]->warehouse_id)->toBe($this->warehouse->id);
    expect($result['children'][1]->warehouse_id)->toBe($this->warehouse2->id);
});

it('mirrors the split into stock_levels: children get rows, parent shrinks', function () {
    // Regression: split() only mutated material_lots, never stock_levels.
    // FEFO consumption picks lots by qty_on_hand but the per-lot stock_level
    // guard reads stock_levels.quantity — so the child lots (no stock_level
    // row) were seen as qty 0, producing a spurious INSUFFICIENT_STOCK with
    // available:0 on the next consumption that touched them.
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
        'received_qty' => 100,
        'unit' => 'g',
    ]);
    // Parent's per-lot stock_level as the receive pipeline would have created.
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 100,
        'unit' => 'g',
    ]);

    $result = $this->service->split($lot, [['qty' => 30], ['qty' => 20]]);

    // Parent shrinks to the remainder (100 − 50).
    $parentLevel = StockLevel::where('material_lot_id', $lot->id)->first();
    expect((float) $parentLevel->quantity)->toBe(50.0);

    // Each child lot now has a matching stock_level equal to its qty_on_hand.
    foreach ($result['children'] as $child) {
        $childLevel = StockLevel::where('material_lot_id', $child->id)->first();
        expect($childLevel)->not->toBeNull()
            ->and((float) $childLevel->quantity)->toBe((float) $child->qty_on_hand);
    }

    // Ledger total stays conserved across the split (50 + 30 + 20 = 100).
    $total = StockLevel::where('material_id', $this->material->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->sum('quantity');
    expect((float) $total)->toBe(100.0);
});

it('creates child stock_levels in the target warehouse on cross-warehouse split', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 20,
        'received_qty' => 20,
        'unit' => 'g',
    ]);

    $result = $this->service->split($lot, [
        ['qty' => 10, 'target_warehouse_id' => $this->warehouse2->id],
    ]);

    $childLevel = StockLevel::where('material_lot_id', $result['children'][0]->id)->first();
    expect($childLevel)->not->toBeNull()
        ->and($childLevel->warehouse_id)->toBe($this->warehouse2->id)
        ->and((float) $childLevel->quantity)->toBe(10.0);
});

it('children inherit parent effective_allergens snapshot', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 10,
        'effective_allergens' => ['peanut', 'gluten'],
    ]);

    $result = $this->service->split($lot, [['qty' => 5], ['qty' => 5]]);

    expect($result['children'][0]->effective_allergens)->toBe(['peanut', 'gluten']);
    expect($result['children'][1]->effective_allergens)->toBe(['peanut', 'gluten']);
});
