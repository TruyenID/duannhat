<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\GenealogyLink;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialLotReservationService;
use App\Services\Inventory\MaterialLotService;
use App\Services\Inventory\RecallService;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * plan-040 Phase C — Reservation lifecycle (TC.1–TC.6 / Cluster C scenarios).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
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
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->actingAs($this->user);

    $this->reservationService = app(MaterialLotReservationService::class);
    $this->lotService = app(MaterialLotService::class);
    $this->stockService = app(StockTransactionService::class);
    $this->recallService = app(RecallService::class);
});

function makeReservationLot(array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'material_id' => test()->material->id,
        'warehouse_id' => test()->warehouse->id,
        'status' => 'active',
        'unit' => 'kg',
        'received_qty' => 100,
        'qty_on_hand' => 100,
        'expiry_date' => now()->addDays(30)->toDateString(),
    ], $overrides));
}

function makeReservationRow(MaterialLot $lot, float $qty, array $overrides = []): MaterialLotReservation
{
    return MaterialLotReservation::factory()->create(array_merge([
        'material_lot_id' => $lot->id,
        'qty_reserved' => $qty,
        'reserved_by_id' => test()->user->id,
        'organization_id' => test()->orgId,
        'status' => 'active',
        'expected_consume_at' => now()->addDays(3),
    ], $overrides));
}

function seedReservationStockLevel(string $warehouseId, string $materialId, ?string $lotId, float $qty): void
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

// =========================================================================
//  TC.1 (H3a) — consume() wired into the real consumption path
// =========================================================================

it('H3a: a fully consumed reserved lot moves its reservation to consumed', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    seedReservationStockLevel($this->warehouse->id, $this->material->id, $lot->id, 100);
    $reservation = makeReservationRow($lot, 100);

    // Manual-lot override stock_out of the whole lot (FEFO availability would be
    // zero because the lot is fully reserved — the explicit lot draws it anyway).
    $txn = $this->stockService->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $this->material->id,
            'material_lot_id' => $lot->id,
            'quantity' => 100,
            'unit' => 'kg',
        ]],
    ]);
    $this->stockService->submit($txn);

    expect($reservation->fresh()->status)->toBe('consumed')
        ->and((float) $lot->fresh()->qty_on_hand)->toBe(0.0);
});

it('H3a: a partial draw decrements the reservation and keeps it active', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    seedReservationStockLevel($this->warehouse->id, $this->material->id, $lot->id, 100);
    $reservation = makeReservationRow($lot, 100);

    $txn = $this->stockService->create([
        'organization_id' => $this->orgId,
        'warehouse_id' => $this->warehouse->id,
        'type' => 'stock_out',
        'sub_type' => 'sales',
        'created_by_id' => (string) Str::uuid(),
        'items' => [[
            'material_id' => $this->material->id,
            'material_lot_id' => $lot->id,
            'quantity' => 40,
            'unit' => 'kg',
        ]],
    ]);
    $this->stockService->submit($txn);

    $fresh = $reservation->fresh();
    expect($fresh->status)->toBe('active')
        ->and((float) $fresh->qty_reserved)->toBe(60.0);
});

// =========================================================================
//  TC.2 (H3b/M16) — expire null-expected_consume_at via created_at + TTL
// =========================================================================

it('H3b/M16: a null-date reservation older than the TTL expires', function () {
    $lot = makeReservationLot();
    $stale = makeReservationRow($lot, 10, ['expected_consume_at' => null]);
    // Backdate created_at past the 7-day TTL.
    $stale->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    $expired = $this->reservationService->expireStale();

    expect($expired)->toBe(1)
        ->and($stale->fresh()->status)->toBe('expired');
});

it('H3b/M16: a fresh null-date reservation within the TTL is NOT expired', function () {
    $lot = makeReservationLot();
    $fresh = makeReservationRow($lot, 10, ['expected_consume_at' => null]);
    $fresh->forceFill(['created_at' => now()->subDays(1)])->saveQuietly();

    expect($this->reservationService->expireStale())->toBe(0)
        ->and($fresh->fresh()->status)->toBe('active');
});

// =========================================================================
//  TC.3 (NEW-LOT-1) — split nets out active reservations
// =========================================================================

it('NEW-LOT-1: a fully reserved lot cannot be fully split', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    seedReservationStockLevel($this->warehouse->id, $this->material->id, $lot->id, 100);
    makeReservationRow($lot, 100);

    expect(fn () => $this->lotService->split($lot, [['qty' => 100]]))
        ->toThrow(ValidationException::class);
});

it('NEW-LOT-1: only the un-reserved remainder of a lot may be split off', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    seedReservationStockLevel($this->warehouse->id, $this->material->id, $lot->id, 100);
    makeReservationRow($lot, 60);

    // 40 is available (100 − 60 reserved); 40 splits fine, 50 must be rejected.
    expect(fn () => $this->lotService->split($lot->fresh(), [['qty' => 50]]))
        ->toThrow(ValidationException::class);

    $result = $this->lotService->split($lot->fresh(), [['qty' => 40]]);
    expect($result['children'])->toHaveCount(1)
        ->and((float) $result['children'][0]->qty_on_hand)->toBe(40.0);
});

// =========================================================================
//  TC.4 (NEW-LOT-2) — renew() re-checks availability
// =========================================================================

it('NEW-LOT-2: renewing an expired reservation that would over-reserve is rejected', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    $r1 = makeReservationRow($lot, 100, ['status' => 'expired', 'expected_consume_at' => now()->subDays(10)]);
    // R2 active claims the whole lot while R1 sat expired.
    makeReservationRow($lot, 100);

    expect(fn () => $this->reservationService->renew($r1->fresh()))
        ->toThrow(ValidationException::class);

    expect($r1->fresh()->status)->toBe('expired');
});

it('NEW-LOT-2: renewing an expired reservation that still fits succeeds', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    $r1 = makeReservationRow($lot, 40, ['status' => 'expired', 'expected_consume_at' => now()->subDays(10)]);

    $renewed = $this->reservationService->renew($r1->fresh());

    expect($renewed->status)->toBe('active');
});

// =========================================================================
//  TC.5 (NEW-LOT-3, M10) — release reservations on dispose + recall quarantine
// =========================================================================

it('NEW-LOT-3: disposing a lot cancels its active reservations', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    $reservation = makeReservationRow($lot, 50);

    $this->lotService->dispose($lot->fresh(), force: true);

    expect($reservation->fresh()->status)->toBe('cancelled');
});

it('M10: a recall quarantining a lot releases the open reservation', function () {
    $rootLot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    $reservation = makeReservationRow($rootLot, 50);

    $this->recallService->initiate([
        'organization_id' => $this->orgId,
        'root_lot_id' => $rootLot->id,
        'reason' => 'contamination',
        'initiated_by_id' => $this->user->id,
    ]);

    expect($rootLot->fresh()->status->value)->toBe('quarantined')
        ->and($reservation->fresh()->status)->toBe('cancelled');
});

it('M10: a recall releases reservations on a downstream child lot too', function () {
    $rootLot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);
    $childLot = makeReservationLot(['qty_on_hand' => 30, 'received_qty' => 30]);
    GenealogyLink::create([
        'parent_lot_id' => $rootLot->id,
        'child_lot_id' => $childLot->id,
        'qty_consumed' => 30,
        'unit' => 'kg',
        'consumed_at' => now(),
        'source_event_type' => 'split',
        'source_event_id' => $rootLot->id,
    ]);
    $childReservation = makeReservationRow($childLot, 20);

    $this->recallService->initiate([
        'organization_id' => $this->orgId,
        'root_lot_id' => $rootLot->id,
        'reason' => 'contamination',
        'initiated_by_id' => $this->user->id,
    ]);

    expect($childLot->fresh()->status->value)->toBe('quarantined')
        ->and($childReservation->fresh()->status)->toBe('cancelled');
});

// =========================================================================
//  TC.6 (H13) — over-reservation guard under lock
// =========================================================================

it('H13: reservations cannot exceed qty_on_hand', function () {
    $lot = makeReservationLot(['qty_on_hand' => 100, 'received_qty' => 100]);

    $this->reservationService->reserve([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 70,
        'reserved_by_id' => $this->user->id,
    ]);

    // 70 already reserved → only 30 available; a 40 reservation must be rejected.
    expect(fn () => $this->reservationService->reserve([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 40,
        'reserved_by_id' => $this->user->id,
    ]))->toThrow(ValidationException::class);

    $totalActive = (float) MaterialLotReservation::where('material_lot_id', $lot->id)
        ->where('status', 'active')
        ->sum('qty_reserved');
    expect($totalActive)->toBeLessThanOrEqual(100.0);
});
