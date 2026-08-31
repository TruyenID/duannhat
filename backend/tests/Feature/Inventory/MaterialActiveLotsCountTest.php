<?php

/**
 * Plan-017 T9.6 MOD-1 — verify Material::activeLots / expiringSoonLots
 * relations + the withCount aggregation MaterialService::list now adds.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Product\MaterialService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
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
    $this->service = app(MaterialService::class);
});

function makeLotForCount(string $orgId, string $brandId, string $materialId, string $warehouseId, array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => $materialId,
        'warehouse_id' => $warehouseId,
        'status' => MaterialLotStatusEnum::Active->value,
        'qty_on_hand' => 100,
    ], $overrides));
}

it('counts only active lots with qty_on_hand > 0 (excludes depleted/quarantined/zero-qty)', function () {
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id);
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id);
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'status' => MaterialLotStatusEnum::Quarantined->value,
        'quarantine_reason' => 'qa hold',
    ]);
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'status' => MaterialLotStatusEnum::Depleted->value,
        'qty_on_hand' => 0,
    ]);
    // Active but zero qty — should still be excluded by the qty_on_hand > 0 filter.
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'qty_on_hand' => 0,
    ]);

    expect($this->material->activeLots()->count())->toBe(2);
});

it('counts expiring-soon lots in the [today, today+7d] window only', function () {
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(3)->toDateString(),
    ]);
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(6)->toDateString(),
    ]);
    // Outside the 7-day window — excluded.
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(20)->toDateString(),
    ]);
    // No expiry — excluded (whereNotNull guard).
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => null,
    ]);

    expect($this->material->expiringSoonLots()->count())->toBe(2);
});

it('MaterialService::list withCount surfaces both counts on the row', function () {
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id);
    makeLotForCount($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(2)->toDateString(),
    ]);

    $page = $this->service->list(['organization_id' => $this->orgId]);
    $row = $page->items()[0];

    expect((int) $row->active_lots_count)->toBe(2)
        ->and((int) $row->expiring_soon_lots_count)->toBe(1);
});
