<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\DeviceSigningKey;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Device\DeviceSigningKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * #1093 — Ed25519 device signing keys (offline-order evidence, step 1/5).
 *
 * These keys are what the offline-replay verifier will trust MONEY on, so the
 * tests are strict about the three lifecycle rules: issuance binds the right
 * tenant, rotation keeps a grace window, revocation kills a key instantly and
 * forever. A wrong answer in any of them becomes a wrong bill later.
 */

/** A valid 32-byte Ed25519 public key in base64 (44 chars). */
function ed25519PublicKey(string $seedByte = 'A'): string
{
    return base64_encode(str_repeat($seedByte, 32));
}

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->device = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'pending_activation',
        'pairing_code' => 'KEY123',
        'pairing_expires_at' => now()->addMinutes(10),
        'device_token' => null,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->keys = app(DeviceSigningKeyService::class);
});

// =========================================================================
//  Issuance at pair time
// =========================================================================

it('pairs WITH a public key: Cloud stores the key on the right org/device and returns key_id + expiry', function () {
    $publicKey = ed25519PublicKey();

    $response = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'KEY123',
        'public_key' => $publicKey,
    ])->assertOk();

    $keyId = $response->json('signing_key.key_id');
    expect($keyId)->not->toBeNull()
        ->and($response->json('signing_key.expires_at'))->not->toBeNull()
        ->and($response->json('device_token'))->not->toBeNull();

    $key = DeviceSigningKey::findOrFail($keyId);
    expect($key->device_id)->toBe($this->device->id)
        ->and($key->organization_id)->toBe($this->orgId)
        ->and($key->public_key)->toBe($publicKey)
        ->and($key->revoked_at)->toBeNull()
        // 180-day lifetime from issuance.
        ->and($key->expires_at->diffInDays($key->issued_at))->toBe(-180.0);
});

it('pairs WITHOUT a public key exactly like before — no key row, no signing_key in the response (old builds)', function () {
    $response = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'KEY123',
    ])->assertOk();

    expect($response->json())->not->toHaveKey('signing_key')
        ->and(DeviceSigningKey::count())->toBe(0);
});

it('rejects a malformed key at the door — not 32 bytes, not base64, or non-canonical encoding', function (string $bad) {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'KEY123',
        'public_key' => $bad,
    ])->assertStatus(422);

    expect(DeviceSigningKey::count())->toBe(0);
})->with([
    'wrong length (31 bytes)' => [base64_encode(str_repeat('A', 31)).'x'],
    'not base64 at all' => [str_repeat('!', 44)],
    'non-canonical base64' => [substr(base64_encode(str_repeat('A', 32)), 0, 43).'!'],
]);

// =========================================================================
//  Tenant isolation
// =========================================================================

it('scopes keys to their own tenant: another org admin cannot list or revoke them', function () {
    $key = $this->keys->issue($this->device, ed25519PublicKey());

    // A manager in a DIFFERENT org.
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $outsider = User::factory()->create(['console_organization_id' => $foreignOrgId]);
    $outsider->assignRole($role, $foreignOrgId);
    grantOrgAccess($outsider, $foreignOrgId);

    $this->actingAs($outsider)
        ->getJson("/api/v1/hq/{$foreignBrand->slug}/devices/{$this->device->id}/signing-keys")
        ->assertStatus(403);

    $this->actingAs($outsider)
        ->postJson("/api/v1/hq/{$foreignBrand->slug}/devices/{$this->device->id}/signing-keys/{$key->id}/revoke", [
            'reason' => 'not yours to revoke',
        ])
        ->assertStatus(403);

    expect($key->fresh()->revoked_at)->toBeNull();
});

// =========================================================================
//  Rotation — grace window
// =========================================================================

it('rotation keeps BOTH keys valid: offline orders signed with the old key still verify during the grace window', function () {
    $old = $this->keys->issue($this->device, ed25519PublicKey('A'));
    $new = $this->keys->issue($this->device, ed25519PublicKey('B'));

    $validIds = $this->keys->validKeysFor($this->device)->pluck('id')->all();

    expect($validIds)->toContain($old->id)
        ->and($validIds)->toContain($new->id)
        ->and($old->fresh()->revoked_at)->toBeNull();
});

it('an EXPIRED key drops out of the valid set — expiry is a hard ceiling, rotation never extends it', function () {
    $old = $this->keys->issue($this->device, ed25519PublicKey('A'));
    $old->update(['expires_at' => now()->subSecond()]);
    $new = $this->keys->issue($this->device, ed25519PublicKey('B'));

    $validIds = $this->keys->validKeysFor($this->device)->pluck('id')->all();

    expect($validIds)->not->toContain($old->id)
        ->and($validIds)->toContain($new->id);
});

it('wasValidAt answers per-instant: valid inside [issued_at, expires_at), invalid outside', function () {
    $key = $this->keys->issue($this->device, ed25519PublicKey());
    $issued = CarbonImmutable::parse($key->issued_at);

    expect($this->keys->wasValidAt($key, $issued))->toBeTrue()
        ->and($this->keys->wasValidAt($key, $issued->addDays(179)))->toBeTrue()
        ->and($this->keys->wasValidAt($key, $issued->subSecond()))->toBeFalse()
        ->and($this->keys->wasValidAt($key, $issued->addDays(180)))->toBeFalse();
});

// =========================================================================
//  Revocation — immediate, total, irreversible
// =========================================================================

it('a revoked key fails wasValidAt for EVERY instant — even timestamps inside its original window (BR-DSK02)', function () {
    $key = $this->keys->issue($this->device, ed25519PublicKey());
    $insideWindow = CarbonImmutable::parse($key->issued_at)->addDay();

    expect($this->keys->wasValidAt($key, $insideWindow))->toBeTrue();

    $this->keys->revoke($key, 'compromised tablet');

    // A compromised key's own timestamps can't be trusted to date a
    // signature — so revocation rejects the past too.
    expect($this->keys->wasValidAt($key->fresh(), $insideWindow))->toBeFalse()
        ->and($this->keys->validKeysFor($this->device)->count())->toBe(0);
});

it('device self-revoke (unpair) revokes every key of the device (BR-DSK03)', function () {
    // Pair first so the device holds a token.
    $token = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'KEY123',
        'public_key' => ed25519PublicKey('A'),
    ])->assertOk()->json('device_token');
    $this->keys->issue($this->device->fresh(), ed25519PublicKey('B'));
    expect($this->keys->validKeysFor($this->device)->count())->toBe(2);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/workstation/self-revoke')
        ->assertOk();

    expect($this->keys->validKeysFor($this->device)->count())->toBe(0)
        ->and(DeviceSigningKey::whereNull('revoked_at')->count())->toBe(0)
        ->and(DeviceSigningKey::first()->revoked_reason)->toBe('device_self_revoked');
});

it('HQ admin revoking the DEVICE revokes its keys too — a dead device cannot vouch for offline money', function () {
    $this->keys->issue($this->device, ed25519PublicKey());

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $manager->assignRole($role, $this->orgId);
    grantOrgAccess($manager, $this->orgId);

    $this->actingAs($manager)
        ->postJson("/api/v1/hq/{$this->brand->slug}/devices/{$this->device->id}/revoke")
        ->assertOk();

    expect($this->keys->validKeysFor($this->device)->count())->toBe(0)
        ->and(DeviceSigningKey::first()->revoked_reason)->toBe('device_revoked');
});

it('HQ admin can revoke ONE key with a reason while the rotated sibling stays valid', function () {
    $compromised = $this->keys->issue($this->device, ed25519PublicKey('A'));
    $healthy = $this->keys->issue($this->device, ed25519PublicKey('B'));

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $manager->assignRole($role, $this->orgId);
    grantOrgAccess($manager, $this->orgId);

    // Reason is REQUIRED — an unexplained revocation is unauditable.
    $this->actingAs($manager)
        ->postJson("/api/v1/hq/{$this->brand->slug}/devices/{$this->device->id}/signing-keys/{$compromised->id}/revoke")
        ->assertStatus(422);

    $this->actingAs($manager)
        ->postJson("/api/v1/hq/{$this->brand->slug}/devices/{$this->device->id}/signing-keys/{$compromised->id}/revoke", [
            'reason' => 'key file found in a public backup',
        ])
        ->assertOk()
        ->assertJsonPath('revoked_reason', 'key file found in a public backup');

    $validIds = $this->keys->validKeysFor($this->device)->pluck('id')->all();
    expect($validIds)->not->toContain($compromised->id)
        ->and($validIds)->toContain($healthy->id);

    // Idempotent: a second revoke keeps the ORIGINAL reason + timestamp.
    $firstRevokedAt = $compromised->fresh()->revoked_at;
    $this->actingAs($manager)
        ->postJson("/api/v1/hq/{$this->brand->slug}/devices/{$this->device->id}/signing-keys/{$compromised->id}/revoke", [
            'reason' => 'different reason later',
        ])
        ->assertOk();
    expect($compromised->fresh()->revoked_reason)->toBe('key file found in a public backup')
        ->and($compromised->fresh()->revoked_at->equalTo($firstRevokedAt))->toBeTrue();
});

it('HQ key listing shows validity at a glance — valid, revoked, and expired each labelled correctly', function () {
    $valid = $this->keys->issue($this->device, ed25519PublicKey('A'));
    $revoked = $this->keys->issue($this->device, ed25519PublicKey('B'));
    $this->keys->revoke($revoked, 'test');
    $expired = $this->keys->issue($this->device, ed25519PublicKey('C'));
    $expired->update(['expires_at' => now()->subDay()]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $manager->assignRole($role, $this->orgId);
    grantOrgAccess($manager, $this->orgId);

    $rows = collect($this->actingAs($manager)
        ->getJson("/api/v1/hq/{$this->brand->slug}/devices/{$this->device->id}/signing-keys")
        ->assertOk()
        ->json('data'))->keyBy('id');

    expect($rows[$valid->id]['is_valid'])->toBeTrue()
        ->and($rows[$revoked->id]['is_valid'])->toBeFalse()
        ->and($rows[$revoked->id]['revoked_reason'])->toBe('test')
        ->and($rows[$expired->id]['is_valid'])->toBeFalse();
});

// =========================================================================
//  Rotation over HTTP (device token)
// =========================================================================

it('POST /workstation/keys/rotate registers a new key under the calling device token', function () {
    $token = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'KEY123',
        'public_key' => ed25519PublicKey('A'),
    ])->assertOk()->json('device_token');

    $newKey = ed25519PublicKey('B');
    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/v1/workstation/keys/rotate', ['public_key' => $newKey])
        ->assertOk();

    $key = DeviceSigningKey::findOrFail($response->json('key_id'));
    expect($key->device_id)->toBe($this->device->id)
        ->and($key->public_key)->toBe($newKey)
        // Both keys valid — the grace window over HTTP too.
        ->and($this->keys->validKeysFor($this->device)->count())->toBe(2);
});

it('rotation without a device token is rejected — anonymous callers cannot mint keys', function () {
    $this->postJson('/api/v1/workstation/keys/rotate', ['public_key' => ed25519PublicKey()])
        ->assertStatus(401);

    expect(DeviceSigningKey::count())->toBe(0);
});
