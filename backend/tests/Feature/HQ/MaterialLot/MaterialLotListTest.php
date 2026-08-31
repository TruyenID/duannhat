<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'hql-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/material-lots";

    MaterialLot::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);
});

it('lists material lots scoped to the brand', function () {
    $this->actingAs($this->user)
        ->getJson($this->baseUrl)
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'lot_code', 'status', 'qty_on_hand']]]);
});

it('does not leak another brand of the same org into the HQ list (route brand scope)', function () {
    // plan-040 fix: the HQ material-lots list must be scoped to the ROUTE brand,
    // not the whole org. A sibling brand in the same org with its own lots must
    // NOT appear under /hq/{thisBrand}/material-lots — otherwise its lots leak
    // into the recall lot-picker and the recall's brand-scoped root resolution 404s.
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'hql-other-'.Str::random(4),
        'is_active' => true,
    ]);
    $otherMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $otherBrand->id,
    ]);
    MaterialLot::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $otherBrand->id,
        'material_id' => $otherMaterial->id,
        'warehouse_id' => $this->warehouse->id,
    ]);

    // This brand's list still returns only its own 3 lots (not 5).
    $this->actingAs($this->user)
        ->getJson($this->baseUrl)
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('filters by status', function () {
    MaterialLot::factory()->quarantined()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->baseUrl}?status=quarantined")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters by expiring_within_days', function () {
    MaterialLot::factory()->expiringSoon(2)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->baseUrl}?expiring_within_days=7")
        ->assertOk();

    expect($response->json('data'))->not->toBeEmpty();
});

it('returns 401 when unauthenticated', function () {
    Auth::forgetGuards();
    $this->getJson($this->baseUrl)->assertUnauthorized();
});

it('returns 404 for an unknown brand slug', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/hq/does-not-exist/material-lots')
        ->assertNotFound();
});
