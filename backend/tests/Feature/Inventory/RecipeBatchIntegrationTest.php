<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchItem;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\Warehouse;
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
        'auto_approve_batch' => false,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->batches = app(MaterialBatchService::class);
});

function recipeFor(string $orgId, string $brandId, string $materialId, string $status = 'approved', array $ingredients = []): Recipe
{
    return Recipe::create([
        'sku' => 'R-'.Str::upper(Str::random(6)),
        'name' => 'Test recipe',
        'material_id' => $materialId,
        'output_quantity' => 100,
        'output_unit' => 'ml',
        'ingredients' => $ingredients,
        'is_active' => true,
        'approval_status' => $status,
        'approved_at' => $status === 'approved' ? now() : null,
        'organization_id' => $orgId,
        'brand_id' => $brandId,
    ]);
}

it('rejects batch creation when material has no recipe', function () {
    expect(fn () => $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'multiplier' => 1,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
});

it('derives batch items from Recipe.ingredients (not Material.components)', function () {
    $flour = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 200, 'unit' => 'g'],
    ]);

    $batch = $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'multiplier' => 100, // multiplier=output → scale=1
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]);

    $items = $batch->items;

    expect($items)->toHaveCount(1)
        ->and((float) $items->first()->planned_quantity)->toEqual(200.0)
        ->and($items->first()->material_id)->toEqual($flour->id);
});

it('rejects submit when recipe is not approved', function () {
    recipeFor($this->orgId, $this->brand->id, $this->material->id, 'draft');

    // Build batch directly to bypass create()'s recipe-required guard.
    $batch = MaterialBatch::create([
        'organization_id' => $this->orgId,
        'batch_code' => 'B-TEST-001',
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'multiplier' => 1,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'status' => 'draft',
        'created_by_id' => (string) Str::uuid(),
    ]);
    MaterialBatchItem::factory()->create([
        'material_batch_id' => $batch->id,
        'product_sku_id' => null,
        'material_id' => Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ])->id,
        'component_type' => 'material',
        'planned_quantity' => 1,
    ]);

    expect(fn () => $this->batches->submit($batch))
        ->toThrow(ValidationException::class);
});

it('approves batch creation + submit when recipe is approved', function () {
    recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ])->id, 'quantity' => 5, 'unit' => 'g'],
    ]);

    $batch = $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'multiplier' => 100,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]);

    $batch = $this->batches->submit($batch);

    expect($batch->status->value ?? $batch->status)->toEqual('pending');
});

it('blocks approve when recipe is un-approved between submit and approve', function () {
    $recipe = recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ])->id, 'quantity' => 5, 'unit' => 'g'],
    ]);

    $batch = $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'multiplier' => 100,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]);
    $batch = $this->batches->submit($batch);

    $recipe->update(['approval_status' => 'draft']);

    expect(fn () => $this->batches->approve($batch->fresh(), (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

// =========================================================================
//  Plan-022 T3.5 — explicit recipe selection on batch
// =========================================================================

it('T3.5 snapshots the explicit recipe_id on the batch row', function () {
    $component = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $recipe = recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 200, 'unit' => 'g'],
    ]);

    $batch = $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'recipe_id' => $recipe->id,
        'multiplier' => 100,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]);

    expect((string) $batch->recipe_id)->toEqual((string) $recipe->id);
});

it('T3.5 falls back to latest approved recipe when recipe_id is omitted', function () {
    $component = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    // Older recipe — should NOT be picked. Force updated_at via raw query so
    // Eloquent's auto-timestamp doesn't overwrite the back-dated value.
    $older = recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 100, 'unit' => 'g'],
    ]);
    Recipe::where('id', $older->id)->update(['updated_at' => now()->subDays(2)]);
    // Newer recipe — should be picked.
    $newer = recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 300, 'unit' => 'g'],
    ]);

    $batch = $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'multiplier' => 100,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]);

    expect((string) $batch->recipe_id)->toEqual((string) $newer->id);
});

it('T3.5 rejects when recipe_id belongs to a different material', function () {
    $otherMaterial = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $component = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $otherRecipe = recipeFor($this->orgId, $this->brand->id, $otherMaterial->id, 'approved', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 50, 'unit' => 'g'],
    ]);

    expect(fn () => $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'recipe_id' => $otherRecipe->id,
        'multiplier' => 1,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
});

it('T3.5 rejects explicit recipe_id when the recipe is inactive', function () {
    $component = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $recipe = recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 50, 'unit' => 'g'],
    ]);
    $recipe->update(['is_active' => false]);

    expect(fn () => $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'recipe_id' => $recipe->id,
        'multiplier' => 1,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
});

it('T3.5 rejects explicit recipe_id when the recipe is not approved', function () {
    $component = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $draft = recipeFor($this->orgId, $this->brand->id, $this->material->id, 'draft', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 50, 'unit' => 'g'],
    ]);

    expect(fn () => $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'recipe_id' => $draft->id,
        'multiplier' => 1,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]))->toThrow(ValidationException::class);
});

it('T3.5 submit re-validates the snapshotted recipe (not latest)', function () {
    $component = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    // Snapshot: approved recipe v1.
    $v1 = recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 50, 'unit' => 'g'],
    ]);

    $batch = $this->batches->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'recipe_id' => $v1->id,
        'multiplier' => 100,
        'planned_yield' => 100,
        'yield_unit' => 'ml',
        'created_by_id' => (string) Str::uuid(),
    ]);

    // A NEWER approved v2 ships — but submit must re-check v1 (snapshotted),
    // not v2. Flip v1 to draft and submit should fail even though v2 is approved.
    recipeFor($this->orgId, $this->brand->id, $this->material->id, 'approved', [
        ['type' => 'material', 'material_id' => $component->id, 'quantity' => 99, 'unit' => 'g'],
    ]);
    $v1->update(['approval_status' => 'draft']);

    expect(fn () => $this->batches->submit($batch->fresh()))
        ->toThrow(ValidationException::class);
});
