<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\StockTransactionItem;
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
        'slug' => 'mun-'.Str::random(4),
    ]);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->actingAs($this->user);
    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/materials/{$this->material->id}/units";
});

it('lists existing units ordered base-first', function () {
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'g',
        'is_base' => false,
        'ratio' => 0.001,
    ]);
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'kg',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $response = $this->getJson($this->baseUrl)->assertOk();
    expect($response->json('data.0.unit'))->toBe('kg')
        ->and($response->json('data.0.is_base'))->toBeTrue();
});

it('creates a unit with valid data', function () {
    $this->postJson($this->baseUrl, [
        'unit' => 'kg',
        'ratio' => 1,
        'is_base' => true,
    ])->assertCreated()->assertJsonPath('data.unit', 'kg');

    expect(MaterialUnit::where('material_id', $this->material->id)->count())->toBe(1);
});

it('rejects ratio <= 0', function () {
    $this->postJson($this->baseUrl, [
        'unit' => 'oz',
        'ratio' => 0,
    ])->assertStatus(422);

    $this->postJson($this->baseUrl, [
        'unit' => 'oz',
        'ratio' => -1,
    ])->assertStatus(422);
});

it('demotes existing base when adding a new base', function () {
    $oldBase = MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'kg',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $this->postJson($this->baseUrl, [
        'unit' => 'g',
        'ratio' => 0.001,
        'is_base' => true,
    ])->assertCreated();

    expect($oldBase->fresh()->is_base)->toBeFalse();
});

it('blocks deleting a base unit', function () {
    $base = MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'kg',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $this->deleteJson("{$this->baseUrl}/{$base->id}")->assertStatus(422);
    expect(MaterialUnit::find($base->id))->not->toBeNull();
});

it('deletes a non-base unit', function () {
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'kg',
        'is_base' => true,
        'ratio' => 1,
    ]);
    $non = MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'g',
        'is_base' => false,
        'ratio' => 0.001,
    ]);

    $this->deleteJson("{$this->baseUrl}/{$non->id}")->assertNoContent();
    expect(MaterialUnit::find($non->id))->toBeNull();
});

it('rejects duplicate unit string per material', function () {
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'kg',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $this->postJson($this->baseUrl, [
        'unit' => 'KG', // case-insensitive duplicate
        'ratio' => 1,
    ])->assertStatus(422);
});

// =========================================================================
//  TC-UNIT-104 — ratio precision (max 4 decimal places)
// =========================================================================

it('TC-UNIT-104: rejects a ratio with more than 4 decimal places', function () {
    $this->postJson($this->baseUrl, [
        'unit' => 'mg',
        'ratio' => 0.00001, // 5 decimals
    ])->assertStatus(422)->assertJsonValidationErrors('ratio');
});

it('TC-UNIT-104: accepts a ratio with exactly 4 decimal places', function () {
    $this->postJson($this->baseUrl, [
        'unit' => 'mg',
        'ratio' => 0.0001,
        'is_base' => true,
    ])->assertCreated();
});

it('TC-UNIT-104: update also rejects >4 decimal places', function () {
    $unit = MaterialUnit::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'kg',
        'is_base' => true,
        'ratio' => 1,
    ]);

    $this->putJson("{$this->baseUrl}/{$unit->id}", [
        'ratio' => 1.23456, // 5 decimals
    ])->assertStatus(422)->assertJsonValidationErrors('ratio');
});

// =========================================================================
//  TC-UNIT-105 — promoting a unit to base auto-recalculates other ratios
// =========================================================================

it('TC-UNIT-105: promoting a unit to base rescales every other ratio', function () {
    // base = g (ratio 1); kg = 1000 g; lb = 453.592 g
    $g = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'g', 'is_base' => true, 'ratio' => 1,
    ]);
    $kg = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'kg', 'is_base' => false, 'ratio' => 1000,
    ]);
    $lb = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'lb', 'is_base' => false, 'ratio' => 453.592,
    ]);

    // Promote kg → base.
    $this->putJson("{$this->baseUrl}/{$kg->id}", ['is_base' => true])->assertOk();

    // kg is now the base with ratio 1; everything else is rescaled by /1000.
    expect((float) $kg->fresh()->ratio)->toBe(1.0)
        ->and($kg->fresh()->is_base)->toBeTrue();
    expect((float) $g->fresh()->ratio)->toBe(0.001)   // 1 / 1000
        ->and($g->fresh()->is_base)->toBeFalse();
    expect((float) $lb->fresh()->ratio)->toBe(0.4536); // 453.592 / 1000, rounded to 4dp
});

it('TC-UNIT-105: only one base remains after promotion', function () {
    $g = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'g', 'is_base' => true, 'ratio' => 1,
    ]);
    $kg = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'kg', 'is_base' => false, 'ratio' => 1000,
    ]);

    $this->putJson("{$this->baseUrl}/{$kg->id}", ['is_base' => true])->assertOk();

    expect(
        MaterialUnit::where('material_id', $this->material->id)->where('is_base', true)->count()
    )->toBe(1);
    expect($g->fresh()->is_base)->toBeFalse();
});

it('TC-UNIT-105: rejects promotion that would round another ratio to zero', function () {
    // base = g; bag = 25000 g. Promoting bag → base would make g = 1/25000 =
    // 0.00004, which rounds to 0 at 4 dp — reject rather than corrupt data.
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'g', 'is_base' => true, 'ratio' => 1,
    ]);
    $bag = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'bag', 'is_base' => false, 'ratio' => 25000,
    ]);

    $this->putJson("{$this->baseUrl}/{$bag->id}", ['is_base' => true])
        ->assertStatus(422)->assertJsonValidationErrors('is_base');

    // Nothing changed — bag is still not the base.
    expect($bag->fresh()->is_base)->toBeFalse();
});

// =========================================================================
//  TC-UNIT-108 — block deleting a unit that is in use
// =========================================================================

it('TC-UNIT-108: blocks deleting a unit referenced by a material lot', function () {
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'kg', 'is_base' => true, 'ratio' => 1,
    ]);
    $box = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'box', 'is_base' => false, 'ratio' => 12,
    ]);

    // A lot was received in "box" — deleting the unit would orphan it.
    MaterialLot::factory()->create([
        'material_id' => $this->material->id,
        'entered_unit' => 'box',
    ]);

    $this->deleteJson("{$this->baseUrl}/{$box->id}")
        ->assertStatus(409)
        ->assertJsonPath('error', 'UNIT_IN_USE');

    expect(MaterialUnit::find($box->id))->not->toBeNull();
});

it('TC-UNIT-108: blocks deleting a unit referenced by a stock-transaction item', function () {
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'kg', 'is_base' => true, 'ratio' => 1,
    ]);
    $box = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'box', 'is_base' => false, 'ratio' => 12,
    ]);

    StockTransactionItem::factory()->create([
        'material_id' => $this->material->id,
        'unit' => 'box',
    ]);

    $this->deleteJson("{$this->baseUrl}/{$box->id}")
        ->assertStatus(409)
        ->assertJsonPath('error', 'UNIT_IN_USE');

    expect(MaterialUnit::find($box->id))->not->toBeNull();
});

it('TC-UNIT-108: a unit with no usage can still be deleted', function () {
    MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'kg', 'is_base' => true, 'ratio' => 1,
    ]);
    $box = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'box', 'is_base' => false, 'ratio' => 12,
    ]);

    // A lot in a DIFFERENT unit must not block deleting "box".
    MaterialLot::factory()->create([
        'material_id' => $this->material->id,
        'entered_unit' => 'kg',
    ]);

    $this->deleteJson("{$this->baseUrl}/{$box->id}")->assertNoContent();
    expect(MaterialUnit::find($box->id))->toBeNull();
});

it('TC-UNIT-105: creating a new base unit rescales existing units', function () {
    $g = MaterialUnit::factory()->create([
        'material_id' => $this->material->id, 'unit' => 'g', 'is_base' => true, 'ratio' => 1,
    ]);

    // Add kg as the new base (1 kg = 1000 g).
    $this->postJson($this->baseUrl, [
        'unit' => 'kg', 'ratio' => 1000, 'is_base' => true,
    ])->assertCreated();

    expect((float) $g->fresh()->ratio)->toBe(0.001)
        ->and($g->fresh()->is_base)->toBeFalse();
    $kg = MaterialUnit::where('material_id', $this->material->id)->where('unit', 'kg')->first();
    expect((float) $kg->ratio)->toBe(1.0)->and($kg->is_base)->toBeTrue();
});
