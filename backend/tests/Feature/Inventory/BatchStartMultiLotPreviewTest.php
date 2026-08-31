<?php

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
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\MaterialBatchService;
use Illuminate\Support\Str;

/**
 * Plan-022 T9 — start() FEFO preview + complete() multi-lot FEFO split.
 *
 * The core scenario an earlier iteration deleted for flakiness: a single
 * material item whose demand exceeds ANY one lot. The old single-lot
 * `firstWhere(qty_on_hand >= planned)` returned NULL when no single lot
 * covered the item even if total stock did. The greedy multi-lot walk must:
 *   1. start() — preview-stamp the FEFO-first (soonest-expiry) lot.
 *   2. complete() — split real consumption across BOTH lots (FEFO), draining
 *      the earlier lot to exactly 0 and the later lot partially, with exact
 *      quantity conservation and one genealogy edge per consumed lot.
 *
 * Fully self-contained (no shared beforeEach) to avoid cross-test factory
 * `inRandomOrder()->first()` contamination — the documented source of the
 * original flakiness.
 */
function multiLotScenario(float $lotOneQty, float $lotTwoQty): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create(['console_organization_id' => $orgId, 'console_brand_id' => $brand->id]);
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
        'auto_approve_batch' => true,
    ]);

    $ingredient = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $output = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    // Lot #1 expires sooner (FEFO-first). Lot #2 expires later.
    $lotOne = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'source' => 'inbound',
        'unit' => 'kg',
        'received_qty' => $lotOneQty,
        'qty_on_hand' => $lotOneQty,
        'expiry_date' => now()->addDays(2)->toDateString(),
    ]);
    $lotTwo = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'source' => 'inbound',
        'unit' => 'kg',
        'received_qty' => $lotTwoQty,
        'qty_on_hand' => $lotTwoQty,
        'expiry_date' => now()->addDays(10)->toDateString(),
    ]);
    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $ingredient->id,
        'material_lot_id' => $lotOne->id,
        'quantity' => $lotOneQty,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);
    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $ingredient->id,
        'material_lot_id' => $lotTwo->id,
        'quantity' => $lotTwoQty,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    return compact('orgId', 'brand', 'warehouse', 'ingredient', 'output', 'lotOne', 'lotTwo');
}

it('start() preview stamps the FEFO-first lot when no single lot covers the item', function () {
    // 5kg + 8kg lots, item needs 12kg — neither single lot covers it.
    $s = multiLotScenario(lotOneQty: 5, lotTwoQty: 8);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $s['orgId'],
        'warehouse_id' => $s['warehouse']->id,
        'material_id' => $s['output']->id,
        'multiplier' => 1,
        'planned_yield' => 10,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::Approved->value,
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $s['ingredient']->id,
        'planned_quantity' => 12,
        'unit' => 'kg',
    ]);

    app(MaterialBatchService::class)->start($batch->fresh());

    $item = MaterialBatchItem::where('material_batch_id', $batch->id)
        ->whereNotNull('material_id')
        ->first();

    // FEFO-first (soonest expiry) lot is stamped, not NULL.
    expect((string) $item->material_lot_id)->toEqual((string) $s['lotOne']->id);
});

it('complete() splits consumption across both lots with exact qty conservation and one genealogy edge per lot', function () {
    // 5kg + 8kg lots, item consumes 12kg → drain lot#1 to 0, lot#2 to 1.
    $s = multiLotScenario(lotOneQty: 5, lotTwoQty: 8);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $s['orgId'],
        'warehouse_id' => $s['warehouse']->id,
        'material_id' => $s['output']->id,
        'multiplier' => 1,
        'planned_yield' => 10,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $s['ingredient']->id,
        'planned_quantity' => 12,
        'actual_quantity' => 12,
        'unit' => 'kg',
    ]);

    app(MaterialBatchService::class)->complete($batch, (string) Str::uuid());

    $batch->refresh();
    $outputLot = MaterialLot::find($batch->output_lot_id);

    // FEFO split: earlier lot drained to exactly 0, later lot partially.
    $lotOne = $s['lotOne']->fresh();
    $lotTwo = $s['lotTwo']->fresh();
    expect((float) $lotOne->qty_on_hand)->toBe(0.0)   // 5 - 5
        ->and((float) $lotTwo->qty_on_hand)->toBe(1.0); // 8 - 7

    // Conservation: total consumed equals demand (5 + 7 = 12).
    $consumed = (5.0 - (float) $lotOne->qty_on_hand) + (8.0 - (float) $lotTwo->qty_on_hand);
    expect($consumed)->toBe(12.0);

    // One genealogy edge per consumed lot → output lot (FSMA-204 grain).
    $links = GenealogyLink::where('child_lot_id', $outputLot->id)->get();
    expect($links)->toHaveCount(2);
    $byParent = $links->keyBy('parent_lot_id');
    expect($byParent)->toHaveKey((string) $s['lotOne']->id)
        ->and($byParent)->toHaveKey((string) $s['lotTwo']->id);
});
