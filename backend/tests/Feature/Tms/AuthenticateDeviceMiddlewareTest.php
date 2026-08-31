<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->deviceToken = Str::random(64);

    $this->device = Device::factory()->create([
        'type' => 'tms',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
        'status' => 'free',
    ]);
});

// =============================================================================
// Missing / bad token
// =============================================================================

it('returns 401 when no Authorization header is sent', function () {
    $this->getJson('/api/v1/tms/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Device token required.']);
});

it('returns 401 when bearer token does not match any device', function () {
    $this->withHeaders(['Authorization' => 'Bearer invalid-token-xyz'])
        ->getJson('/api/v1/tms/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid device token.']);
});

// =============================================================================
// Inactive / revoked device
// =============================================================================

it('returns 401 when device is pending_activation', function () {
    $pendingToken = Str::random(64);
    Device::factory()->create([
        'type' => 'tms',
        'status' => 'pending_activation',
        'device_token' => $pendingToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$pendingToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Device is not active.']);
});

it('returns 401 when device is revoked', function () {
    $revokedToken = Str::random(64);
    Device::factory()->create([
        'type' => 'tms',
        'status' => 'revoked',
        'device_token' => $revokedToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$revokedToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Device is not active.']);
});

it('returns 401 when device is inactive', function () {
    $inactiveToken = Str::random(64);
    Device::factory()->create([
        'type' => 'tms',
        'status' => 'inactive',
        'device_token' => $inactiveToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$inactiveToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Device is not active.']);
});

// =============================================================================
// Device type restriction
// =============================================================================

it('returns 403 when POS device calls a TMS-only write endpoint', function () {
    $posToken = Str::random(64);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $posToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$posToken}"])
        ->postJson("/api/v1/tms/tables/{$this->table->id}/status", ['status' => 'occupied'])
        ->assertForbidden()
        ->assertJson([
            'message' => 'Device type not allowed for this endpoint.',
            'code' => 'DEVICE_TYPE_NOT_ALLOWED',
        ]);
});

it('allows kiosk device to access TMS read endpoints', function () {
    $kioskToken = Str::random(64);
    Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $kioskToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk();
});

it('returns 403 when kiosk device calls a TMS-only write endpoint', function () {
    $kioskToken = Str::random(64);
    Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $kioskToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
        ->postJson("/api/v1/tms/tables/{$this->table->id}/status", ['status' => 'occupied'])
        ->assertForbidden();
});

// =============================================================================
// Heartbeat side-effect — throttled (#2714, parent #2711)
// =============================================================================
//
// The old contract here was "updates last_seen_at on EVERY authenticated
// request". That is exactly the write being removed: an idle workstation polls
// ~144 times a minute, so two idle shops alone were ~5 `UPDATE devices` a
// second. The stamp is now throttled to
// `DeviceService::LAST_SEEN_THROTTLE_SECONDS`.
//
// Every case below counts the real `UPDATE ... devices` statements the request
// emits, not just the resulting value: with the clock frozen, a second request
// writing the same timestamp is invisible in the column but very visible in the
// DB, and the whole point of the issue is the DB.

/**
 * Count `UPDATE ... devices` statements emitted while $work runs.
 */
function deviceWritesDuring(Closure $work): int
{
    $writes = 0;

    DB::listen(function ($query) use (&$writes) {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'update') && str_contains($sql, 'devices')) {
            $writes++;
        }
    });

    $work();

    // The listener stays registered for the rest of the test; each case creates
    // its own counter and reads it immediately, so a later listener adding to an
    // earlier counter never affects an assertion that already ran.
    return $writes;
}

it('stamps last_seen_at on the first request, then stays quiet for the rest of the throttle window', function () {
    Carbon::setTestNow('2026-08-13 04:15:00');

    $this->device->updateQuietly(['last_seen_at' => now()->subMinutes(5)]);

    $call = fn () => $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk();

    $firstWrites = deviceWritesDuring($call);
    $stamped = $this->device->fresh()->last_seen_at;

    // Still the same frozen instant — well inside the 60s window.
    Carbon::setTestNow('2026-08-13 04:15:59');
    $secondWrites = deviceWritesDuring($call);

    expect($firstWrites)->toBe(1, 'a stale device must be stamped')
        ->and($stamped->toDateTimeString())->toBe('2026-08-13 04:15:00')
        ->and($secondWrites)->toBe(0, 'second request inside the window must not touch the row')
        ->and($this->device->fresh()->last_seen_at->toDateTimeString())->toBe('2026-08-13 04:15:00');
});

it('stamps again once the throttle window has passed', function () {
    Carbon::setTestNow('2026-08-13 04:15:00');
    $this->device->updateQuietly(['last_seen_at' => now()]);

    $call = fn () => $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk();

    Carbon::setTestNow('2026-08-13 04:16:01');
    $writes = deviceWritesDuring($call);

    expect($writes)->toBe(1, 'past the window the stamp must be refreshed')
        ->and($this->device->fresh()->last_seen_at->toDateTimeString())->toBe('2026-08-13 04:16:01');
});

it('stamps a device that has never been seen', function () {
    Carbon::setTestNow('2026-08-13 04:15:00');
    $this->device->updateQuietly(['last_seen_at' => null]);

    $writes = deviceWritesDuring(fn () => $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk());

    expect($writes)->toBe(1, 'absence of a stamp is not freshness')
        ->and($this->device->fresh()->last_seen_at->toDateTimeString())->toBe('2026-08-13 04:15:00');
});

it('still records a changed X-App-Version inside the throttle window', function () {
    Carbon::setTestNow('2026-08-13 04:15:00');
    $this->device->updateQuietly([
        'last_seen_at' => now(),
        'device_info' => ['app_version' => '0.3.0', 'app_version_seen_at' => now()->toISOString()],
    ]);

    $callWith = fn (string $version) => $this->withHeaders([
        'Authorization' => "Bearer {$this->deviceToken}",
        'X-App-Version' => $version,
    ])->getJson('/api/v1/tms/me')->assertOk();

    Carbon::setTestNow('2026-08-13 04:15:10');
    $writesOnUpgrade = deviceWritesDuring(fn () => $callWith('0.3.1'));
    $afterUpgrade = $this->device->fresh();

    // Same version again, still inside the window: nothing new to record.
    Carbon::setTestNow('2026-08-13 04:15:20');
    $writesOnRepeat = deviceWritesDuring(fn () => $callWith('0.3.1'));

    expect($writesOnUpgrade)->toBe(1, 'a version change must reach the row even while throttled')
        ->and($afterUpgrade->device_info['app_version'])->toBe('0.3.1')
        // The row already claims the device is live, so it carries the stamp:
        // app_version_seen_at must never be newer than last_seen_at.
        ->and($afterUpgrade->last_seen_at->toDateTimeString())->toBe('2026-08-13 04:15:10')
        ->and($writesOnRepeat)->toBe(0, 'an unchanged version is not a reason to write');
});

// =============================================================================
// Token revoked mid-session
// =============================================================================

it('returns 401 on subsequent requests after token is revoked', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk();

    $this->device->update(['device_token' => null, 'status' => 'revoked']);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertUnauthorized();
});
