<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialLotService;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Support\Str;

/**
 * plan-040 H4 — FEFO must never allocate a null-expiry lot before an expiring one.
 * A plain `ORDER BY expiry_date` sorts MySQL NULLs FIRST, which would drain the
 * no-expiry lot ahead of the soon-expiring one. The fix orders null-expiry last.
 */
function fefoSetup(): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create(['console_organization_id' => $orgId, 'console_brand_id' => $brand->id]);
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
    ]);
    $material = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);

    // Null-expiry lot received EARLIER than the expiring lot, so received_at can't
    // be what saves us — only the null-last expiry ordering can.
    $nullLot = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'active',
        'unit' => 'kg',
        'received_qty' => 100,
        'qty_on_hand' => 100,
        'received_at' => now()->subDays(10),
        'expiry_date' => null,
    ]);
    $expiringLot = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $warehouse->id,
        'status' => 'active',
        'unit' => 'kg',
        'received_qty' => 100,
        'qty_on_hand' => 100,
        'received_at' => now()->subDays(1),
        'expiry_date' => now()->addDay()->toDateString(),
    ]);

    return [$orgId, $material->id, $warehouse->id, $nullLot, $expiringLot];
}

it('FEFO preview draws the expiring lot before the null-expiry lot', function () {
    [, $materialId, $warehouseId, $nullLot, $expiringLot] = fefoSetup();

    $allocations = app(StockTransactionService::class)
        ->previewLotsForConsumption($materialId, $warehouseId, 10, 'kg');

    expect($allocations)->toHaveCount(1)
        ->and($allocations[0]['material_lot_id'])->toEqual((string) $expiringLot->id)
        ->and($allocations[0]['material_lot_id'])->not->toEqual((string) $nullLot->id);
});

it('lot lookup orders expiring-first and null-expiry last', function () {
    [$orgId, $materialId, $warehouseId, $nullLot, $expiringLot] = fefoSetup();

    $pool = app(MaterialLotService::class)->lookup($orgId, $materialId, $warehouseId);

    expect($pool->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->toEqual([(string) $expiringLot->id, (string) $nullLot->id]);
});
