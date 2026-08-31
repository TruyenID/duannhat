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

    $this->base = "/api/v1/shops/{$this->shop->slug}/devices";
});

// =============================================================================
// A — index
// =============================================================================

it('returns 401 on index when unauthenticated', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('only returns devices belonging to the shop in the URL', function () {
    Device::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    $response = $this->actingAs($this->user)->getJson($this->base);

    $response->assertOk()->assertJsonCount(2, 'data');
});

it('cross-shop isolation: devices of other shop in same brand do not appear', function () {
    $otherBase = "/api/v1/shops/{$this->otherShop->slug}/devices";

    Device::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $response = $this->actingAs($this->user)->getJson($otherBase);

    $response->assertOk()->assertJsonCount(0, 'data');
});

it('filters by status scoped to the shop', function () {
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'status' => 'active']);
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'status' => 'revoked']);

    $response = $this->actingAs($this->user)->getJson("{$this->base}?status=active");

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('excludes soft-deleted devices by default', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    $device->delete();

    $response = $this->actingAs($this->user)->getJson($this->base);
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($device->id);
});

it('includes soft-deleted devices with with_trashed=true', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    $device->delete();

    $response = $this->actingAs($this->user)->getJson("{$this->base}?with_trashed=true");
    expect(collect($response->json('data'))->pluck('id'))->toContain($device->id);
});

// =============================================================================
// B — store
// =============================================================================

it('returns 401 on store when unauthenticated', function () {
    $this->postJson($this->base, [])->assertUnauthorized();
});

it('creates device under the shop in URL regardless of branch_id in body', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Shop TMS',
        'type' => 'tms',
        'branch_id' => $this->otherShop->id,
    ]);

    $response->assertCreated();

    $device = Device::latest()->first();
    expect($device->branch_id)->toBe($this->shop->id);
});

it('rejects duplicate name within the same shop', function () {
    Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Taken',
    ]);

    // Issue #133 fixed by PR #461 (DeviceService friendly duplicate-name
    // error) — the duplicate is now a 422 validation error, not a DB 500.
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Taken',
        'type' => 'tms',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);
    $this->assertDatabaseCount('devices', 1);
});

it('allows same name in different shop', function () {
    Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'name' => 'Shared Name',
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Shared Name',
        'type' => 'tms',
    ])->assertCreated();
});

it('creates device with status pending_activation', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'New Shop Device',
        'type' => 'tms',
    ])->assertCreated();

    $this->assertDatabaseHas('devices', [
        'name' => 'New Shop Device',
        'status' => DeviceStatusEnum::PendingActivation->value,
    ]);
});

// =============================================================================
// C — show / update / destroy
// =============================================================================

it('returns a device belonging to the shop', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $device->id);
});

it('returns 404 when showing a device from another shop', function () {
    $otherDevice = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$otherDevice->id}")
        ->assertNotFound();
});

it('updates a device in the shop', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", [
            'name' => 'Updated Shop Device',
            'type' => $device->type,
        ])->assertOk();

    expect($device->fresh()->name)->toBe('Updated Shop Device');
});

it('returns 404 when updating a device from another shop', function () {
    $otherDevice = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$otherDevice->id}", ['name' => 'Hacked', 'type' => 'tms'])
        ->assertNotFound();
});

it('soft-deletes a device and returns 204', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$device->id}")
        ->assertNoContent();

    expect(Device::withTrashed()->find($device->id)->deleted_at)->not->toBeNull();
});

it('returns 404 when deleting a device from another shop', function () {
    $otherDevice = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$otherDevice->id}")
        ->assertNotFound();
});

// =============================================================================
// D — restore
// =============================================================================

it('restores a soft-deleted device in the shop', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    $device->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/restore")
        ->assertOk();

    expect($device->fresh()->deleted_at)->toBeNull();
});

it('returns 404 when restoring a soft-deleted device from another shop', function () {
    $otherDevice = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
    ]);
    $otherDevice->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$otherDevice->id}/restore")
        ->assertNotFound();
});

// =============================================================================
// E — regeneratePairingCode & revoke (shop isolation)
// =============================================================================

it('regenerates pairing code for device in shop', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'status' => 'pending_activation',
        'pairing_code' => 'OLD001',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/regenerate-pairing")
        ->assertOk();

    expect($device->fresh()->pairing_code)->not->toBe('OLD001');
});

it('returns 404 when regenerating pairing code for device in other shop', function () {
    $otherDevice = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'status' => 'pending_activation',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$otherDevice->id}/regenerate-pairing")
        ->assertNotFound();
});

it('revokes device in shop', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'status' => 'active',
        'device_token' => Str::random(64),
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/revoke")
        ->assertOk();

    expect($device->fresh()->status)->toBe(DeviceStatusEnum::Revoked);
});

it('returns 404 when revoking device from other shop', function () {
    $otherDevice = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->otherShop->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$otherDevice->id}/revoke")
        ->assertNotFound();
});

// =============================================================================
// F — Cross-tenant isolation
// =============================================================================

it('cross-tenant: user from org A cannot access shop of org B', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'slug' => 'cross-tenant-shop',
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/shops/{$otherShop->slug}/devices")
        ->assertStatus(403);
});
