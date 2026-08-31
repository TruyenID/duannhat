<?php

use App\Models\Allergen;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Recipe;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Product\AllergenRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
        'name' => 'Test Org',
        'slug' => 'test-org',
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->service = new AllergenRollupService;
});

it('deduplicates and sorts allergen ids from overlapping materials', function () {
    $a1 = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $a2 = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $a3 = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $m1 = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $m2 = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $m3 = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    // Overlap: a1 on both m1 and m2; a2 only on m2; a3 only on m3.
    $m1->allergens()->attach([$a1->id]);
    $m2->allergens()->attach([$a1->id, $a2->id]);
    $m3->allergens()->attach([$a3->id]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $m1->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [
            ['material_id' => $m2->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw'],
            ['material_id' => $m3->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw'],
        ],
    ]);

    $rollup = $this->service->compute($recipe);
    $expected = collect([(string) $a1->id, (string) $a2->id, (string) $a3->id])
        ->sort()
        ->values()
        ->all();

    expect($rollup)->toEqual($expected);
    // Ensure dedup: the length must be exactly 3 even though a1 appears on both m1 & m2.
    expect($rollup)->toHaveCount(3);
});

it('filters out soft-deleted allergens from the rollup', function () {
    $alive = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $trashed = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $material->allergens()->attach([$alive->id, $trashed->id]);

    $trashed->delete();

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [],
    ]);

    $rollup = $this->service->compute($recipe);

    expect($rollup)->toEqual([(string) $alive->id]);
});

it('returns empty array when recipe has no material_id and no ingredients', function () {
    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [],
    ]);

    expect($this->service->compute($recipe))->toBe([]);
});

it('cascades allergens through a compound material sub-recipe', function () {
    // Raw milk carries the "milk" allergen.
    $milkAllergen = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $rawMilk = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $rawMilk->allergens()->attach([$milkAllergen->id]);

    // "House sauce" is a compound (produced) material with NO directly declared
    // allergens — it is built from raw milk via its own sub-recipe.
    $sauce = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $sauce->id, // sub-recipe produces the sauce
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [
            ['material_id' => $rawMilk->id, 'quantity' => 1, 'unit' => 'l', 'type' => 'raw'],
        ],
    ]);

    // Top-level dish uses the sauce as an ingredient. The sauce itself declares
    // no allergen, so a non-cascading rollup would under-declare milk.
    $dish = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $topRecipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $dish->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [
            ['material_id' => $sauce->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw'],
        ],
    ]);

    expect($this->service->compute($topRecipe))->toEqual([(string) $milkAllergen->id]);
});

it('cascades allergens through two nested compound layers without looping on cycles', function () {
    $wheat = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $flour = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $flour->allergens()->attach([$wheat->id]);

    // Layer 1: dough is produced from flour.
    $dough = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $dough->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [['material_id' => $flour->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw']],
    ]);

    // Layer 2: base is produced from dough.
    $base = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $base->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [['material_id' => $dough->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw']],
    ]);

    // Top: pizza uses the base.
    $pizza = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => null,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [['material_id' => $base->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw']],
    ]);

    expect($this->service->compute($pizza))->toEqual([(string) $wheat->id]);
});

it('recomputes a downstream recipe that reaches the changed material only through a compound layer', function () {
    $milk = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $rawMilk = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    $sauce = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $sauce->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [['material_id' => $rawMilk->id, 'quantity' => 1, 'unit' => 'l', 'type' => 'raw']],
    ]);

    $dish = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $topRecipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $dish->id,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'ingredients' => [['material_id' => $sauce->id, 'quantity' => 1, 'unit' => 'kg', 'type' => 'raw']],
        'allergen_rollup' => [],
    ]);

    // Milk gains its allergen AFTER the recipes exist. The top recipe touches
    // milk only via the compound sauce, so a direct-reference-only recompute
    // would miss it.
    $rawMilk->allergens()->attach([$milk->id]);

    $changed = $this->service->recomputeForDownstreamRecipes((string) $rawMilk->id, (string) $rawMilk->organization_id);

    expect($changed->pluck('id'))->toContain($topRecipe->id);
    expect($topRecipe->fresh()->allergen_rollup)->toEqual([(string) $milk->id]);
});

it('returns empty array when materials have zero allergens', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [],
    ]);

    expect($this->service->compute($recipe))->toBe([]);
});
