<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
    ]);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 450,
        'received_qty' => 450,
    ]);

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}";

    $this->actingAs($this->user);
});

it('accepts a single-part split — parent keeps the remainder', function () {
    $this->postJson("{$this->baseUrl}/material-lots/{$this->lot->id}/split", [
        'parts' => [['qty' => 300]],
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data.children')
        ->assertJsonPath('data.parent.status', MaterialLotStatusEnum::Active->value);

    expect((float) $this->lot->fresh()->qty_on_hand)->toBe(150.0);
});

it('rejects an empty parts list', function () {
    $this->postJson("{$this->baseUrl}/material-lots/{$this->lot->id}/split", [
        'parts' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('parts');
});
