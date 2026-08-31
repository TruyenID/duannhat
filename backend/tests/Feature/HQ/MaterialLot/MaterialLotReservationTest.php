<?php

/**
 * Plan-018 Group B.1 — MaterialLotReservation service tests.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialLotReservationService;
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
        'slug' => 'rsv-'.Str::random(4),
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

    $this->service = app(MaterialLotReservationService::class);
});

it('reserves qty from an active lot', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    $reservation = $this->service->reserve([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 50,
        'reserved_by_id' => $this->user->id,
    ]);

    expect($reservation->status)->toBe('active')
        ->and((float) $reservation->qty_reserved)->toBe(50.0)
        ->and($reservation->material_lot_id)->toBe($lot->id)
        ->and($reservation->organization_id)->toBe($this->orgId);
});

it('rejects reservation that exceeds available qty', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    // Existing reservation of 50
    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 50,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
    ]);

    // Trying to reserve 60 when only 50 available
    expect(fn () => $this->service->reserve([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 60,
        'reserved_by_id' => $this->user->id,
    ]))->toThrow(ValidationException::class);
});

it('releases an active reservation idempotently', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    $reservation = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 30,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
    ]);

    $released = $this->service->release($reservation);
    expect($released->status)->toBe('cancelled');

    // Second release is idempotent (already cancelled)
    $releasedAgain = $this->service->release($reservation);
    expect($releasedAgain->status)->toBe('cancelled');
});

it('consumes an active reservation', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    $reservation = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 30,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
    ]);

    $consumed = $this->service->consume($reservation);
    expect($consumed->status)->toBe('consumed');
});

it('rejects consuming a non-active reservation', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    $reservation = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 30,
        'reserved_by_id' => $this->user->id,
        'status' => 'cancelled',
        'organization_id' => $this->orgId,
    ]);

    expect(fn () => $this->service->consume($reservation))
        ->toThrow(ValidationException::class);
});

it('computes available qty excluding active reservations', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 30,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
    ]);

    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 20,
        'reserved_by_id' => $this->user->id,
        'status' => 'cancelled',
        'organization_id' => $this->orgId,
    ]);

    $available = $this->service->computeAvailableQty($lot);
    expect($available)->toBe(70.0);
});

it('expires stale reservations past 7-day TTL', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'qty_on_hand' => 100,
    ]);

    // Stale: expected 10 days ago
    $stale = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'expected_consume_at' => now()->subDays(10),
        'organization_id' => $this->orgId,
    ]);

    // Fresh: expected 2 days ago (within 7-day TTL)
    $fresh = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'expected_consume_at' => now()->subDays(2),
        'organization_id' => $this->orgId,
    ]);

    $expired = $this->service->expireStale();
    expect($expired)->toBe(1);

    expect(MaterialLotReservation::find($stale->id)->status)->toBe('expired');
    expect(MaterialLotReservation::find($fresh->id)->status)->toBe('active');
});
