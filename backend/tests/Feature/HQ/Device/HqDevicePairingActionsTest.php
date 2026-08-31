<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-pairing',
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/devices";
});

// =============================================================================
// G — regeneratePairingCode
// =============================================================================

it('regenerates pairing code for a pending_activation device', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'pending_activation',
        'pairing_code' => 'OLD123',
        'pairing_expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/regenerate-pairing");

    $response->assertOk();

    $newCode = $device->fresh()->pairing_code;
    expect($newCode)->not->toBe('OLD123')
        ->and(strlen($newCode))->toBe(6);
});

it('regenerates pairing code for an active device (force re-pair)', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'device_token' => Str::random(64),
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/regenerate-pairing")
        ->assertOk();
});

it('rejects regenerating pairing code for a revoked device', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'revoked',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/regenerate-pairing")
        ->assertUnprocessable();
});

it('regenerates with fresh expiry (≈ now + 15 min)', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'pending_activation',
    ]);

    $before = now();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/regenerate-pairing")
        ->assertOk();

    $expires = $device->fresh()->pairing_expires_at;
    expect($expires->isAfter($before->addMinutes(14)))->toBeTrue();
});

it('returns 404 when regenerating for a device from another org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $otherDevice = Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id, 'status' => 'pending_activation']);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$otherDevice->id}/regenerate-pairing")
        ->assertForbidden();
});

// =============================================================================
// H — revoke
// =============================================================================

it('revokes an active device', function () {
    $deviceToken = Str::random(64);
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'device_token' => $deviceToken,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/revoke")
        ->assertOk();

    $fresh = $device->fresh();
    expect($fresh->status)->toBe(DeviceStatusEnum::Revoked)
        ->and($fresh->device_token)->toBeNull();
});

it('revoked device token no longer works on TMS endpoints', function () {
    $deviceToken = Str::random(64);
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'device_token' => $deviceToken,
        'type' => 'tms',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/revoke")
        ->assertOk();

    $this->withHeaders(['Authorization' => "Bearer {$deviceToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertUnauthorized();
});

it('revoking an already revoked device is idempotent', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'status' => 'revoked',
        'device_token' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/revoke")
        ->assertOk();

    expect($device->fresh()->status)->toBe(DeviceStatusEnum::Revoked);
});

it('returns 404 when revoking a device from another org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $otherDevice = Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id, 'status' => 'active']);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$otherDevice->id}/revoke")
        ->assertForbidden();
});
