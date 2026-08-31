<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        'auto_approve_stock_in' => true,
        'auto_approve_stock_out' => true,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->service = app(StockTransactionService::class);
});

function lotForTest(MaterialLot $defaults): MaterialLot
{
    return $defaults;
}

function makeLotForFefo(string $orgId, string $brandId, string $materialId, string $warehouseId, array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'material_id' => $materialId,
        'warehouse_id' => $warehouseId,
        'source' => 'inbound',
        'status' => 'active',
    ], $overrides));
}

function seedStockLevel(string $warehouseId, string $materialId, ?string $lotId, float $qty): void
{
    StockLevel::create([
        'warehouse_id' => $warehouseId,
        'material_id' => $materialId,
        'material_lot_id' => $lotId,
        'quantity' => $qty,
        'unit' => 'kg',
        'alert_enabled' => false,
    ]);
}

function consumeStockOut(StockTransactionService $service, string $orgId, string $warehouseId, string $materialId, float $qty): StockTransaction
{
    $txn = $service->create([
        'organization_id' => $orgId,
        'warehouse_id' => $warehouseId,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $materialId,
            'quantity' => $qty,
            'unit' => 'kg',
        ]],
    ]);

    return $service->submit($txn);
}

it('picks oldest-expiry lot first', function () {
    $oldLot = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(10)->toDateString(),
        'received_at' => now()->subDays(5),
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
    ]);
    $newLot = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(60)->toDateString(),
        'received_at' => now()->subDay(),
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
    ]);
    seedStockLevel($this->warehouse->id, $this->material->id, $oldLot->id, 1000);
    seedStockLevel($this->warehouse->id, $this->material->id, $newLot->id, 1000);

    consumeStockOut($this->service, $this->orgId, $this->warehouse->id, $this->material->id, 500);

    expect((float) $oldLot->fresh()->qty_on_hand)->toBe(500.0)
        ->and((float) $newLot->fresh()->qty_on_hand)->toBe(1000.0);
});

it('splits a multi-lot allocation into N stock_transaction_items', function () {
    $lotA = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(10)->toDateString(),
        'received_qty' => 300,
        'qty_on_hand' => 300,
    ]);
    $lotB = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(30)->toDateString(),
        'received_qty' => 500,
        'qty_on_hand' => 500,
    ]);
    seedStockLevel($this->warehouse->id, $this->material->id, $lotA->id, 300);
    seedStockLevel($this->warehouse->id, $this->material->id, $lotB->id, 500);

    $txn = consumeStockOut($this->service, $this->orgId, $this->warehouse->id, $this->material->id, 700);

    $items = StockTransactionItem::where('stock_transaction_id', $txn->id)->get();
    expect($items)->toHaveCount(2);

    $taken = $items->keyBy('material_lot_id')->map(fn ($i) => (float) $i->base_quantity);
    expect((float) $taken[$lotA->id])->toBe(300.0)
        ->and((float) $taken[$lotB->id])->toBe(400.0);
    expect((float) $lotA->fresh()->qty_on_hand)->toBe(0.0)
        ->and($lotA->fresh()->status->value)->toBe(MaterialLotStatusEnum::Depleted->value)
        ->and((float) $lotB->fresh()->qty_on_hand)->toBe(100.0);
});

it('rejects pre-lot stock when active lots do not cover demand', function () {
    $lotA = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ]);
    seedStockLevel($this->warehouse->id, $this->material->id, $lotA->id, 100);
    seedStockLevel($this->warehouse->id, $this->material->id, null, 500);

    expect(fn () => consumeStockOut(
        $this->service,
        $this->orgId,
        $this->warehouse->id,
        $this->material->id,
        400,
    ))->toThrow(InsufficientStockException::class);

    $preLotLevel = StockLevel::where('warehouse_id', $this->warehouse->id)
        ->where('material_id', $this->material->id)
        ->whereNull('material_lot_id')
        ->first();

    expect((float) $lotA->fresh()->qty_on_hand)->toBe(100.0)
        ->and((float) $preLotLevel->fresh()->quantity)->toBe(500.0);
});

it('throws InsufficientStockException when total available < requested', function () {
    $lot = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ]);
    seedStockLevel($this->warehouse->id, $this->material->id, $lot->id, 100);

    expect(fn () => consumeStockOut($this->service, $this->orgId, $this->warehouse->id, $this->material->id, 500))
        ->toThrow(InsufficientStockException::class);

    // The whole transaction should have rolled back
    expect((float) $lot->fresh()->qty_on_hand)->toBe(100.0);
});

it('skips quarantined lots even if they have stock', function () {
    $quarantined = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(10)->toDateString(),
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
        'status' => MaterialLotStatusEnum::Quarantined->value,
        'quarantine_reason' => 'lab hold',
    ]);
    $active = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(60)->toDateString(),
        'received_qty' => 200,
        'qty_on_hand' => 200,
    ]);
    seedStockLevel($this->warehouse->id, $this->material->id, $quarantined->id, 1000);
    seedStockLevel($this->warehouse->id, $this->material->id, $active->id, 200);

    consumeStockOut($this->service, $this->orgId, $this->warehouse->id, $this->material->id, 200);

    expect((float) $quarantined->fresh()->qty_on_hand)->toBe(1000.0) // untouched
        ->and((float) $active->fresh()->qty_on_hand)->toBe(0.0);
});

it('honors manual material_lot_id override', function () {
    $oldLot = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(10)->toDateString(),
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
    ]);
    $newLot = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(60)->toDateString(),
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
    ]);
    seedStockLevel($this->warehouse->id, $this->material->id, $oldLot->id, 1000);
    seedStockLevel($this->warehouse->id, $this->material->id, $newLot->id, 1000);

    // Manual override picks newLot even though FEFO would prefer oldLot.
    $txn = $this->service->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $this->material->id,
            'material_lot_id' => $newLot->id,
            'quantity' => 100,
            'unit' => 'kg',
        ]],
    ]);
    $this->service->submit($txn);

    expect((float) $newLot->fresh()->qty_on_hand)->toBe(900.0)
        ->and((float) $oldLot->fresh()->qty_on_hand)->toBe(1000.0);
});

it('rejects manual override on a quarantined lot', function () {
    $quarantined = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'received_qty' => 1000,
        'qty_on_hand' => 1000,
        'status' => MaterialLotStatusEnum::Quarantined->value,
        'quarantine_reason' => 'on hold',
    ]);

    $txn = $this->service->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $this->material->id,
            'material_lot_id' => $quarantined->id,
            'quantity' => 100,
            'unit' => 'kg',
        ]],
    ]);

    expect(fn () => $this->service->submit($txn))
        ->toThrow(ValidationException::class);
});

it('FEFO pick skips reserved qty — picks from next lot when reserved', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);

    $lotA = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(10)->toDateString(),
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ]);
    $lotB = makeLotForFefo($this->orgId, $this->brand->id, $this->material->id, $this->warehouse->id, [
        'expiry_date' => now()->addDays(30)->toDateString(),
        'received_qty' => 100,
        'qty_on_hand' => 100,
    ]);
    seedStockLevel($this->warehouse->id, $this->material->id, $lotA->id, 100);
    seedStockLevel($this->warehouse->id, $this->material->id, $lotB->id, 100);

    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lotA->id,
        'qty_reserved' => 50,
        'reserved_by_id' => $user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
    ]);

    $txn = consumeStockOut($this->service, $this->orgId, $this->warehouse->id, $this->material->id, 60);

    $items = StockTransactionItem::where('stock_transaction_id', $txn->id)->get();
    $byLot = $items->keyBy('material_lot_id')->map(fn ($i) => (float) $i->base_quantity);

    expect((float) $byLot[$lotA->id])->toBe(50.0)
        ->and((float) $byLot[$lotB->id])->toBe(10.0);
});
