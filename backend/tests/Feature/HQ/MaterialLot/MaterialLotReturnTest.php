<?php

/**
 * Plan-018 Group E.1 — Returned lot tests.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\StockTransaction;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Services\Inventory\MaterialLotService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'ret-'.Str::random(4),
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

    $this->service = app(MaterialLotService::class);
});

it('returns qty back to lot and keeps status active', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 10,
        'status' => MaterialLotStatusEnum::Active->value,
    ]);

    $result = $this->service->returnQty($lot, 5, 'Production leftover', (string) Str::uuid());

    expect((float) $result->qty_on_hand)->toBe(15.0);
    expect($result->status->value)->toBe(MaterialLotStatusEnum::Active->value);
});

it('returns qty to depleted lot and reverts to active', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 0,
        'status' => MaterialLotStatusEnum::Depleted->value,
    ]);

    $result = $this->service->returnQty($lot, 3, 'Customer return', (string) Str::uuid());

    expect((float) $result->qty_on_hand)->toBe(3.0);
    expect($result->status->value)->toBe(MaterialLotStatusEnum::Active->value);
});

it('rejects return of zero or negative qty', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 10,
    ]);

    expect(fn () => $this->service->returnQty($lot, 0, 'Bad return'))
        ->toThrow(ValidationException::class);
});

it('rejects a return that exceeds what was consumed from the lot (Finding 3 cap)', function () {
    // received 100, currently 90 on hand → only 10 were consumed, so a return
    // of 11 must be rejected (would push qty_on_hand above received_qty). This
    // enforces the DESIGN E1 "cannot return more than consumed" guard, which
    // the received_qty cap expresses (received_qty - qty_on_hand == net
    // consumed-and-not-yet-returned).
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 90,
        'received_qty' => 100,
        'status' => MaterialLotStatusEnum::Active->value,
    ]);

    expect(fn () => $this->service->returnQty($lot, 11, 'Over-return', (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    // Boundary — returning exactly the consumed amount (10) is allowed and
    // writes no more than the received_qty back.
    $result = $this->service->returnQty($lot, 10, 'Return all consumed', (string) Str::uuid());
    expect((float) $result->qty_on_hand)->toBe(100.0);

    // And no StockTransaction ledger row leaked from the rejected 11-return —
    // only the accepted 10-return produced one.
    expect(StockTransaction::where('reference_type', 'material_lot')
        ->where('reference_id', $lot->id)
        ->where('sub_type', 'return')
        ->count())->toBe(1);
});

it('writes a stock_in / return StockTransaction ledger entry (Finding 3)', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 10,
        'received_qty' => 100,
        'status' => MaterialLotStatusEnum::Active->value,
    ]);

    $this->service->returnQty($lot, 5, 'Wholesale return', (string) Str::uuid());

    $tx = StockTransaction::where('reference_type', 'material_lot')
        ->where('reference_id', $lot->id)
        ->where('sub_type', 'return')
        ->latest('created_at')
        ->first();

    expect($tx)->not->toBeNull();
    expect($tx->type->value ?? (string) $tx->type)->toBe('stock_in');

    $item = $tx->items()->first();
    expect($item)->not->toBeNull();
    expect((float) $item->quantity)->toBe(5.0);
    expect($item->material_lot_id)->toBe($lot->id);
});

it('re-syncs the lot StockLevel when the warehouse auto-approves stock_in', function () {
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'auto_approve_stock_in' => true,
    ]);

    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $warehouse->id,
        'qty_on_hand' => 10,
        'received_qty' => 100,
        'status' => MaterialLotStatusEnum::Active->value,
    ]);

    StockLevel::create([
        'warehouse_id' => $warehouse->id,
        'material_id' => $this->material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 10,
        'unit' => $lot->unit,
    ]);

    $this->service->returnQty($lot, 5, 'Wholesale return', (string) Str::uuid());

    $level = StockLevel::where('warehouse_id', $warehouse->id)
        ->where('material_lot_id', $lot->id)
        ->first();

    // StockLevel now matches the lot's bumped qty_on_hand (10 + 5), not double
    // counted, and no longer drifts below it.
    expect((float) $level->quantity)->toBe(15.0);
    expect((float) $lot->fresh()->qty_on_hand)->toBe(15.0);
});
