<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Plan 030 — Terminal-state guards (test-gap audit, finding 3: error handling).
 *
 * TESTS.md "Error handling" listed eight 409/404 scenarios; only a handful
 * (SHIFT_ALREADY_OPEN, SHIFT_HAS_PAYMENTS, NO_OPEN_SHIFT on /pos/orders) had
 * coverage. This file closes the SETTLED-state transitions and the missing
 * 404 / payments-guard cases:
 *
 *   - POST  …/close      on a settled session → 409 SHIFT_NOT_OPEN
 *   - POST  …/cash-events on a settled session → 409 SHIFT_NOT_OPEN
 *   - PATCH …/draft      on a settled session → 409 SHIFT_NOT_OPEN
 *   - POST  …/abandon    on a settled session → 409 SHIFT_NOT_OPEN
 *   - GET   …/{id}       for a non-existent session → 404
 *   - POST  /pos/orders/{order}/payments with no open shift → 409 NO_OPEN_SHIFT
 *
 * Money math is not the focus here — these pin the state machine's closed doors.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'state-guard-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
});

function actingAsGuardCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

/** Open then settle a shift with a flat drawer; returns the settled session id. */
function openAndSettle(): string
{
    $session = actingAsGuardCashier()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
            ],
            'opened_by_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->json('data');

    actingAsGuardCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk();

    return $session['id'];
}

it('rejects a second close on a settled session with 409 SHIFT_NOT_OPEN', function () {
    $id = openAndSettle();

    $response = actingAsGuardCashier()->postJson(
        "/api/v1/pos/till/sessions/{$id}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ],
    )->assertStatus(409);

    expect($response->json('code'))->toBe('SHIFT_NOT_OPEN');
    expect($response->json('status'))->toBe('settled');
});

it('rejects a cash-event on a settled session with 409 SHIFT_NOT_OPEN', function () {
    $id = openAndSettle();

    $response = actingAsGuardCashier()->postJson(
        "/api/v1/pos/till/sessions/{$id}/cash-events",
        ['event_type' => 'paid_out', 'amount' => 500, 'reason' => 'late drop'],
    )->assertStatus(409);

    expect($response->json('code'))->toBe('SHIFT_NOT_OPEN');
});

it('rejects a draft save on a settled session with 409 SHIFT_NOT_OPEN', function () {
    $id = openAndSettle();

    $response = actingAsGuardCashier()->patchJson(
        "/api/v1/pos/till/sessions/{$id}/draft",
        ['closing_counts' => [], 'tender_details' => [], 'closing_note' => 'too late'],
    )->assertStatus(409);

    expect($response->json('code'))->toBe('SHIFT_NOT_OPEN');
});

it('rejects an abandon on a settled session with 409 SHIFT_NOT_OPEN', function () {
    $id = openAndSettle();

    $response = actingAsGuardCashier()->postJson(
        "/api/v1/pos/till/sessions/{$id}/abandon",
        ['abandon_reason' => 'oops'],
    )->assertStatus(409);

    expect($response->json('code'))->toBe('SHIFT_NOT_OPEN');
});

it('returns 404 for a GET on a non-existent session', function () {
    // A well-formed but unknown uuid must 404, not 500 / leak.
    actingAsGuardCashier()
        ->getJson('/api/v1/pos/till/sessions/'.Str::uuid())
        ->assertNotFound();
});

it('blocks POST /pos/orders/{order}/payments with 409 NO_OPEN_SHIFT when no shift is open', function () {
    // A till exists for the branch but has no open session.
    Till::factory()->create([
        'till_code' => 'MAIN',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'current_session_id' => null,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);

    $response = actingAsGuardCashier()->postJson(
        "/api/v1/pos/orders/{$order->id}/payments",
        ['amount' => 1000],
    )->assertStatus(409);

    expect($response->json('code'))->toBe('NO_OPEN_SHIFT');
});
