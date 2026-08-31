<?php

/**
 * Plan-018 Group B.3 — Allergen-segregated storage tests.
 */

use App\Models\Allergen;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'alg-'.Str::random(4),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);

    $this->peanutAllergen = Allergen::factory()->create([
        'organization_id' => $this->orgId,
        'name' => 'peanut',
        'code' => 'PEANUT',
        'is_active' => true,
    ]);

    $this->soyAllergen = Allergen::factory()->create([
        'organization_id' => $this->orgId,
        'name' => 'soy',
        'code' => 'SOY',
        'is_active' => true,
    ]);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->material->allergens()->attach([$this->peanutAllergen->id, $this->soyAllergen->id]);

    $this->cleanMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->shopSlug = $this->branch->slug ?? $this->branch->id;
});

it('rejects receive of a peanut-containing material at peanut-forbidden warehouse', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'allergen_policy' => ['forbidden' => ['peanut']],
    ]);

    $response = $this->actingAs($this->user)->postJson(
        "/api/v1/shops/{$this->shopSlug}/material-lots/receive",
        [
            'material_id' => $this->material->id,
            'warehouse_id' => $warehouse->id,
            'received_qty' => 10,
        ]
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['allergen_policy']);
    expect($response->json('errors.allergen_policy.0'))->toContain('allergen_forbidden_at_warehouse');
});

it('allows receive with allergen_override_reason when forbidden allergen present', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'allergen_policy' => ['forbidden' => ['peanut']],
    ]);

    $response = $this->actingAs($this->user)->postJson(
        "/api/v1/shops/{$this->shopSlug}/material-lots/receive",
        [
            'material_id' => $this->material->id,
            'warehouse_id' => $warehouse->id,
            'received_qty' => 10,
            'allergen_override_reason' => 'Special quarantined storage room',
        ]
    );

    $response->assertStatus(201);
});

it('allows receive at warehouse with no allergen_policy', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'allergen_policy' => null,
    ]);

    $response = $this->actingAs($this->user)->postJson(
        "/api/v1/shops/{$this->shopSlug}/material-lots/receive",
        [
            'material_id' => $this->material->id,
            'warehouse_id' => $warehouse->id,
            'received_qty' => 10,
        ]
    );

    $response->assertStatus(201);
});

it('allows receive of clean material at peanut-forbidden warehouse', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'allergen_policy' => ['forbidden' => ['peanut']],
    ]);

    $response = $this->actingAs($this->user)->postJson(
        "/api/v1/shops/{$this->shopSlug}/material-lots/receive",
        [
            'material_id' => $this->cleanMaterial->id,
            'warehouse_id' => $warehouse->id,
            'received_qty' => 10,
        ]
    );

    $response->assertStatus(201);
});
