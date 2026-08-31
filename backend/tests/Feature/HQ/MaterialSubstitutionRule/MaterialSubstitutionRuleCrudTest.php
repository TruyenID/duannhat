<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialSubstitutionRule;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'msr-'.Str::random(4),
    ]);

    $this->primary = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->substitute = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/material-substitution-rules";
});

it('lists rules filtered by primary material, priority-ordered', function () {
    $other = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'priority' => 5,
    ]);
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $other->id,
        'priority' => 1,
    ]);
    // A rule for a different primary must not leak in.
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->substitute->id,
        'substitute_material_id' => $this->primary->id,
    ]);

    $response = $this->getJson("{$this->baseUrl}?primary_material_id={$this->primary->id}")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.priority'))->toBe(1);
});

it('creates a rule, stamping organization and brand from context', function () {
    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'conversion_factor' => 1.25,
        'priority' => 2,
        'is_active' => true,
        'notes' => 'use when out of stock',
    ])
        ->assertCreated()
        ->assertJsonPath('data.substitute_material_id', $this->substitute->id)
        ->assertJsonPath('data.organization_id', $this->orgId)
        ->assertJsonPath('data.brand_id', $this->brand->id);

    expect(MaterialSubstitutionRule::where('primary_material_id', $this->primary->id)->count())->toBe(1);
});

it('persists the opt-in flag + temporal window and defaults auto_substitute off', function () {
    // Omitting auto_substitute leaves it off-by-default (BS-04 / Finding 4).
    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'conversion_factor' => 1,
    ])->assertCreated()->assertJsonPath('data.auto_substitute', false);

    $other = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $other->id,
        'conversion_factor' => 1,
        'auto_substitute' => true,
        'valid_from' => '2026-01-01 00:00:00',
        'valid_until' => '2026-12-31 23:59:59',
    ])->assertCreated()->assertJsonPath('data.auto_substitute', true);

    $rule = MaterialSubstitutionRule::where('substitute_material_id', $other->id)->first();
    expect($rule->auto_substitute)->toBeTrue()
        ->and($rule->valid_from->toDateString())->toBe('2026-01-01')
        ->and($rule->valid_until->toDateString())->toBe('2026-12-31');
});

it('rejects a validity window that ends before it starts', function () {
    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'conversion_factor' => 1,
        'valid_from' => '2026-12-31 00:00:00',
        'valid_until' => '2026-01-01 00:00:00',
    ])->assertStatus(422)->assertJsonValidationErrors('valid_until');
});

it('rejects a material substituting for itself', function () {
    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->primary->id,
        'conversion_factor' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('substitute_material_id');
});

it('rejects a non-positive conversion factor', function () {
    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'conversion_factor' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors('conversion_factor');
});

it('rejects a duplicate active rule for the same material pair', function () {
    MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
    ]);

    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'conversion_factor' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('substitute_material_id');
});

it('resurrects a soft-deleted rule instead of hitting the unique constraint', function () {
    $rule = MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'conversion_factor' => 1,
    ]);
    $rule->delete();

    $this->postJson($this->baseUrl, [
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'conversion_factor' => 2,
    ])->assertCreated()->assertJsonPath('data.id', $rule->id);

    expect(MaterialSubstitutionRule::where('primary_material_id', $this->primary->id)->count())->toBe(1)
        ->and((float) MaterialSubstitutionRule::find($rule->id)->conversion_factor)->toBe(2.0);
});

it('updates a rule', function () {
    $rule = MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
        'priority' => 0,
    ]);

    $this->putJson("{$this->baseUrl}/{$rule->id}", [
        'priority' => 9,
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.priority', 9)
        ->assertJsonPath('data.is_active', false);
});

it('deletes a rule', function () {
    $rule = MaterialSubstitutionRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'primary_material_id' => $this->primary->id,
        'substitute_material_id' => $this->substitute->id,
    ]);

    $this->deleteJson("{$this->baseUrl}/{$rule->id}")->assertNoContent();

    expect(MaterialSubstitutionRule::find($rule->id))->toBeNull();
});

it('blocks access to a rule from another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherMaterial = Material::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
    ]);

    $foreignRule = MaterialSubstitutionRule::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
        'primary_material_id' => $otherMaterial->id,
        'substitute_material_id' => $otherMaterial->id,
    ]);

    $this->deleteJson("{$this->baseUrl}/{$foreignRule->id}")->assertForbidden();
});
