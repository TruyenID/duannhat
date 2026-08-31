<?php

/**
 * plan-040 Cluster H — TH.9 (NEW-BP-4): updating a draft batch's `multiplier`
 * alone must re-expand the BOM items from the snapshotted recipe so a later
 * complete() can't consume stale (pre-change) quantities.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\Warehouse;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Inventory\MaterialBatchService;
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
    ]);
    $this->component = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->outputMaterial = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->service = app(MaterialBatchService::class);
});

it('re-expands BOM item quantities when a draft batch multiplier is updated alone (NEW-BP-4)', function () {
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->outputMaterial->id,
        'output_quantity' => 1,
        'output_unit' => 'kg',
        'is_active' => true,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'approved_at' => now()->subHour(),
        'ingredients' => [
            ['type' => 'material', 'material_id' => $this->component->id, 'quantity' => 10, 'unit' => 'kg'],
        ],
    ]);

    // Draft batch with the BOM auto-expanded at multiplier 1 → planned 10.
    $batch = $this->service->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->outputMaterial->id,
        'recipe_id' => $recipe->id,
        'multiplier' => 1,
        'planned_yield' => 1,
        'yield_unit' => 'kg',
        'created_by_id' => (string) Str::uuid(),
    ]);

    expect($batch->items)->toHaveCount(1)
        ->and((float) $batch->items->first()->planned_quantity)->toBe(10.0);

    // Bump multiplier alone (no items in the payload).
    $updated = $this->service->update($batch, ['multiplier' => 2]);

    expect($updated->items)->toHaveCount(1)
        ->and((float) $updated->items->first()->planned_quantity)->toBe(20.0)
        ->and($updated->items->first()->material_id)->toBe($this->component->id);
});
