<?php

/**
 * Plan-018 Group D.1 — Workstation /lots sync endpoint.
 *
 * Test-gap coverage: the pre-existing WorkstationThrottleTest only asserts the
 * slow-loop fan-out reaches /workstation/lots with a 200 — it never exercises
 * the endpoint's contract. These tests lock down the four D.1 scenarios that
 * were untested:
 *   - branch-warehouse scoping + response shape (material_id filter),
 *   - cross-branch warehouse isolation,
 *   - expiring_within_days filter,
 *   - the 300/min per-device rate limit boundary (429) + per-device bucketing.
 *
 * NOTE ON SPEC DIVERGENCE: plan-018 TESTS.md phrases the isolation scenario as
 * "device token from a different branch → 403". The shipped LotController does
 * NOT 403 — it silently scopes the query to the device's own branch warehouses
 * (whereHas('warehouse', branch_id)). The isolation test below asserts the
 * ACTUAL behaviour (a device only ever sees its own branch's lots; another
 * branch's lots are excluded, not rejected with 403).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
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
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'timezone' => 'Asia/Tokyo',
    ]);
    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->wsToken = Str::random(64);
    $this->device = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->headers = ['Authorization' => "Bearer {$this->wsToken}"];
});

/**
 * Create a lot defaulting to the test's branch warehouse + material. `$test` is
 * the current Pest test instance (its beforeEach() stamps the fixtures onto
 * dynamic properties we read here).
 *
 * @param  array<string, mixed>  $overrides
 */
function makeWsSyncLot(object $test, array $overrides = []): MaterialLot
{
    return MaterialLot::factory()->create(array_merge([
        'organization_id' => $test->orgId,
        'brand_id' => $test->brand->id,
        'material_id' => $test->material->id,
        'warehouse_id' => $test->warehouse->id,
        'status' => 'active',
        'qty_on_hand' => 50,
        'received_qty' => 50,
    ], $overrides));
}

it('returns branch-scoped active lots filtered by material_id with the D.1 shape', function () {
    // days_until_expiry is computed server-side against an Asia/Tokyo "today".
    // Built from now()+10d, the fixture and the server land on different calendar
    // dates whenever the test runs near a date boundary, and the assertion drifts
    // to 9. Pin the clock so the two agree.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));

    $wanted = makeWsSyncLot($this, [
        'lot_code' => 'WS-WANT-001',
        'qty_on_hand' => 42,
        'expiry_date' => now()->addDays(10)->format('Y-m-d'),
    ]);

    // A lot of a DIFFERENT material in the same branch — must be excluded by the
    // material_id filter.
    $otherMaterial = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    makeWsSyncLot($this, [
        'material_id' => $otherMaterial->id,
        'lot_code' => 'WS-OTHER-MAT',
    ]);

    $resp = $this->withHeaders($this->headers)
        ->getJson("/api/v1/workstation/lots?material_id={$this->material->id}")
        ->assertOk()
        ->assertJsonStructure([
            'lots' => [['id', 'lot_code', 'material_id', 'qty_on_hand', 'expiry_date', 'status', 'days_until_expiry']],
            'generated_at',
        ]);

    $lots = $resp->json('lots');
    expect($lots)->toHaveCount(1);
    expect($lots[0]['id'])->toBe($wanted->id);
    expect($lots[0]['lot_code'])->toBe('WS-WANT-001');
    expect($lots[0]['material_id'])->toBe($this->material->id);
    expect((float) $lots[0]['qty_on_hand'])->toBe(42.0);
    // 10 days out from an Asia/Tokyo "today" → days_until_expiry is a positive int.
    expect($lots[0]['days_until_expiry'])->toBe(10);
});

it('isolates lots to the device branch — another branch lot is never surfaced', function () {
    $mine = makeWsSyncLot($this, ['lot_code' => 'MINE']);

    // Second branch + its own warehouse + an active lot living there.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $otherWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);
    MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $otherWarehouse->id,
        'status' => 'active',
        'qty_on_hand' => 99,
        'received_qty' => 99,
        'lot_code' => 'FOREIGN',
    ]);

    // My device (branch A) sees only MINE, not FOREIGN.
    $mineResp = $this->withHeaders($this->headers)
        ->getJson('/api/v1/workstation/lots')
        ->assertOk();
    $mineCodes = collect($mineResp->json('lots'))->pluck('lot_code')->all();
    expect($mineCodes)->toContain('MINE');
    expect($mineCodes)->not->toContain('FOREIGN');

    // A device paired to branch B sees only FOREIGN, not MINE — symmetric isolation.
    $otherToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $otherToken,
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);
    $otherResp = $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
        ->getJson('/api/v1/workstation/lots')
        ->assertOk();
    $otherCodes = collect($otherResp->json('lots'))->pluck('lot_code')->all();
    expect($otherCodes)->toContain('FOREIGN');
    expect($otherCodes)->not->toContain('MINE');

    expect($mine->lot_code)->toBe('MINE'); // sanity: fixture created
});

it('expiring_within_days=3 returns only lots expiring on or before now+3d', function () {
    makeWsSyncLot($this, [
        'lot_code' => 'SOON',
        'expiry_date' => now()->addDays(2)->format('Y-m-d'),
    ]);
    makeWsSyncLot($this, [
        'lot_code' => 'LATER',
        'expiry_date' => now()->addDays(5)->format('Y-m-d'),
    ]);
    // A null-expiry lot must NOT satisfy the expiring filter (whereNotNull guard).
    makeWsSyncLot($this, [
        'lot_code' => 'NO-EXPIRY',
        'expiry_date' => null,
    ]);

    $resp = $this->withHeaders($this->headers)
        ->getJson('/api/v1/workstation/lots?expiring_within_days=3')
        ->assertOk();

    $codes = collect($resp->json('lots'))->pluck('lot_code')->all();
    expect($codes)->toBe(['SOON']);
});

it('excludes depleted and zero-qty lots (active + qty_on_hand>0 only)', function () {
    makeWsSyncLot($this, ['lot_code' => 'ACTIVE']);
    makeWsSyncLot($this, [
        'lot_code' => 'DEPLETED',
        'status' => 'depleted',
        'qty_on_hand' => 0,
    ]);
    makeWsSyncLot($this, [
        'lot_code' => 'ZERO',
        'status' => 'active',
        'qty_on_hand' => 0,
    ]);

    $resp = $this->withHeaders($this->headers)
        ->getJson('/api/v1/workstation/lots')
        ->assertOk();

    $codes = collect($resp->json('lots'))->pluck('lot_code')->all();
    expect($codes)->toBe(['ACTIVE']);
});

it('enforces the 300/min per-device rate limit and buckets per device', function () {
    // 300 requests succeed within the window; the 301st is throttled.
    for ($i = 0; $i < 300; $i++) {
        $this->withHeaders($this->headers)
            ->getJson('/api/v1/workstation/lots')
            ->assertOk();
    }

    $this->withHeaders($this->headers)
        ->getJson('/api/v1/workstation/lots')
        ->assertStatus(429);

    // The throttle keys by device id — a SECOND freshly-paired device on the
    // same branch/org (same NAT/IP) still has its full budget and is NOT 429d.
    $freshToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $freshToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$freshToken}"])
        ->getJson('/api/v1/workstation/lots')
        ->assertOk();
});
