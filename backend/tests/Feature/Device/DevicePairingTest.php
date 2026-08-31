<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Facades\Cache;
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

    $this->device = Device::factory()->create([
        'type' => 'tms',
        'status' => 'pending_activation',
        'pairing_code' => 'ABC123',
        'pairing_expires_at' => now()->addMinutes(10),
        'device_token' => null,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

// =============================================================================
// Validation
// =============================================================================

it('rejects missing pairing_code', function () {
    $this->postJson('/api/v1/devices/pair', [
        'device_info' => ['user_agent' => 'TMS/1.0'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pairing_code']);
});

it('rejects pairing_code shorter than 6 characters', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'AB123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pairing_code']);
});

it('rejects pairing_code longer than 6 characters', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'AB12345',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pairing_code']);
});

it('rejects pairing_code that is not a string', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => ['arr' => 'ay'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pairing_code']);
});

it('rejects device_info that is not an array', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'device_info' => 'not-an-array',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['device_info']);
});

it('rejects device_info.user_agent that is not a string', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'device_info' => ['user_agent' => ['array']],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['device_info.user_agent']);
});

it('rejects a valid-length code that does not exist in DB', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'XXXXXX',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pairing_code']);
});

it('allows omitting device_info', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
    ])->assertOk();
});

// =============================================================================
// Happy path
// =============================================================================

it('pairs a device with valid code and returns token + device resource', function () {
    $response = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'device_info' => ['user_agent' => 'TMS/1.0', 'ip_address' => '10.0.0.1'],
    ]);

    $response->assertOk()
        ->assertJsonStructure(['device_token', 'device' => ['id', 'name', 'status']])
        ->assertJsonPath('device.branch.id', $this->branch->id)
        ->assertJsonPath('device.branch.slug', $this->branch->slug)
        ->assertJsonPath('device.branch.locale', $this->branch->locale);
});

it('sets device_token to a 64-character string after pairing', function () {
    $this->postJson('/api/v1/devices/pair', ['pairing_code' => 'ABC123'])->assertOk();

    $device = $this->device->fresh();
    expect(strlen($device->device_token))->toBe(64);
});

it('marks device as active after pairing', function () {
    $this->postJson('/api/v1/devices/pair', ['pairing_code' => 'ABC123'])->assertOk();

    expect($this->device->fresh()->status)->toBe(DeviceStatusEnum::Active);
});

it('clears pairing_code and pairing_expires_at after pairing', function () {
    $this->postJson('/api/v1/devices/pair', ['pairing_code' => 'ABC123'])->assertOk();

    $device = $this->device->fresh();
    expect($device->pairing_code)->toBeNull()
        ->and($device->pairing_expires_at)->toBeNull();
});

it('sets paired_at and last_seen_at on successful pair', function () {
    $this->postJson('/api/v1/devices/pair', ['pairing_code' => 'ABC123'])->assertOk();

    $device = $this->device->fresh();
    expect($device->paired_at)->not->toBeNull()
        ->and($device->last_seen_at)->not->toBeNull();
});

it('persists device_info on successful pair', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'device_info' => ['user_agent' => 'TMS/1.0', 'ip_address' => '192.168.1.1', 'app_version' => '2.0'],
    ])->assertOk();

    $device = $this->device->fresh();
    expect($device->device_info['user_agent'])->toBe('TMS/1.0')
        ->and($device->device_info['ip_address'])->toBe('192.168.1.1')
        ->and($device->device_info['app_version'])->toBe('2.0');
});

it('persists empty device_info as empty array', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'device_info' => [],
    ])->assertOk();

    expect($this->device->fresh()->device_info)->toBeArray()->toBeEmpty();
});

it('does not create a new device record on pairing', function () {
    $countBefore = Device::count();

    $this->postJson('/api/v1/devices/pair', ['pairing_code' => 'ABC123'])->assertOk();

    expect(Device::count())->toBe($countBefore);
});

// =============================================================================
// Expired / used code
// =============================================================================

it('rejects an expired pairing code', function () {
    $this->device->update(['pairing_expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['pairing_code']);
});

it('cannot pair the same device twice with the original code', function () {
    // First pair — succeeds, clears pairing_code + sets device_token.
    $first = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
    ])->assertOk();

    $firstToken = $first->json('device_token');

    // Second attempt with the same code — service must reject (code was nulled).
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
    ])->assertUnprocessable()->assertJsonValidationErrors(['pairing_code']);

    // The original token must still be valid (not regenerated by failed attempt).
    expect($this->device->fresh()->device_token)->toBe($firstToken);
});

// =============================================================================
// expected_type — device-type gate (issue #935)
// =============================================================================

it('pairs when expected_type includes the device type', function () {
    $this->device->update(['type' => 'handy']);

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'expected_type' => 'handy,pos',
    ])->assertOk();
});

it('accepts a pos device for the handy allow-set', function () {
    $this->device->update(['type' => 'pos']);

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'expected_type' => 'handy,pos',
    ])->assertOk();
});

it('rejects a mismatched device type and leaves the code usable', function () {
    $this->device->update(['type' => 'kds']);

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'expected_type' => 'handy,pos',
    ])->assertUnprocessable()->assertJsonValidationErrors(['pairing_code']);

    // Mismatch must NOT consume the code or activate the device.
    $device = $this->device->fresh();
    expect($device->status)->toBe(DeviceStatusEnum::PendingActivation)
        ->and($device->pairing_code)->toBe('ABC123')
        ->and($device->device_token)->toBeNull();
});

it('names the actual device type in the rejection message', function () {
    $this->device->update(['type' => 'kds']);

    $response = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'expected_type' => 'handy,pos',
    ])->assertUnprocessable();

    expect($response->json('errors.pairing_code.0'))->toContain(DeviceTypeEnum::Kds->label());
});

it('pairs without expected_type regardless of type (legacy client)', function () {
    $this->device->update(['type' => 'kds']);

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
    ])->assertOk();
});

it('trims whitespace in the expected_type CSV', function () {
    $this->device->update(['type' => 'pos']);

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'expected_type' => ' handy , pos ',
    ])->assertOk();
});

it('rejects an unknown expected_type value on the expected_type field', function () {
    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
        'expected_type' => 'banana',
    ])->assertUnprocessable()->assertJsonValidationErrors(['expected_type']);
});

// =============================================================================
// Post-pairing heartbeat
// =============================================================================

it('allows the freshly-issued token to access GET /tms/me', function () {
    $pairResponse = $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'ABC123',
    ]);

    $token = $pairResponse->json('device_token');

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk();
});

// =============================================================================
// Throttling
// =============================================================================

it('throttles devices-pair at 5 requests per minute per IP', function () {
    // Other tests in this process share the array cache the limiter uses;
    // flush so this test owns a fresh 5-request budget.
    Cache::flush();

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/devices/pair', [
            'pairing_code' => 'XXXXXX',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/devices/pair', [
        'pairing_code' => 'XXXXXX',
    ])->assertStatus(429);
});
