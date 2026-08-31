<?php

/**
 * plan-040 Cluster H — MaterialBatchService cost basis + yield (TH.5/TH.6/TH.7).
 *
 *  - H8:       cross-currency consumed lots are rejected at complete(), not
 *              summed bare.
 *  - H8:       a null lot cost_basis_amount is NOT treated as 0 (output
 *              unit_cost stays null rather than being diluted).
 *  - M8:       yield variance is measured against the recipe baseline
 *              (output_quantity × multiplier), not the free-typed planned_yield.
 *  - NEW-BP-1: getCostBreakdown normalises the yield through
 *              calculateBaseQuantity so the panel unit_cost matches the lot.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\MaterialBatchStatusEnum;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\MaterialBatchService;
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
        'auto_approve_batch' => true,
    ]);
    $this->compA = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->compB = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->outputMaterial = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->service = app(MaterialBatchService::class);
});

/**
 * Seed an active lot + matching StockLevel for one component.
 */
function seedCostLot(
    string $orgId,
    string $brandId,
    string $warehouseId,
    Material $material,
    float $qty,
    ?float $unitCost,
    ?string $currency,
): MaterialLot {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => $material->id,
        'warehouse_id' => $warehouseId,
        'status' => MaterialLotStatusEnum::Active->value,
        'received_qty' => $qty,
        'qty_on_hand' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => $unitCost !== null ? $unitCost * $qty : null,
        'currency' => $currency,
        'cost_basis' => $unitCost !== null ? 'manual' : null,
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    StockLevel::create([
        'warehouse_id' => $warehouseId,
        'material_id' => $material->id,
        'material_lot_id' => $lot->id,
        'quantity' => $qty,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    return $lot;
}

/**
 * Build an in-progress batch consuming compA + compB with the given yield_unit.
 */
function makeCostBatch(object $self, float $plannedYield, string $yieldUnit, ?string $recipeId = null): MaterialBatch
{
    $batch = MaterialBatch::factory()->create([
        'organization_id' => $self->orgId,
        'warehouse_id' => $self->warehouse->id,
        'material_id' => $self->outputMaterial->id,
        'recipe_id' => $recipeId,
        'multiplier' => 1,
        'planned_yield' => $plannedYield,
        'yield_unit' => $yieldUnit,
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $self->compA->id,
        'planned_quantity' => 10,
        'actual_quantity' => 10,
        'unit' => 'kg',
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $self->compB->id,
        'planned_quantity' => 5,
        'actual_quantity' => 5,
        'unit' => 'kg',
    ]);

    return $batch;
}

it('rejects completion when consumed lots span multiple currencies (H8)', function () {
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compA, 10, 100, 'JPY');
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compB, 5, 200, 'USD');

    $batch = makeCostBatch($this, plannedYield: 10, yieldUnit: 'kg');

    expect(fn () => $this->service->complete($batch, (string) Str::uuid(), actualYield: 10))
        ->toThrow(ValidationException::class, 'multiple currencies');

    // Batch must remain in-progress (transaction rolled back).
    $batch->refresh();
    expect($batch->status->value)->toBe(MaterialBatchStatusEnum::InProgress->value);
});

it('does not treat a null lot cost as 0 — output unit_cost stays null (H8)', function () {
    // compA costed, compB has no cost basis at all.
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compA, 10, 100, 'JPY');
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compB, 5, null, null);

    $batch = makeCostBatch($this, plannedYield: 10, yieldUnit: 'kg');
    $this->service->complete($batch, (string) Str::uuid(), actualYield: 10);
    $batch->refresh();

    $outputLot = MaterialLot::find($batch->output_lot_id);
    // Not 100.0 (which is what summing 1000 + 0 / 10 would give).
    expect($outputLot->unit_cost)->toBeNull();
});

it('measures yield variance against the recipe baseline, not planned_yield (M8)', function () {
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compA, 10, 100, 'JPY');
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compB, 5, 200, 'JPY');

    // Recipe baseline = output_quantity(10) × multiplier(1) = 10. The operator
    // mistyped planned_yield = 8 (would mask the loss against planned). Actual
    // yield 8 is a real 20% loss vs the baseline → reason required.
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->outputMaterial->id,
        'output_quantity' => 10,
        'output_unit' => 'kg',
        'yield_variance_tolerance_pct' => 5,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'approved_at' => now()->subHour(),
    ]);

    $batch = makeCostBatch($this, plannedYield: 8, yieldUnit: 'kg', recipeId: $recipe->id);

    expect(fn () => $this->service->complete($batch, (string) Str::uuid(), actualYield: 8))
        ->toThrow(ValidationException::class, 'reason is required');
});

it('agrees on output unit_cost between the lot and getCostBreakdown when yield_unit != base unit (NEW-BP-1)', function () {
    // Output material base unit = ml, yield entered in L (ratio 1000).
    MaterialUnit::factory()->create([
        'material_id' => $this->outputMaterial->id,
        'unit' => 'ml',
        'ratio' => 1,
        'is_base' => true,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $this->outputMaterial->id,
        'unit' => 'L',
        'ratio' => 1000,
        'is_base' => false,
    ]);

    // Total consumed cost = 10*100 + 5*200 = 2000. Yield 2 L = 2000 ml.
    // Expected unit_cost = 2000 / 2000 = 1.0 per ml.
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compA, 10, 100, 'JPY');
    seedCostLot($this->orgId, $this->brand->id, $this->warehouse->id, $this->compB, 5, 200, 'JPY');

    $batch = makeCostBatch($this, plannedYield: 2, yieldUnit: 'L');
    $this->service->complete($batch, (string) Str::uuid(), actualYield: 2);
    $batch->refresh();

    $outputLot = MaterialLot::find($batch->output_lot_id);
    $breakdown = $this->service->getCostBreakdown($batch);

    expect((float) $outputLot->unit_cost)->toBe(1.0)
        ->and($breakdown['output_unit_cost'])->toBe(1.0)
        ->and($breakdown['yield_qty'])->toBe(2000.0);
});
