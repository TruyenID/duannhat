<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Recipe;
use App\Services\Product\MaterialService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->materials = app(MaterialService::class);
});

it('accepts a raw material with no yield_unit', function () {
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Raw flour',
        'sku' => 'MA-RAW-1',
        'yield_quantity' => 1,
    ]);

    expect($m->yield_unit)->toBeNull();
});

it('Plan-022 T19: setting yield_unit declares the material as produced (accepts upfront)', function () {
    // Pre-T19, sending yield_unit on a material without a recipe was a 422
    // ("yield_unit must be null for a raw material"). T19 inverts the
    // semantics — yield_unit IS the produced declaration. HQ form sends it
    // upfront when user picks Kind = "Bán thành phẩm".
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Sauce base (declared produced)',
        'sku' => 'MA-PROD-DECL-1',
        'yield_quantity' => 500,
        'yield_unit' => 'ml',
    ]);

    expect($m->yield_unit)->toBe('ml');
    expect((float) $m->yield_quantity)->toBe(500.0);
});

it('seeds yield_unit as the base MaterialUnit when a produced material is created', function () {
    // The create form requires yield_unit but units live on a separate tab.
    // Creating a produced material must auto-register yield_unit as the base
    // unit so the two never drift and the Units tab starts from a valid base.
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Sauce base auto-unit',
        'sku' => 'MA-AUTOUNIT-1',
        'yield_quantity' => 500,
        'yield_unit' => 'ml',
    ]);

    $units = MaterialUnit::where('material_id', $m->id)->get();
    expect($units)->toHaveCount(1);
    expect($units[0]->unit)->toBe('ml')
        ->and((float) $units[0]->ratio)->toBe(1.0)
        ->and((bool) $units[0]->is_base)->toBeTrue();
});

it('does not seed a unit for a raw material', function () {
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Raw with no yield',
        'sku' => 'MA-RAW-NOUNIT-1',
        'yield_quantity' => 1,
    ]);

    expect(MaterialUnit::where('material_id', $m->id)->count())->toBe(0);
});

it('seeds the base unit when a material is promoted to produced via update', function () {
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Becomes produced later',
        'sku' => 'MA-PROMOTE-1',
        'yield_quantity' => 1,
    ]);
    expect(MaterialUnit::where('material_id', $m->id)->count())->toBe(0);

    $this->materials->update($m, [
        'yield_quantity' => 200,
        'yield_unit' => 'g',
    ]);

    $units = MaterialUnit::where('material_id', $m->id)->get();
    expect($units)->toHaveCount(1);
    expect($units[0]->unit)->toBe('g')
        ->and((bool) $units[0]->is_base)->toBeTrue();
});

it('does not duplicate the base unit when units already exist', function () {
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Already has units',
        'sku' => 'MA-HASUNITS-1',
        'yield_quantity' => 1000,
        'yield_unit' => 'g',
    ]);
    // create() seeded the base unit (g). Add a conversion unit, then re-save.
    MaterialUnit::create(['material_id' => $m->id, 'unit' => 'kg', 'ratio' => 1000, 'is_base' => false]);

    $this->materials->update($m, ['yield_quantity' => 2000]);

    // No extra base row injected — still exactly the seeded base + the kg conversion.
    expect(MaterialUnit::where('material_id', $m->id)->count())->toBe(2);
});

it('rejects produced material missing yield_unit', function () {
    expect(fn () => $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Sauce',
        'sku' => 'MA-PROD-1',
        'yield_quantity' => 1,
        // Plan-022 T4.5 — Material.components retired; signal "produced"
        // via the transitional `ingredients` payload key (the same hint
        // isProducedMaterial() reads when a recipe doesn't yet exist).
        'ingredients' => [['type' => 'material', 'quantity' => 5]],
    ]))->toThrow(ValidationException::class);
});

it('rejects produced material with yield_unit not in MaterialUnit', function () {
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Sauce 2',
        'sku' => 'MA-PROD-2',
        'yield_quantity' => 1,
        // No components on create — material lives as raw at create-time.
    ]);

    // Add an active recipe + a registered MaterialUnit; then update with mismatched yield_unit.
    Recipe::create([
        'sku' => 'R-PROD-2',
        'name' => 'Sauce recipe',
        'material_id' => $m->id,
        'output_quantity' => 100,
        'output_unit' => 'ml',
        'ingredients' => [['type' => 'material', 'quantity' => 5]],
        'is_active' => true,
        'approval_status' => 'approved',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $m->id,
        'unit' => 'ml',
        'is_base' => true,
        'ratio' => 1,
    ]);

    expect(fn () => $this->materials->update($m->fresh(), ['yield_unit' => 'bottle']))
        ->toThrow(ValidationException::class);
});

it('accepts produced material with valid yield_unit', function () {
    $m = $this->materials->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Sauce 3',
        'sku' => 'MA-PROD-3',
        'yield_quantity' => 100,
    ]);

    Recipe::create([
        'sku' => 'R-PROD-3',
        'name' => 'Sauce recipe',
        'material_id' => $m->id,
        'output_quantity' => 100,
        'output_unit' => 'ml',
        'ingredients' => [['type' => 'material', 'quantity' => 5]],
        'is_active' => true,
        'approval_status' => 'approved',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $m->id,
        'unit' => 'ml',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $m = $this->materials->update($m->fresh(), ['yield_unit' => 'ml', 'yield_quantity' => 100]);

    expect($m->yield_unit)->toEqual('ml');
});

// =========================================================================
//  Plan-022 T19 — explicit "produced" declaration via yield_unit on payload
// =========================================================================

describe('T19 explicit kind declaration', function () {
    it('accepts a produced material declared upfront (yield_unit set, no recipe yet)', function () {
        // The HQ Material new form (kind = "Bán thành phẩm") sends yield_unit
        // on create, before any recipe exists. T19 inverts the pre-T19 rule
        // that rejected this — yield_unit IS the declaration of produced.
        $m = $this->materials->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Sauce base (T19 declared)',
            'sku' => 'MA-T19-PROD-1',
            'yield_quantity' => 500,
            'yield_unit' => 'ml',
        ]);

        expect($m->yield_unit)->toBe('ml');
        expect((float) $m->yield_quantity)->toBe(500.0);
    });

    it('rejects produced declaration with yield_quantity = 0', function () {
        expect(fn () => $this->materials->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Bad qty',
            'sku' => 'MA-T19-BADQ',
            'yield_quantity' => 0,
            'yield_unit' => 'g',
        ]))->toThrow(ValidationException::class);
    });

    it('treats empty-string yield_unit as raw (not produced)', function () {
        // The HQ form might send "" instead of null for an unset text field.
        // T19 normalises that to null + skips produced checks.
        $m = $this->materials->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Empty yield_unit raw',
            'sku' => 'MA-T19-EMPTY',
            'yield_quantity' => 1,
            'yield_unit' => '',
        ]);

        expect($m->yield_unit)->toBeNull();
    });

    it('preserves yield_unit through update (idempotent on produced)', function () {
        $m = $this->materials->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'name' => 'Idempotent test',
            'sku' => 'MA-T19-IDEMP',
            'yield_quantity' => 100,
            'yield_unit' => 'g',
        ]);

        $m = $this->materials->update($m, ['calculated_cost' => 42]);

        expect($m->yield_unit)->toBe('g');
        expect((float) $m->calculated_cost)->toBe(42.0);
    });
});
