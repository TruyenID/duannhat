<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/**
 * Plan-022 T1.7 — pre-deploy guard `material-lots:assert-base-unit`.
 *
 * TESTS.md planned a `MaterialUnitConversionGuardMigrationTest.php`; the guard
 * shipped as an artisan command (NOTES 2026-05-14) rather than a migration.
 * These cases assert the command's exit-code contract: SUCCESS (0) when every
 * lot is in its material's base unit, FAILURE (1) when any lot is not — the
 * signal a deploy pipeline uses to abort before promoting the T1 build.
 */
function guardMaterialWithBaseUnit(string $orgId, string $brandId, string $baseUnit = 'g'): Material
{
    $material = Material::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $material->id,
        'unit' => $baseUnit,
        'ratio' => 1,
        'is_base' => true,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $material->id,
        'unit' => 'kg',
        'ratio' => 1000,
        'is_base' => false,
    ]);

    return $material;
}

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->warehouse = Warehouse::factory()->create(['organization_id' => $this->orgId]);
});

it('passes on an empty database (no lots to check)', function () {
    $this->artisan('material-lots:assert-base-unit')->assertExitCode(0);
});

it('passes when every lot is stored in its material base unit', function () {
    $material = guardMaterialWithBaseUnit($this->orgId, $this->brand->id, 'g');

    MaterialLot::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'g',
    ]);

    $this->artisan('material-lots:assert-base-unit')->assertExitCode(0);
});

it('fails when a lot is stored in a non-base unit', function () {
    $material = guardMaterialWithBaseUnit($this->orgId, $this->brand->id, 'g');

    MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'kg', // non-base — corrupts stock_levels = SUM(qty_on_hand)
    ]);

    $this->artisan('material-lots:assert-base-unit')->assertExitCode(1);
});

it('ignores soft-deleted non-base lots (only live rows gate the deploy)', function () {
    $material = guardMaterialWithBaseUnit($this->orgId, $this->brand->id, 'g');

    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'kg',
    ]);
    $lot->delete();

    $this->artisan('material-lots:assert-base-unit')->assertExitCode(0);
});

it('passes when a material has no registered units (legacy, no base to compare)', function () {
    // No MaterialUnit rows → the guard join finds no base row → no violation.
    $material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $this->warehouse->id,
        'unit' => 'kg',
    ]);

    $this->artisan('material-lots:assert-base-unit')->assertExitCode(0);
});
