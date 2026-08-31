<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\MaterialLot;
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

/**
 * Plan-022 (yield variance) — when actual yield drifts past the recipe's
 * tolerance %, complete() must reject the request unless a reason is supplied.
 * The tolerance is per-recipe so HQ can dial it in per dish.
 */
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
    $this->component = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->outputMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->service = app(MaterialBatchService::class);
});

/**
 * Build the standard fixture: an approved recipe with the given tolerance,
 * an active component lot at the warehouse, and an in-progress batch that
 * planned for 10 kg of output. Returns the batch ready for ->complete().
 */
function makeVarianceBatch(float $tolerancePct, float $plannedYield = 10): MaterialBatch
{
    $self = test();
    $recipe = Recipe::factory()->create([
        'organization_id' => $self->orgId,
        'brand_id' => $self->brand->id,
        'material_id' => $self->outputMaterial->id,
        'output_quantity' => $plannedYield,
        'output_unit' => 'kg',
        'yield_variance_tolerance_pct' => $tolerancePct,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'approved_at' => now()->subHour(),
    ]);

    $lot = MaterialLot::factory()->create([
        'organization_id' => $self->orgId,
        'brand_id' => $self->brand->id,
        'material_id' => $self->component->id,
        'warehouse_id' => $self->warehouse->id,
        'status' => MaterialLotStatusEnum::Active->value,
        'received_qty' => 100,
        'qty_on_hand' => 100,
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    StockLevel::create([
        'warehouse_id' => $self->warehouse->id,
        'material_id' => $self->component->id,
        'material_lot_id' => $lot->id,
        'quantity' => 100,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $batch = MaterialBatch::factory()->create([
        'organization_id' => $self->orgId,
        'warehouse_id' => $self->warehouse->id,
        'material_id' => $self->outputMaterial->id,
        'recipe_id' => $recipe->id,
        'multiplier' => 1,
        'planned_yield' => $plannedYield,
        'yield_unit' => 'kg',
        'status' => MaterialBatchStatusEnum::InProgress->value,
        'started_at' => now()->subHour(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'component_type' => 'material',
        'material_id' => $self->component->id,
        'planned_quantity' => 20,
        'actual_quantity' => 20,
        'unit' => 'kg',
    ]);

    return $batch;
}

it('accepts actual_yield within tolerance without a reason', function () {
    // Tolerance 5%, planned 10kg, actual 9.6kg → variance 4% < 5% → no reason needed.
    $batch = makeVarianceBatch(tolerancePct: 5);

    $this->service->complete($batch, (string) Str::uuid(), actualYield: 9.6);

    $batch->refresh();
    expect($batch->status->value)->toBe(MaterialBatchStatusEnum::Completed->value)
        ->and((float) $batch->actual_yield)->toBe(9.6)
        ->and($batch->yield_variance_reason)->toBeNull();
});

it('rejects actual_yield outside tolerance when reason is missing', function () {
    // Tolerance 5%, planned 10kg, actual 8kg → variance 20% > 5% → must throw.
    $batch = makeVarianceBatch(tolerancePct: 5);

    $this->service->complete($batch, (string) Str::uuid(), actualYield: 8);
})->throws(ValidationException::class, 'reason is required');

it('accepts actual_yield outside tolerance when reason is supplied', function () {
    // Same setup but with a reason — should persist the reason on the batch.
    $batch = makeVarianceBatch(tolerancePct: 5);

    $this->service->complete(
        $batch,
        (string) Str::uuid(),
        actualYield: 8,
        varianceReason: 'Boiled too long, lost 2kg to evaporation',
    );

    $batch->refresh();
    expect($batch->status->value)->toBe(MaterialBatchStatusEnum::Completed->value)
        ->and((float) $batch->actual_yield)->toBe(8.0)
        ->and($batch->yield_variance_reason)->toBe('Boiled too long, lost 2kg to evaporation');
});

it('treats default tolerance=0 as strict: any drift requires a reason', function () {
    // Tolerance 0, planned 10kg, actual 9.999kg → any non-zero variance fails.
    $batch = makeVarianceBatch(tolerancePct: 0);

    $this->service->complete($batch, (string) Str::uuid(), actualYield: 9.999);
})->throws(ValidationException::class, 'reason is required');

it('clears yield_variance_reason when actual matches planned', function () {
    // Even if operator types a reason, BE strips it when within tolerance —
    // keeps the audit field meaningful (NULL = no variance worth recording).
    $batch = makeVarianceBatch(tolerancePct: 5);

    $this->service->complete(
        $batch,
        (string) Str::uuid(),
        actualYield: 10,
        varianceReason: 'this should be cleared',
    );

    $batch->refresh();
    expect($batch->yield_variance_reason)->toBeNull();
});
