<?php

/**
 * Plan-017 T9.6 MOD-4 — verify StockLevelService::list eager-loads
 * `materialLot:id,lot_code,expiry_date,status` so the FE can render
 * Lot + Expiry columns without per-row queries.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Services\Inventory\StockLevelService;
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
    $this->service = app(StockLevelService::class);
});

it('eager-loads materialLot with lot_code + expiry_date when row has material_lot_id', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'lot_code' => 'L-TEST-001',
        'expiry_date' => '2026-12-31',
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 50,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $page = $this->service->list(['organization_id' => $this->orgId]);
    $row = $page->items()[0];

    expect($row->relationLoaded('materialLot'))->toBeTrue()
        ->and($row->materialLot)->not->toBeNull()
        ->and($row->materialLot->lot_code)->toBe('L-TEST-001')
        ->and((string) $row->materialLot->expiry_date->toDateString())->toBe('2026-12-31');
});

it('handles legacy NULL-lot rows without crashing the eager load', function () {
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'material_lot_id' => null,
        'quantity' => 100,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);

    $page = $this->service->list(['organization_id' => $this->orgId]);
    $row = $page->items()[0];

    expect($row->relationLoaded('materialLot'))->toBeTrue()
        ->and($row->materialLot)->toBeNull()
        ->and($row->material_lot_id)->toBeNull();
});
