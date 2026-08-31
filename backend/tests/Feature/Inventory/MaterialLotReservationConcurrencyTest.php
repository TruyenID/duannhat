<?php

/**
 * Plan-018 test-gap coverage — reservation money-precision + concurrency guards.
 *
 * The existing reservation/qty tests assert only at trivial whole-number values
 * (100 − 30 = 70, drain-to-zero). This file closes two high-risk gaps:
 *
 *   1. MONEY PRECISION — computeAvailableQty and the over-reservation guard
 *      operate on Decimal(18,4) qty. Prove the fractional decimal math is exact
 *      (using binary-exact fractions so the assertion is deterministic, never a
 *      float-epsilon flake) and that the guard boundary is at the last 0.25 unit.
 *
 *   2. CONCURRENCY / OVERSELL — plan-018 Group A scenario 2 ("concurrent FEFO
 *      picks racing for the last units → exactly one succeeds, never a negative
 *      qty") and the H13 over-reservation guard. The test environment is SQLite
 *      in-memory (phpunit.xml) which serialises writes, so — following the same
 *      convention as CouponConcurrencyTest — we prove the application-level
 *      lockForUpdate + WHERE-guard logic is correct IN SEQUENCE: N contending
 *      attempts yield exactly the capacity in successes and never oversell.
 *      True parallel MySQL racing is a deferred QA concern.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\Organization;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialLotReservationService;
use App\Services\Inventory\StockTransactionService;
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
        'slug' => 'rc-'.Str::random(4),
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'auto_approve_stock_out' => true,
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

it('computes available qty exactly from fractional Decimal(18,4) reservations', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'qty_on_hand' => 100,
        'received_qty' => 100,
    ]);

    // Binary-exact quarter fractions so the SUM is deterministic: 60.75 + 9.25
    // = 70.0000 active reserved. A cancelled 40.50 must be ignored entirely.
    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 60.75,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
    ]);
    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 9.25,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
    ]);
    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 40.50,
        'reserved_by_id' => $this->user->id,
        'status' => 'cancelled',
        'organization_id' => $this->orgId,
    ]);

    expect($this->service->computeAvailableQty($lot))->toBe(30.0);
});

it('guards the over-reservation boundary at the last fractional unit', function () {
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'qty_on_hand' => 100,
        'received_qty' => 100,
    ]);

    // Reserve 62.50 → available 37.50 remaining.
    $this->service->reserve([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 62.50,
        'reserved_by_id' => $this->user->id,
    ]);
    expect($this->service->computeAvailableQty($lot))->toBe(37.5);

    // Asking for one quarter-unit more than the 37.50 available is rejected...
    expect(fn () => $this->service->reserve([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 37.75,
        'reserved_by_id' => $this->user->id,
    ]))->toThrow(ValidationException::class);

    // ...but reserving EXACTLY the remaining 37.50 succeeds and zeroes available.
    $this->service->reserve([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 37.50,
        'reserved_by_id' => $this->user->id,
    ]);
    expect($this->service->computeAvailableQty($lot))->toBe(0.0);
});

it('never over-reserves under contention — exactly capacity succeeds (H13 guard)', function () {
    // Lot holds 10; each attempt reserves 3. At most 3 can fit (9 reserved),
    // leaving 1.0 available. A 10-attempt loop must yield exactly 3 successes
    // and 7 insufficient-qty rejections — never a 4th success (which would push
    // Σ active reservations to 12 > 10 on-hand).
    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'qty_on_hand' => 10,
        'received_qty' => 10,
    ]);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 10; $i++) {
        try {
            $this->service->reserve([
                'material_lot_id' => $lot->id,
                'qty_reserved' => 3,
                'reserved_by_id' => $this->user->id,
            ]);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(3);
    expect($rejections)->toBe(7);

    // Σ active reservations never exceeded on-hand.
    $totalActive = (float) MaterialLotReservation::where('material_lot_id', $lot->id)
        ->where('status', 'active')
        ->sum('qty_reserved');
    expect($totalActive)->toBe(9.0);
    expect($totalActive)->toBeLessThanOrEqual(10.0);
    expect($this->service->computeAvailableQty($lot))->toBe(1.0);
});

it('two drains racing for a lot last 5 units — exactly one wins, never negative', function () {
    // Plan-018 Group A scenario 2. Lot holds 5; two consumers each try to draw
    // all 5. Serialised, the first drains it to 0 and the second — finding the
    // lot at 0 — is rejected by the qty guard rather than persisting a negative
    // balance. Exactly one drain succeeds.
    $stock = app(StockTransactionService::class);

    $lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
        'status' => 'active',
        'source' => 'inbound',
        'unit' => 'g',
        'qty_on_hand' => 5,
        'received_qty' => 5,
    ]);
    StockLevel::create([
        'warehouse_id' => $this->warehouse->id,
        'material_id' => $this->material->id,
        'material_lot_id' => $lot->id,
        'quantity' => 5,
        'unit' => 'g',
        'alert_enabled' => true,
    ]);

    $drain = function () use ($stock, $lot): void {
        $txn = $stock->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'stock_out',
            'sub_type' => 'adjustment_out',
            'created_by_id' => (string) Str::uuid(),
            'items' => [[
                'material_id' => $this->material->id,
                'material_lot_id' => $lot->id,
                'quantity' => 5,
                'unit' => 'g',
            ]],
        ]);
        $stock->submit($txn);
    };

    $successes = 0;
    $failures = 0;

    foreach ([1, 2] as $attempt) {
        try {
            $drain();
            $successes++;
        } catch (Throwable) {
            $failures++;
        }
    }

    expect($successes)->toBe(1);
    expect($failures)->toBe(1);

    // The lot balance settled at exactly zero — never driven negative.
    expect((float) MaterialLot::find($lot->id)->qty_on_hand)->toBe(0.0);
});
