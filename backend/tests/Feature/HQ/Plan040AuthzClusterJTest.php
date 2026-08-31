<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialSubstitutionRule;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\User;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Policies\MaterialSubstitutionRulePolicy;
use App\Services\Product\MaterialService;
use App\Services\Product\RecipeService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * plan-040 Cluster J — authz / mass-assignment hardening (HQ surface).
 * Covers NEW-MR-1, NEW-MR-2, NEW-MR-4, NEW-MR-5, NEW-MR-6/H6.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'pj40-'.Str::random(5),
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
});

/** Build a valid (draft) recipe with a real output material + one ingredient. */
function pj40MakeRecipe(array $attrs = []): Recipe
{
    $output = Material::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ]);
    $ingredient = Material::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ]);

    return Recipe::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'material_id' => $output->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
        'ingredients' => [
            ['type' => 'material', 'material_id' => $ingredient->id, 'quantity' => 5, 'unit' => 'g'],
        ],
    ], $attrs));
}

// ── NEW-MR-1 — recipe approval forge ──────────────────────────────────────

it('strips approval_status from a recipe PUT (cannot forge approved)', function () {
    $recipe = pj40MakeRecipe();

    $this->putJson("{$this->baseUrl}/recipes/{$recipe->id}", [
        'description' => 'edited',
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'approved_by_id' => $this->user->id,
    ])->assertOk();

    expect($recipe->fresh()->getApprovalStatus())->toBe(ApprovalStatusEnum::Draft);
});

it('rejects approval columns at the RecipeService layer (defence in depth)', function () {
    $recipe = pj40MakeRecipe();

    expect(fn () => app(RecipeService::class)->update($recipe, [
        'approval_status' => ApprovalStatusEnum::Approved->value,
    ]))->toThrow(ValidationException::class);
});

// ── NEW-MR-2 — material/recipe foreign-org brand reassignment ──────────────

it('ignores a foreign-org brand_id on a material PUT (reconciled, not orphaned)', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);

    $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
        'brand_id' => $foreignBrand->id,
    ])->assertOk();

    expect((string) $material->fresh()->brand_id)->toBe((string) $this->brand->id);
});

it('rejects a cross-org brand_id at the MaterialService layer', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);

    expect(fn () => app(MaterialService::class)->update($material, [
        'brand_id' => $foreignBrand->id,
    ]))->toThrow(ValidationException::class);
});

// ── NEW-MR-4 — unit must belong to the route material ──────────────────────

it('returns 404 when updating a unit through the wrong material', function () {
    $materialA = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $materialB = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    $unitOfB = MaterialUnit::factory()->create([
        'material_id' => $materialB->id,
        'unit' => 'box',
        'is_base' => false,
        'ratio' => 10,
    ]);

    $this->putJson("{$this->baseUrl}/materials/{$materialA->id}/units/{$unitOfB->id}", [
        'unit' => 'hacked',
    ])->assertNotFound();
});

it('returns 404 when deleting a unit through the wrong material', function () {
    $materialA = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $materialB = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    $unitOfB = MaterialUnit::factory()->create([
        'material_id' => $materialB->id,
        'unit' => 'crate',
        'is_base' => false,
        'ratio' => 12,
    ]);

    $this->deleteJson("{$this->baseUrl}/materials/{$materialA->id}/units/{$unitOfB->id}")
        ->assertNotFound();
});

// ── NEW-MR-5 — base-unit ratio invariant ───────────────────────────────────

it('pins the base unit ratio to 1 even when a PUT tries to change it', function () {
    $material = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $base = MaterialUnit::factory()->create([
        'material_id' => $material->id,
        'unit' => 'kg',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $this->putJson("{$this->baseUrl}/materials/{$material->id}/units/{$base->id}", [
        'ratio' => 5,
    ])->assertOk();

    expect((float) $base->fresh()->ratio)->toBe(1.0);
});

// ── NEW-MR-6 / H6 — substitution scope + self-sub + policy ─────────────────

it('rejects a self-substitution on update (substitute = primary)', function () {
    $primary = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $substitute = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    $rule = MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $primary->id,
        'substitute_material_id' => $substitute->id,
    ]);

    $this->putJson("{$this->baseUrl}/material-substitution-rules/{$rule->id}", [
        'substitute_material_id' => $primary->id,
    ])->assertStatus(422);
});

it('rejects a cross-org substitute_material_id on store (scoped exists)', function () {
    $primary = Material::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $foreignMaterial = Material::factory()->create([
        'organization_id' => $foreignOrgId,
        'brand_id' => $foreignBrand->id,
    ]);

    $this->postJson("{$this->baseUrl}/material-substitution-rules", [
        'primary_material_id' => $primary->id,
        'substitute_material_id' => $foreignMaterial->id,
        'conversion_factor' => 1,
    ])->assertStatus(422);
});

it('MaterialSubstitutionRulePolicy@create enforces a real org/role check', function () {
    request()->attributes->set('organization_id', $this->orgId);

    $policy = new MaterialSubstitutionRulePolicy;

    // A user with a role in the org passes.
    expect($policy->create($this->user))->toBeTrue();

    // A user with no role assignment in the org is denied (not unconditional true).
    $outsider = User::factory()->create([]);
    expect($policy->create($outsider))->toBeFalse();
});
