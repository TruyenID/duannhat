<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'hqw-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
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
    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/material-lots";
});

function makeLot(string $orgId, string $brandId, string $materialId, string $warehouseId, array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => $materialId,
        'warehouse_id' => $warehouseId,
    ], $overrides));
}

// =========================================================================
//  Quarantine
// =========================================================================

it('quarantines an active lot with a reason', function () {
    $lot = makeLot($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id);

    $this->postJson("{$this->baseUrl}/{$lot->id}/quarantine", [
        'reason' => 'QC hold pending lab result',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'quarantined');

    expect($lot->fresh()->status->value)->toBe(MaterialLotStatusEnum::Quarantined->value)
        ->and($lot->fresh()->quarantine_reason)->toBe('QC hold pending lab result');
});

it('rejects quarantine with empty reason', function () {
    $lot = makeLot($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id);

    $this->postJson("{$this->baseUrl}/{$lot->id}/quarantine", ['reason' => ''])
        ->assertStatus(422);
});

it('rejects quarantining a non-active lot', function () {
    $lot = makeLot($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'status' => MaterialLotStatusEnum::Disposed->value,
    ]);

    $this->postJson("{$this->baseUrl}/{$lot->id}/quarantine", [
        'reason' => 'attempt on disposed lot',
    ])
        ->assertStatus(422);
});

// =========================================================================
//  Release
// =========================================================================

it('releases a quarantined lot back to active and clears reason', function () {
    $lot = makeLot($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'status' => MaterialLotStatusEnum::Quarantined->value,
        'quarantine_reason' => 'pending lab',
    ]);

    $this->postJson("{$this->baseUrl}/{$lot->id}/release")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $fresh = $lot->fresh();
    expect($fresh->status->value)->toBe(MaterialLotStatusEnum::Active->value)
        ->and($fresh->quarantine_reason)->toBeNull();
});

it('rejects releasing an already-active lot', function () {
    $lot = makeLot($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id);

    $this->postJson("{$this->baseUrl}/{$lot->id}/release")
        ->assertStatus(422);
});

// =========================================================================
//  Dispose
// =========================================================================

it('disposes a depleted lot without force', function () {
    $lot = makeLot($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'status' => MaterialLotStatusEnum::Depleted->value,
        'qty_on_hand' => 0,
    ]);

    $this->postJson("{$this->baseUrl}/{$lot->id}/dispose")
        ->assertOk()
        ->assertJsonPath('data.status', 'disposed');

    expect($lot->fresh()->status->value)->toBe(MaterialLotStatusEnum::Disposed->value);
});

it('blocks disposing a lot with stock unless force=true', function () {
    $lot = makeLot($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'status' => MaterialLotStatusEnum::Active->value,
        'qty_on_hand' => 100,
    ]);

    $this->postJson("{$this->baseUrl}/{$lot->id}/dispose")
        ->assertStatus(422); // InvalidArgumentException without force

    $this->postJson("{$this->baseUrl}/{$lot->id}/dispose", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'disposed')
        ->assertJsonPath('data.qty_on_hand', '0.0000');
});

// =========================================================================
//  Authorization
// =========================================================================

it('blocks cross-org lot access', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->id,
    ]);
    $otherWarehouse = Warehouse::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => $otherBranch->id,
    ]);
    $otherMaterial = Material::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
    ]);
    $stranger = makeLot($otherOrgId, $otherBrand->id, $otherMaterial->id, $otherWarehouse->id);

    $this->postJson("{$this->baseUrl}/{$stranger->id}/quarantine", ['reason' => 'cross-org'])
        ->assertForbidden();
});
