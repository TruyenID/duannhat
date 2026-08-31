<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialBatchService;
use App\Services\Product\AllergenRollupService;
use App\Services\Product\MaterialService;
use App\Services\Product\ProductSkuService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Plan-022 T4.6 — BOM unification regression suite.
 *
 * Asserts that every consumer of "what materials does this batch / order /
 * allergen rollup pull from" reads Recipe.ingredients (post-T3 canonical)
 * and never touches the retired Material.components column. The drop
 * migration ran in T4.5; this suite is the safety net that catches any
 * code path that resurrects the legacy field.
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
        'auto_approve_batch' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);
});

function bomScenarioRecipe(string $orgId, string $brandId, string $materialId, array $ingredients): Recipe
{
    return Recipe::create([
        'sku' => 'R-'.Str::upper(Str::random(6)),
        'name' => 'Test recipe',
        'material_id' => $materialId,
        'output_quantity' => 1,
        'output_unit' => 'g',
        'ingredients' => $ingredients,
        'is_active' => true,
        'approval_status' => 'approved',
        'organization_id' => $orgId,
        'brand_id' => $brandId,
    ]);
}

it('Material.components column is gone from the schema', function () {
    $columns = Schema::getColumnListing('materials');
    expect($columns)->not->toContain('components');
});

it('MaterialBatchService::create derives items from Recipe.ingredients', function () {
    $produced = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $ingredient = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    bomScenarioRecipe($this->orgId, $this->brand->id, $produced->id, [
        ['type' => 'material', 'material_id' => $ingredient->id, 'quantity' => 250, 'unit' => 'g'],
    ]);

    $batch = app(MaterialBatchService::class)->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $produced->id,
        'multiplier' => 1,
        'planned_yield' => 1,
        'yield_unit' => 'g',
        'created_by_id' => (string) Str::uuid(),
    ]);

    $items = $batch->items;

    expect($items)->toHaveCount(1)
        ->and($items->first()->material_id)->toEqual($ingredient->id)
        ->and((float) $items->first()->planned_quantity)->toEqual(250.0);
});

it('MaterialService::checkUsage walks Recipe.ingredients, not components', function () {
    $consumed = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $parent = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    bomScenarioRecipe($this->orgId, $this->brand->id, $parent->id, [
        ['type' => 'material', 'material_id' => $consumed->id, 'quantity' => 1, 'unit' => 'g'],
    ]);

    $usage = app(MaterialService::class)->checkUsage($consumed);

    expect($usage)->toHaveCount(1)
        ->and((string) $usage[0]['id'])->toEqual($parent->id);
});

it('ProductSkuService::checkUsage finds parents via Recipe.ingredients[variant_id]', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    $parent = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
    ]);
    bomScenarioRecipe($this->orgId, $this->brand->id, $parent->id, [
        ['type' => 'variant', 'variant_id' => $sku->id, 'quantity' => 1, 'unit' => 'piece'],
    ]);

    $usage = app(ProductSkuService::class)->checkUsage($sku);

    expect($usage)->toHaveCount(1)
        ->and((string) $usage->first()->id)->toEqual($parent->id);
});

it('AllergenRollupService walks Recipe.ingredients for downstream recompute', function () {
    // Smoke-check that the service does not blow up referencing a dropped
    // `components` column. The full rollup behaviour is covered by the
    // plan-003 allergen suite — this test just guards against regression
    // when T4.5 dropped the column.
    $ingredient = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $parent = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    bomScenarioRecipe($this->orgId, $this->brand->id, $parent->id, [
        ['type' => 'material', 'material_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'g'],
    ]);

    // Should be callable without throwing — pre-T4.5 this would have crashed
    // on `JSON_CONTAINS(components, ?)` against the dropped column.
    expect(fn () => app(AllergenRollupService::class)->recomputeForDownstreamRecipes(
        (string) $ingredient->id,
        (string) $ingredient->organization_id,
    ))->not->toThrow(Throwable::class);
});

it('Material.list response includes active_recipes_count', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    bomScenarioRecipe($this->orgId, $this->brand->id, $material->id, [
        ['type' => 'material', 'material_id' => Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ])->id, 'quantity' => 1, 'unit' => 'g'],
    ]);

    $page = app(MaterialService::class)->list(['organization_id' => $this->orgId]);
    $hit = collect($page->items())->firstWhere('id', $material->id);

    expect($hit)->not->toBeNull()
        ->and((int) $hit->active_recipes_count)->toEqual(1);
});
