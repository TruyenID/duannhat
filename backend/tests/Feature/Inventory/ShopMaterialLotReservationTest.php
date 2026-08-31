<?php

/**
 * #3112 — shop-scoped write path for material-lot holds.
 *
 * Every case here goes through the HTTP endpoint, never through
 * `MaterialLotReservationService` directly. That is the whole point:
 * `$request->validate()` strips every key without a rule, so a service-level
 * test stays green while the real request silently drops the field (#2622).
 * The `material_batch_id` case below is the deliberate detector for that —
 * remove its rule from the controller and this file must go red.
 *
 * The other property under test is that opening the shop ring did NOT open the
 * HQ ring. The SAME user drives both: it holds `staff`, which carries
 * `material.create`, so the shop route must accept it — and it is neither
 * `org-admin` nor `org-manager`, so the HQ route must keep refusing it.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    // The baseline Organization row (id === console_organization_id) is created
    // by tests/Pest.php for every Feature test.
    $this->orgId = '00000000-0000-0000-0000-000000000001';

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rsv-'.Str::random(6),
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
    ]);

    $this->otherWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'is_active' => true,
    ]);

    // A central / shared warehouse. Out of scope for #3112 on purpose — a
    // shop-scoped route must refuse it until a central-warehouse ruling exists.
    $this->sharedWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'is_active' => true,
    ]);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    // `staff`, org-wide: holds material.view/create/update, holds NEITHER
    // org-admin nor org-manager. Both halves matter — see the file docblock.
    $this->user->assignRole(Role::query()->where('slug', 'staff')->firstOrFail(), $this->orgId);

    $this->makeLot = function (Warehouse $warehouse, float $qty = 100): MaterialLot {
        return MaterialLot::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'material_id' => $this->material->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'active',
            'qty_on_hand' => $qty,
            'received_qty' => $qty,
            'produced_by_batch_id' => null,
        ]);
    };

    $this->makeBatch = function (Warehouse $warehouse): MaterialBatch {
        return MaterialBatch::factory()->create([
            'organization_id' => $this->orgId,
            'warehouse_id' => $warehouse->id,
            'material_id' => $this->material->id,
            'status' => 'draft',
            'stock_out_transaction_id' => null,
            'stock_in_transaction_id' => null,
            'created_by_id' => $this->user->id,
            'approved_by_id' => null,
        ]);
    };

    $this->baseUrl = "/api/v1/shops/{$this->shop->slug}/material-lot-reservations";

    $this->actingAs($this->user);
});

// =========================================================================
//  Authentication
// =========================================================================

it('refuses an unauthenticated hold', function () {
    Auth::forgetGuards();

    $this->postJson($this->baseUrl, [
        'material_lot_id' => (string) Str::uuid(),
        'qty_reserved' => 1,
    ])->assertUnauthorized();
});

// =========================================================================
//  Store — the shop's own warehouse
// =========================================================================

it('holds qty on a lot of the shop own warehouse', function () {
    $lot = ($this->makeLot)($this->warehouse);

    $response = $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 40,
        'reason' => 'Giữ cho mẻ sáng mai',
    ])->assertCreated();

    $reservation = MaterialLotReservation::query()
        ->where('material_lot_id', $lot->id)
        ->firstOrFail();

    expect($reservation->status)->toBe('active')
        ->and((float) $reservation->qty_reserved)->toBe(40.0)
        ->and($reservation->reserved_by_id)->toBe($this->user->id)
        ->and($reservation->organization_id)->toBe($this->orgId)
        ->and($response->json('data.id'))->toBe($reservation->id);
});

/**
 * #2622 detector. `material_batch_id` is optional, so nothing 4xx-s when the
 * rule disappears — the only thing that changes is that the column lands NULL.
 * Assert on the stored value, from the HTTP path, or this test proves nothing.
 */
it('carries material_batch_id from the request all the way into the row', function () {
    $lot = ($this->makeLot)($this->warehouse);
    $batch = ($this->makeBatch)($this->warehouse);

    $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'material_batch_id' => $batch->id,
    ])->assertCreated();

    $reservation = MaterialLotReservation::query()
        ->where('material_lot_id', $lot->id)
        ->firstOrFail();

    expect($reservation->material_batch_id)->toBe($batch->id);
});

it('applies the over-reservation guard through the endpoint', function () {
    $lot = ($this->makeLot)($this->warehouse, 100);

    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 70,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
        'material_batch_id' => null,
    ]);

    $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 40,
    ])->assertStatus(422)->assertJsonValidationErrors('qty_reserved');
});

it('rejects a non-positive hold quantity', function () {
    $lot = ($this->makeLot)($this->warehouse);

    $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 0,
    ])->assertStatus(422)->assertJsonValidationErrors('qty_reserved');
});

// =========================================================================
//  Store — the branch boundary
// =========================================================================

it('refuses a lot that lives in another shop warehouse', function () {
    $lot = ($this->makeLot)($this->otherWarehouse);

    $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 5,
    ])->assertForbidden();

    expect(MaterialLotReservation::query()->where('material_lot_id', $lot->id)->exists())->toBeFalse();
});

it('refuses a lot in a shared warehouse with no branch', function () {
    $lot = ($this->makeLot)($this->sharedWarehouse);

    $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 5,
    ])->assertForbidden();

    expect(MaterialLotReservation::query()->where('material_lot_id', $lot->id)->exists())->toBeFalse();
});

it('refuses to pin the shop own lot to another shop batch', function () {
    $lot = ($this->makeLot)($this->warehouse);
    $foreignBatch = ($this->makeBatch)($this->otherWarehouse);

    $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 5,
        'material_batch_id' => $foreignBatch->id,
    ])->assertForbidden();

    expect(MaterialLotReservation::query()->where('material_lot_id', $lot->id)->exists())->toBeFalse();
});

// =========================================================================
//  Index — holds of one batch
// =========================================================================

it('lists the holds of a batch of this shop', function () {
    $lot = ($this->makeLot)($this->warehouse);
    $batch = ($this->makeBatch)($this->warehouse);

    MaterialLotReservation::factory()->count(2)->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 5,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
        'material_batch_id' => $batch->id,
    ]);

    // Noise: a hold on the same lot but attached to no batch.
    MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 5,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
        'material_batch_id' => null,
    ]);

    $this->getJson("{$this->baseUrl}?material_batch_id={$batch->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('refuses to list the holds of another shop batch', function () {
    $foreignBatch = ($this->makeBatch)($this->otherWarehouse);

    $this->getJson("{$this->baseUrl}?material_batch_id={$foreignBatch->id}")
        ->assertForbidden();
});

it('requires a batch to list holds from a shop route', function () {
    $this->getJson($this->baseUrl)
        ->assertStatus(422)
        ->assertJsonValidationErrors('material_batch_id');
});

// =========================================================================
//  Release
// =========================================================================

it('releases a hold on the shop own lot', function () {
    $lot = ($this->makeLot)($this->warehouse);
    $reservation = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
        'material_batch_id' => null,
    ]);

    $this->postJson("{$this->baseUrl}/{$reservation->id}/release")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($reservation->fresh()->status)->toBe('cancelled');
});

it('refuses to release a hold on another shop lot', function () {
    $lot = ($this->makeLot)($this->otherWarehouse);
    $reservation = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
        'material_batch_id' => null,
    ]);

    $this->postJson("{$this->baseUrl}/{$reservation->id}/release")
        ->assertForbidden();

    expect($reservation->fresh()->status)->toBe('active');
});

// =========================================================================
//  Renew
// =========================================================================

it('renews a hold on the shop own lot', function () {
    $lot = ($this->makeLot)($this->warehouse);
    $reservation = MaterialLotReservation::factory()->expired()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'reserved_by_id' => $this->user->id,
        'organization_id' => $this->orgId,
        'material_batch_id' => null,
    ]);

    $this->postJson("{$this->baseUrl}/{$reservation->id}/renew", [
        'expected_consume_at' => now()->addDays(3)->toIso8601String(),
    ])->assertOk()->assertJsonPath('data.status', 'active');

    expect($reservation->fresh()->status)->toBe('active');
});

it('refuses to renew a hold on a shared warehouse lot', function () {
    $lot = ($this->makeLot)($this->sharedWarehouse);
    $reservation = MaterialLotReservation::factory()->expired()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'reserved_by_id' => $this->user->id,
        'organization_id' => $this->orgId,
        'material_batch_id' => null,
    ]);

    $this->postJson("{$this->baseUrl}/{$reservation->id}/renew")
        ->assertForbidden();

    expect($reservation->fresh()->status)->toBe('expired');
});

// =========================================================================
//  The HQ ring did not move
// =========================================================================

it('keeps the HQ route HQ-only for the very user the shop route accepts', function () {
    $lot = ($this->makeLot)($this->warehouse);

    // Same user, same lot, same payload — accepted at the shop ring...
    $this->postJson($this->baseUrl, [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 5,
    ])->assertCreated();

    // ...and still refused at the org-wide HQ ring.
    $this->postJson("/api/v1/hq/{$this->brand->slug}/material-lot-reservations", [
        'material_lot_id' => $lot->id,
        'qty_reserved' => 5,
    ])->assertForbidden();
});

it('keeps the HQ release route HQ-only', function () {
    $lot = ($this->makeLot)($this->warehouse);
    $reservation = MaterialLotReservation::factory()->create([
        'material_lot_id' => $lot->id,
        'qty_reserved' => 10,
        'reserved_by_id' => $this->user->id,
        'status' => 'active',
        'organization_id' => $this->orgId,
        'material_batch_id' => null,
    ]);

    $this->postJson("/api/v1/hq/{$this->brand->slug}/material-lot-reservations/{$reservation->id}/release")
        ->assertForbidden();

    expect($reservation->fresh()->status)->toBe('active');
});
