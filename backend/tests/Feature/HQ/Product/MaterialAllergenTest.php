<?php

use App\Models\Allergen;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Recipe;
use App\Models\User;
use App\Omnify\Enums\ApprovalStatusEnum;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    $this->actingAs($this->user);
});

it('attaches allergen_ids to a material via PUT and GET returns them', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $a1 = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $a2 = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $a3 = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
        'allergen_ids' => [$a1->id, $a2->id],
    ])->assertOk();

    $this->assertDatabaseHas('material_allergens', [
        'material_id' => $material->id,
        'allergen_id' => $a1->id,
    ]);
    $this->assertDatabaseHas('material_allergens', [
        'material_id' => $material->id,
        'allergen_id' => $a2->id,
    ]);
    $this->assertDatabaseMissing('material_allergens', [
        'material_id' => $material->id,
        'allergen_id' => $a3->id,
    ]);

    $response = $this->getJson("{$this->baseUrl}/materials/{$material->id}")->assertOk();
    $allergenIds = collect($response->json('data.allergens'))->pluck('id')->sort()->values()->all();
    expect($allergenIds)->toEqual(collect([$a1->id, $a2->id])->sort()->values()->all());
});

it('recomputes Recipe.allergen_rollup when material allergens change', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $recipe = Recipe::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'allergen_rollup' => [],
    ]);

    $a1 = Allergen::factory()->create(['organization_id' => $this->orgId]);
    $a2 = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
        'allergen_ids' => [$a1->id, $a2->id],
    ])->assertOk();

    $recipe->refresh();
    $rollup = collect($recipe->allergen_rollup)->sort()->values()->all();
    expect($rollup)->toEqual(collect([$a1->id, $a2->id])->sort()->values()->all());
    expect($recipe->allergen_rollup_updated_at)->not->toBeNull();
});

it('blocks allergen delete with 409 ALLERGEN_IN_USE surfacing used_by list', function () {
    $allergen = Allergen::factory()->create(['organization_id' => $this->orgId]);

    $m1 = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $m2 = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $m1->allergens()->attach($allergen->id);
    $m2->allergens()->attach($allergen->id);

    $response = $this->deleteJson("{$this->baseUrl}/allergens/{$allergen->id}")
        ->assertStatus(409)
        ->assertJsonPath('error', 'ALLERGEN_IN_USE');

    $usedByIds = collect($response->json('used_by'))->pluck('id')->sort()->values()->all();
    expect($usedByIds)->toEqual(collect([$m1->id, $m2->id])->sort()->values()->all());
});

it('returns 422 when allergen_ids contains a non-existent id', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
        'allergen_ids' => [fake()->uuid()],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['allergen_ids.0']);
});
