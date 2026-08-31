<?php

use App\Exceptions\ComponentInUseException;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\User;
use App\Services\Product\MaterialService;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";

    $this->actingAs($this->user);
});

// =========================================================================
//  Index
// =========================================================================

describe('index', function () {
    it('lists materials for the user organization', function () {
        Material::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/materials")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('filters materials by search', function () {
        Material::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Wheat Flour',
            'brand_id' => $this->brand->id,
        ]);
        Material::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Sugar',
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/materials?search=Wheat")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Wheat Flour');
    });

    it('filters materials by status (is_active)', function () {
        $active = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => true,
        ]);
        $inactive = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => false,
        ]);

        $this->getJson("{$this->baseUrl}/materials?is_active=0")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);

        $this->getJson("{$this->baseUrl}/materials?is_active=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        // No filter => both rows returned (the bug: status was always ignored).
        $this->getJson("{$this->baseUrl}/materials")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters materials by kind (raw vs produced)', function () {
        $raw = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'yield_unit' => null,
        ]);
        $produced = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'yield_unit' => 'kg',
        ]);

        $this->getJson("{$this->baseUrl}/materials?kind=raw")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $raw->id);

        $this->getJson("{$this->baseUrl}/materials?kind=produced")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $produced->id);
    });

    it('does not leak materials from a sibling brand in the same org', function () {
        $siblingBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Current Brand Flour',
        ]);
        Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $siblingBrand->id,
            'name' => 'Sibling Brand Sugar',
        ]);

        $response = $this->getJson("{$this->baseUrl}/materials")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0.name'))->toBe('Current Brand Flour');
    });

    it('does not leak materials from a sibling brand on lookup', function () {
        $siblingBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Current Brand Flour',
            'is_active' => true,
        ]);
        Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $siblingBrand->id,
            'name' => 'Sibling Brand Sugar',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->baseUrl}/materials/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0.name'))->toBe('Current Brand Flour');
    });
});

// =========================================================================
//  Store
// =========================================================================

describe('store', function () {
    it('creates a material with name', function () {
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'name' => 'Brown Rice',
            'yield_quantity' => 1,
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Brown Rice');

        $this->assertDatabaseHas('material_translations', [
            'name' => 'Brown Rice',
        ]);
    });

    it('rounds yield_quantity to 4 decimals (decimal:4 cast) instead of rejecting extra precision', function () {
        // `numeric` validation accepts any precision; the decimal:4 cast +
        // decimal(15,4) column round on the way out. 1.23456 -> 1.2346.
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'name' => 'Precise Yield',
            'yield_unit' => 'kg',
            'yield_quantity' => 1.23456,
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.yield_quantity', '1.2346');
    });

    it('rejects a negative yield_quantity (plan-040 F3)', function () {
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'name' => 'Negative Yield',
            'yield_quantity' => -5,
            'yield_unit' => 'g',
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('yield_quantity');
    });

    it('rejects a zero yield_quantity (plan-040 F3)', function () {
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'name' => 'Zero Yield',
            'yield_quantity' => 0,
            'yield_unit' => 'g',
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('yield_quantity');
    });

    it('forbids a shop-staff user from creating a material (plan-040 F2)', function () {
        // Shop Staff is view-only per the seeded RBAC matrix (no material.create).
        // Previously MaterialPolicy@create returned true and let them through.
        $staff = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        $staff->assignRole('shop-staff', $this->orgId);

        $this->actingAs($staff)
            ->postJson("{$this->baseUrl}/materials", [
                'name' => 'Blocked by RBAC',
                'yield_quantity' => 1,
                'calculated_cost' => 0,
                'brand_id' => $this->brand->id,
            ])
            ->assertForbidden();

        expect(Material::where('organization_id', $this->orgId)->count())->toBe(0);
    });

    it('allows an HQ-tier role (org-admin) to create — F2 control', function () {
        // $this->user holds org-admin via grantOrgAccess → has material.create.
        $this->postJson("{$this->baseUrl}/materials", [
            'name' => 'Allowed by RBAC',
            'yield_quantity' => 1,
            'calculated_cost' => 0,
            'brand_id' => $this->brand->id,
        ])->assertCreated();
    });

    it('returns a 422 with field-keyed errors the create form can render inline', function () {
        // TC-M2-09 — the admin-web create form reads err.body.errors[field] and
        // paints a red message under that field. Guard the contract: an invalid
        // payload must come back 422 with the errors object keyed by field name.
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'name' => str_repeat('x', 256), // exceeds max:255
            'yield_quantity' => 1,
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name')
            ->assertJsonStructure(['message', 'errors' => ['name']]);
    });

    it('flags a missing name in every language under ja.name', function () {
        // No name at any locale → the after-validator attaches the error to ja.name.
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'yield_quantity' => 1,
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ja.name');
    });

    it('auto-generates SKU when not provided', function () {
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'name' => 'Auto SKU Material',
            'yield_quantity' => 1,
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])->assertCreated();

        $material = Material::whereTranslation('name', 'Auto SKU Material')->first();

        expect($material->sku)->not->toBeEmpty();
    });

    it('validates that name is required', function () {
        $this->postJson("{$this->baseUrl}/materials", [
            'organization_id' => $this->orgId,
            'yield_quantity' => 1,
            'calculated_cost' => 0,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ja.name']);
    });
});

// =========================================================================
//  Show
// =========================================================================

describe('show', function () {
    it('returns a material with relations', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/materials/{$material->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $material->id)
            ->assertJsonStructure(['data' => ['id', 'name', 'sku']]);
    });
});

// =========================================================================
//  Update
// =========================================================================

describe('update', function () {
    it('updates the material name and description', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Old Name',
            'description' => 'Old description',
            'brand_id' => $this->brand->id,
        ]);

        $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
            'name' => 'New Name',
            'description' => 'New description',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.description', 'New description');
    });

    it('rejects a negative yield_quantity on update (plan-040 F3)', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'yield_quantity' => 100,
        ]);

        $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
            'yield_quantity' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('yield_quantity');

        expect($material->fresh()->yield_quantity)->toEqual('100.0000');
    });

    it('rejects an out-of-range temperature with a clean 422 instead of leaking SQL', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        // temperature_max is decimal(5,2) — 9999 overflows the column. Without
        // a validation bound it surfaced as a raw QueryException (leaking the
        // SQL statement to the client). It must now be a validation error.
        $this->putJson("{$this->baseUrl}/materials/{$material->id}", [
            'temperature_max' => 9999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['temperature_max']);
    });
});

// =========================================================================
//  Destroy
// =========================================================================

describe('destroy', function () {
    it('soft deletes a material', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $service = Mockery::mock(MaterialService::class)->makePartial();
        $service->shouldReceive('delete')->once()->andReturnUsing(function (Material $m) {
            return $m->delete();
        });
        $this->app->instance(MaterialService::class, $service);

        $this->deleteJson("{$this->baseUrl}/materials/{$material->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('materials', ['id' => $material->id]);
    });

    it('prevents delete if used by other materials', function () {
        $materialA = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $materialB = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $service = Mockery::mock(MaterialService::class)->makePartial();
        $service->shouldReceive('delete')->once()->andThrow(
            new ComponentInUseException(
                'Cannot delete material: it is used by other materials.',
                [['id' => $materialB->id, 'name' => $materialB->name]]
            )
        );
        $this->app->instance(MaterialService::class, $service);

        $this->deleteJson("{$this->baseUrl}/materials/{$materialA->id}")
            ->assertUnprocessable()
            ->assertJsonPath('error', 'COMPONENT_IN_USE')
            ->assertJsonPath('used_by.0.id', $materialB->id);
    });
});

// =========================================================================
//  Check Usage
// =========================================================================

describe('check usage', function () {
    it('returns materials referencing this material', function () {
        $materialA = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $materialB = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $service = Mockery::mock(MaterialService::class)->makePartial();
        $service->shouldReceive('checkUsage')->once()->andReturn([
            ['id' => $materialB->id, 'name' => $materialB->name],
        ]);
        $this->app->instance(MaterialService::class, $service);

        $this->getJson("{$this->baseUrl}/materials/{$materialA->id}/check-usage")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('returns empty when material is not used', function () {
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $service = Mockery::mock(MaterialService::class)->makePartial();
        $service->shouldReceive('checkUsage')->once()->andReturn([]);
        $this->app->instance(MaterialService::class, $service);

        $this->getJson("{$this->baseUrl}/materials/{$material->id}/check-usage")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

// =========================================================================
//  Lookup
// =========================================================================

describe('lookup', function () {
    it('returns only active materials', function () {
        Material::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => true,
            'brand_id' => $this->brand->id,
        ]);
        Material::factory()->create([
            'organization_id' => $this->orgId,
            'is_active' => false,
            'brand_id' => $this->brand->id,
        ]);

        $this->getJson("{$this->baseUrl}/materials/lookup")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('includes the registered units (base first) for the lot-receive picker', function () {
        // MaterialFactory randomises is_active (fake()->boolean()); the lookup
        // only returns active materials, so pin it active or the row is missing
        // ~half the time (flaky "array offset on null").
        $material = Material::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => true,
        ]);
        MaterialUnit::factory()->create([
            'material_id' => $material->id,
            'unit' => 'box',
            'ratio' => 12,
            'is_base' => false,
        ]);
        MaterialUnit::factory()->create([
            'material_id' => $material->id,
            'unit' => 'piece',
            'ratio' => 1,
            'is_base' => true,
        ]);

        $response = $this->getJson("{$this->baseUrl}/materials/lookup")->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $material->id);
        expect($row['units'])->toHaveCount(2)
            ->and($row['units'][0]['unit'])->toBe('piece')   // base first
            ->and($row['units'][0]['is_base'])->toBeTrue()
            ->and($row['units'][1]['unit'])->toBe('box');
    });
});

// =========================================================================
//  Bulk Delete
// =========================================================================

describe('bulk delete', function () {
    it('deletes multiple materials', function () {
        $materials = Material::factory()->count(3)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $this->postJson("{$this->baseUrl}/materials/bulk-delete", [
            'ids' => $materials->pluck('id')->toArray(),
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 3);

        foreach ($materials as $material) {
            $this->assertSoftDeleted('materials', ['id' => $material->id]);
        }
    });
});

// =========================================================================
//  Authentication
// =========================================================================

it('returns 401 when not authenticated', function () {
    Auth::forgetGuards();

    $this->getJson("{$this->baseUrl}/materials")
        ->assertUnauthorized();
});

// =========================================================================
//  Org Isolation
// =========================================================================

it('returns 403 when showing another org material', function () {
    $material = Material::factory()->create([
        'organization_id' => fake()->uuid(),
    ]);

    $this->getJson("{$this->baseUrl}/materials/{$material->id}")
        ->assertForbidden();
});

it('returns 403 when updating another org material', function () {
    $material = Material::factory()->create([
        'organization_id' => fake()->uuid(),
    ]);

    $this->putJson("{$this->baseUrl}/materials/{$material->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('returns 403 when deleting another org material', function () {
    $material = Material::factory()->create([
        'organization_id' => fake()->uuid(),
    ]);

    $this->deleteJson("{$this->baseUrl}/materials/{$material->id}")
        ->assertForbidden();
});

// =========================================================================
//  404 — Non-existent & Soft-deleted
// =========================================================================

it('returns 404 for non-existent material', function () {
    $this->getJson("{$this->baseUrl}/materials/".fake()->uuid())
        ->assertNotFound();
});

it('returns 404 when showing a soft-deleted material', function () {
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $material->delete();

    $this->getJson("{$this->baseUrl}/materials/{$material->id}")
        ->assertNotFound();
});
