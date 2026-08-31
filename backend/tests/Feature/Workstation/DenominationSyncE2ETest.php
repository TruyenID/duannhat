<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Production incident regression: shop manager adds a JPY denomination
 * via Shop Settings, waits 5+ min, LAN-mode pos-web shows zero rows.
 *
 * This test exercises the Cloud-side leg end-to-end:
 *   1. Shop manager POSTs to /api/v1/shops/{slug}/denominations.
 *   2. Workstation device GETs /api/v1/workstation/till-denominations.
 *   3. Assert the new denomination appears in the workstation feed.
 *
 * If THIS test passes, the bug is workstation-side (decode, throttle,
 * binary version, etc.). If it FAILS, the bug is Cloud-side (org scope
 * mismatch, soft-delete scope, model cast, etc.). Either way it
 * narrows the search and locks down the contract going forward.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'denom-e2e-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->wsToken = Str::random(64);
    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

it('shop-created denomination appears in the workstation feed', function () {
    // Pre-check: workstation feed empty (no global seed in test DB).
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/till-denominations')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Shop manager adds a JPY 1000 yen note via Shop Settings UI.
    $payload = [
        'currency_code' => 'JPY',
        'value' => 1000,
        'kind' => 'note',
        'label' => '千円札',
        'sort_order' => 5,
        'is_active' => true,
    ];
    $created = $this->withoutToken()
        ->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/denominations", $payload)
        ->assertCreated()
        ->json('data');

    expect($created['currency_code'])->toBe('JPY');
    // Verify the row landed with the right org via direct DB query —
    // some Eloquent resources hide organization_id from the JSON.
    $rowOrgId = Denomination::where('id', $created['id'])->value('organization_id');
    expect($rowOrgId)->toBe($this->orgId);

    // Now the workstation feed MUST surface that row — anything else is the
    // bug user is hitting in production.
    $feed = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/till-denominations')
        ->assertOk()
        ->json('data');

    expect($feed)->toHaveCount(1);
    expect($feed[0]['currency_code'])->toBe('JPY');
    expect($feed[0]['kind'])->toBe('note');
    // Lock the value-shape contract — Laravel decimal:2 emits quoted string
    // in JSON. Workstation's flexFloat handles both, but this assert pins
    // what the wire actually looks like so a future cast change can't
    // silently regress.
    expect((string) $feed[0]['value'])->toBe('1000.00');
});

it('feed scopes by device organization and excludes other-org denominations', function () {
    // Other-org denomination — must NOT leak into this device's feed.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    Denomination::create([
        'currency_code' => 'JPY',
        'value' => 9999,
        'kind' => 'note',
        'label' => 'OTHER-ORG-SHOULD-NOT-APPEAR',
        'sort_order' => 0,
        'is_active' => true,
        'organization_id' => $otherOrgId,
    ]);

    // Our org's denomination.
    Denomination::create([
        'currency_code' => 'JPY',
        'value' => 1000,
        'kind' => 'note',
        'label' => 'OURS',
        'sort_order' => 0,
        'is_active' => true,
        'organization_id' => $this->orgId,
    ]);

    $feed = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/till-denominations')
        ->assertOk()
        ->json('data');

    // Must surface our org's row...
    $labels = array_column($feed, 'label');
    expect($labels)->toContain('OURS');
    // ...and must NOT leak the other org's row.
    expect($labels)->not->toContain('OTHER-ORG-SHOULD-NOT-APPEAR');
});

it('feed includes system-wide (organization_id NULL) seeded denominations', function () {
    // The DenominationSeeder seeds JPY denominations with organization_id
    // NULL so every org sees them. The workstation feed must surface
    // these too — otherwise a fresh shop with no shop-scoped denominations
    // would see an empty dropdown until the manager manually adds rows.
    Denomination::create([
        'currency_code' => 'JPY',
        'value' => 500,
        'kind' => 'coin',
        'label' => 'global-500',
        'sort_order' => 0,
        'is_active' => true,
        'organization_id' => null,
    ]);

    $feed = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/till-denominations')
        ->assertOk()
        ->json('data');

    $labels = array_column($feed, 'label');
    expect($labels)->toContain('global-500');
});
