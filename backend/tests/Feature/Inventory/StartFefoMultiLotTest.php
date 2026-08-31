<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatchItem;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialBatchService;
use Illuminate\Support\Str;

it('start() FEFO stamps a lot even when no single lot covers the item', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create(['console_organization_id' => $orgId, 'console_brand_id' => $brand->id]);
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'auto_approve_batch' => true,
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);
    $produced = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $ingredient = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    Recipe::create([
        'sku' => 'R-'.Str::upper(Str::random(8)),
        'name' => 'Sauce',
        'material_id' => $produced->id,
        'output_quantity' => 1,
        'output_unit' => 'g',
        'ingredients' => [
            ['type' => 'material', 'material_id' => $ingredient->id, 'quantity' => 12, 'unit' => 'kg'],
        ],
        'is_active' => true,
        'approval_status' => 'approved',
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
    ]);

    // Two lots: 5kg + 8kg. Item needs 12kg — neither single lot covers.
    $small = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'kg',
        'received_qty' => 5,
        'qty_on_hand' => 5,
        'expiry_date' => now()->addDays(2)->toDateString(),
    ]);
    MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'kg',
        'received_qty' => 8,
        'qty_on_hand' => 8,
        'expiry_date' => now()->addDays(10)->toDateString(),
    ]);

    $batches = app(MaterialBatchService::class);
    $batch = $batches->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouse->id,
        'material_id' => $produced->id,
        'multiplier' => 1,
        'planned_yield' => 1,
        'yield_unit' => 'g',
        'created_by_id' => (string) Str::uuid(),
    ]);

    $batches->submit($batch);
    $batches->start($batch->fresh());

    // Old single-lot behaviour would have left material_lot_id=NULL because
    // no single lot covers 12kg. Multi-lot preview stamps the FEFO-first.
    $item = MaterialBatchItem::where('material_batch_id', $batch->id)
        ->whereNotNull('material_id')
        ->first();

    expect((string) $item->material_lot_id)->toEqual($small->id);
});
