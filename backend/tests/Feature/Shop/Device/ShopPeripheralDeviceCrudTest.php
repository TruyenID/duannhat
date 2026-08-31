<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\User;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'main-shop',
        'is_active' => true,
    ]);

    $this->otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/peripheral-devices";
});

it('requires authentication on index', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('returns only peripheral devices of the current shop', function () {
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Shop Kiosk',
        'type' => 'kiosk',
    ]);
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'name' => 'Other Kiosk',
        'type' => 'kiosk',
    ]);

    $response = $this->actingAs($this->user)->getJson($this->base);
    $response->assertOk();
    expect(collect($response->json('data'))->pluck('name')->all())
        ->toEqual(['Shop Kiosk']);
});

it('does not expose secret field to shop clients', function () {
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Secure Kiosk',
        'type' => 'kiosk',
        'secret' => 'super-secret',
    ]);

    $response = $this->actingAs($this->user)->getJson($this->base);
    $response->assertOk()
        ->assertJsonMissingPath('data.0.secret');
});

it('creates and scopes peripheral to shop in URL', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Checkout Kiosk',
        'type' => 'kiosk',
        'branch_id' => $this->otherShop->id,
    ]);

    $response->assertCreated();
    $device = PeripheralDevice::latest('id')->first();

    expect($device->branch_id)->toBe($this->shop->id);
    expect($device->organization_id)->toBe($this->orgId);
});

it('prevents duplicate device names within the shop', function () {
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Already Taken',
        'type' => 'kiosk',
    ]);

    $this->actingAs($this->user)
        ->postJson($this->base, [
            'name' => 'Already Taken',
            'type' => 'kiosk',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('returns soft-deleted rows only when with_trashed=true', function () {
    $device = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Old Device',
        'type' => 'pos',
    ]);
    $device->delete();

    $this->actingAs($this->user)->getJson($this->base)->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->user)->getJson("{$this->base}?with_trashed=true")->assertOk()
        ->assertJsonCount(1, 'data');
});

it('updates and restores a peripheral device in the same shop', function () {
    $device = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Front POS',
        'type' => 'pos',
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", [
            'name' => 'Lane POS',
            'type' => 'pos',
        ])
        ->assertOk();

    expect($device->fresh()->name)->toBe('Lane POS');

    $device->delete();
    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/restore")
        ->assertOk();

    expect($device->fresh()->deleted_at)->toBeNull();
});

it('registers a payment_terminal (P400) with host and port metadata', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Counter P400',
        'type' => 'payment_terminal',
        'metadata' => ['host' => '192.168.0.77', 'port' => 8888],
    ]);

    $response->assertCreated();
    $device = PeripheralDevice::latest('id')->first();

    expect($device->type)->toBe('payment_terminal');
    expect($device->metadata['host'])->toBe('192.168.0.77');
    expect($device->metadata['port'])->toBe(8888);
    expect($device->branch_id)->toBe($this->shop->id);
});

it('registers a coin_changer (釣銭機) with just a host (port optional)', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Counter Changer',
        'type' => 'coin_changer',
        'metadata' => ['host' => '192.168.0.10'],
    ])->assertCreated();

    $device = PeripheralDevice::latest('id')->first();
    expect($device->type)->toBe('coin_changer');
    expect($device->metadata['host'])->toBe('192.168.0.10');
});

it('rejects a LAN device with no metadata.host', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Hostless Terminal',
        'type' => 'payment_terminal',
    ])->assertUnprocessable()->assertJsonValidationErrors(['metadata.host']);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Blank Host Changer',
        'type' => 'coin_changer',
        'metadata' => ['host' => ''],
    ])->assertUnprocessable()->assertJsonValidationErrors(['metadata.host']);
});

/*
 * #2422 — deposit timeout on the 釣銭機.
 *
 * On timeout the machine KEEPS the customer's cash, so how long it waits is a
 * per-shop operational decision. Cloud validates the bounds; the workstation
 * reads the value per transaction and falls back to 300s when it is absent.
 */

it('accepts metadata.deposit_timeout_seconds on a coin_changer', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Hongo Changer',
        'type' => 'coin_changer',
        'metadata' => ['host' => '192.168.251.120', 'deposit_timeout_seconds' => 600],
    ])->assertCreated();

    expect(PeripheralDevice::latest('id')->first()->metadata['deposit_timeout_seconds'])->toBe(600);
});

it('leaves deposit_timeout_seconds absent when not supplied — the workstation default stands', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Default Changer',
        'type' => 'coin_changer',
        'metadata' => ['host' => '192.168.0.10'],
    ])->assertCreated();

    expect(PeripheralDevice::latest('id')->first()->metadata)
        ->not->toHaveKey('deposit_timeout_seconds');
});

it('rejects a deposit timeout outside the Glory bounds', function (mixed $value) {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Bad Timeout Changer '.json_encode($value),
        'type' => 'coin_changer',
        'metadata' => ['host' => '192.168.0.10', 'deposit_timeout_seconds' => $value],
    ])->assertUnprocessable()->assertJsonValidationErrors(['metadata.deposit_timeout_seconds']);
})->with([
    // 0 is the Glory API's "wait forever" — refused: the machine would sit
    // holding the customer's cash with no terminal state for the POS to clear.
    'zero (wait forever)' => 0,
    'negative' => -1,
    'below the 30s floor' => 29,
    'above the 86400s ceiling' => 86401,
    'not an integer' => 'soon',
]);

it('accepts the exact bounds', function (int $value) {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Bound Changer '.$value,
        'type' => 'coin_changer',
        'metadata' => ['host' => '192.168.0.10', 'deposit_timeout_seconds' => $value],
    ])->assertCreated();
})->with([30, 86400]);

it('does NOT offer a deposit timeout on a payment_terminal — it is a 釣銭機 setting', function () {
    // The rule is scoped to coin_changer, so the terminal path must not start
    // validating (or advertising) a field its hardware has no concept of.
    expect(PeripheralDeviceService::metadataRulesFor('payment_terminal'))
        ->not->toHaveKey('metadata.deposit_timeout_seconds')
        ->and(PeripheralDeviceService::metadataRulesFor('coin_changer'))
        ->toHaveKey('metadata.deposit_timeout_seconds');
});

it('rejects an out-of-range metadata.port', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Bad Port Terminal',
        'type' => 'payment_terminal',
        'metadata' => ['host' => '192.168.0.77', 'port' => 70000],
    ])->assertUnprocessable()->assertJsonValidationErrors(['metadata.port']);
});

it('rejects an unknown device type', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Mystery Machine',
        'type' => 'teleporter',
    ])->assertUnprocessable()->assertJsonValidationErrors(['type']);
});

it('does not require metadata.host for a non-LAN device (kiosk)', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Plain Kiosk',
        'type' => 'kiosk',
    ])->assertCreated();
});

it('blocks a metadata edit that drops the host, but allows toggling is_active alone', function () {
    $device = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Editable P400',
        'type' => 'payment_terminal',
        'metadata' => ['host' => '192.168.0.77', 'port' => 8888],
    ]);

    // Editing metadata without a host is rejected.
    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", ['metadata' => ['port' => 9000]])
        ->assertUnprocessable()->assertJsonValidationErrors(['metadata.host']);

    // A partial update that leaves metadata untouched is allowed.
    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", ['is_active' => false])
        ->assertOk();
    expect($device->fresh()->is_active)->toBeFalse();

    // A complete metadata edit (host present) is allowed and persists.
    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", ['metadata' => ['host' => '10.0.0.5', 'port' => 8888]])
        ->assertOk();
    expect($device->fresh()->metadata['host'])->toBe('10.0.0.5');
});

it('prevents cross-shop actions on a peripheral', function () {
    $otherDevice = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'name' => 'Other POS Device',
        'type' => 'pos',
    ]);

    $this->actingAs($this->user)->getJson("{$this->base}/{$otherDevice->id}")
        ->assertNotFound();

    $this->actingAs($this->user)->putJson("{$this->base}/{$otherDevice->id}", [
        'name' => 'Edited',
        'type' => 'pos',
    ])->assertNotFound();

    $otherDevice->delete();
    $this->actingAs($this->user)->postJson("{$this->base}/{$otherDevice->id}/restore")
        ->assertNotFound();
});
