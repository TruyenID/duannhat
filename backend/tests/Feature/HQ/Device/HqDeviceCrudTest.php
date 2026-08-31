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
        'slug' => 'acme-devices',
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
// A — index
// =============================================================================

it('returns 401 on index when unauthenticated', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('returns paginated devices for the brand', function () {
    Device::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->user)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('caps per_page at 100', function () {
    Device::factory()->count(5)->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}?per_page=200");

    $response->assertOk();
    expect($response->json('meta.per_page'))->toBe(100);
});

it('filters by status', function () {
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'status' => 'active']);
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'status' => 'revoked']);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}?status=active");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('status')->unique()->all())->toBe(['active']);
});

it('filters by type', function () {
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'type' => 'tms']);
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'type' => 'pos']);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}?type=tms");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('type')->unique()->all())->toBe(['tms']);
});

it('filters by branch_id', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $otherBranch->id]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}?branch_id={$this->branch->id}");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('cross-org isolation: brand_id from another org returns empty list', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);

    Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}?branch_id={$otherBranch->id}");

    $response->assertOk()->assertJsonCount(0, 'data');
});

it('filters by search matching name', function () {
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'name' => 'Alpha Terminal']);
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'name' => 'Beta Register']);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}?search=Alpha");

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.name'))->toBe('Alpha Terminal');
});

it('excludes soft-deleted devices by default', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $device->delete();

    $response = $this->actingAs($this->user)->getJson($this->base);

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($device->id);
});

it('includes soft-deleted devices when with_trashed=true', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $device->delete();

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}?with_trashed=true");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($device->id);
});

// =============================================================================
// B — store
// =============================================================================

it('returns 401 on store when unauthenticated', function () {
    $this->postJson($this->base, [])->assertUnauthorized();
});

it('creates a device and returns 201', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'New TMS Device',
        'type' => 'tms',
        'branch_id' => $this->branch->id,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'name', 'type', 'status']]);
});

it('creates device with status pending_activation', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Pending Device',
        'type' => 'tms',
        'branch_id' => $this->branch->id,
    ])->assertCreated();

    $this->assertDatabaseHas('devices', [
        'name' => 'Pending Device',
        'status' => DeviceStatusEnum::PendingActivation->value,
    ]);
});

it('generates a 6-character uppercase pairing code on create', function () {
    $response = $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Code Device',
        'type' => 'tms',
        'branch_id' => $this->branch->id,
    ])->assertCreated();

    $pairingCode = $response->json('data.pairing_code');
    expect(strlen($pairingCode))->toBe(6)
        ->and($pairingCode)->toMatch('/^[A-Z0-9]{6}$/');
});

it('rejects missing name', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'type' => 'tms',
        'branch_id' => $this->branch->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('rejects name over 255 characters', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => str_repeat('A', 256),
        'type' => 'tms',
        'branch_id' => $this->branch->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('rejects invalid device type', function () {
    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Bad Type',
        'type' => 'fax-machine',
        'branch_id' => $this->branch->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['type']);
});

it('rejects duplicate name within same branch', function () {
    Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'name' => 'Taken Name',
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Taken Name',
        'type' => 'tms',
        'branch_id' => $this->branch->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('rejects a branch_id belonging to another organization (#845)', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $foreignBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Cross-Tenant Device',
        'type' => 'tms',
        'branch_id' => $foreignBranch->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);

    $this->assertDatabaseMissing('devices', ['name' => 'Cross-Tenant Device']);
});

it('allows duplicate name in different branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
        'name' => 'Shared Name',
    ]);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Shared Name',
        'type' => 'tms',
        'branch_id' => $this->branch->id,
    ])->assertCreated();
});

it('forces organization_id from context, ignoring request body', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);

    $this->actingAs($this->user)->postJson($this->base, [
        'name' => 'Org Check',
        'type' => 'tms',
        'branch_id' => $this->branch->id,
        'organization_id' => $otherOrgId,
    ])->assertCreated();

    $this->assertDatabaseHas('devices', [
        'name' => 'Org Check',
        'organization_id' => $this->orgId,
    ]);
});

// =============================================================================
// C — show
// =============================================================================

it('returns device on show', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $device->id);
});

it('returns 404 for non-existent device on show', function () {
    $this->actingAs($this->user)
        ->getJson("{$this->base}/".Str::uuid())
        ->assertNotFound();
});

it('returns 404 for soft-deleted device on show', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $device->delete();

    $this->actingAs($this->user)
        ->getJson("{$this->base}/{$device->id}")
        ->assertNotFound();
});

// =============================================================================
// D — update
// =============================================================================

it('updates device name', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", [
            'name' => 'Updated Name',
            'type' => $device->type,
        ])->assertOk();

    expect($device->fresh()->name)->toBe('Updated Name');
});

it('allows updating name to same value (unique ignore self)', function () {
    $device = Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'name' => 'Same Name',
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", [
            'name' => 'Same Name',
            'type' => $device->type,
        ])->assertOk();
});

it('returns 403 when updating device from different org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $otherDevice = Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$otherDevice->id}", ['name' => 'Hacked', 'type' => 'tms'])
        ->assertForbidden();
});

// =============================================================================
// E — destroy
// =============================================================================

it('soft-deletes a device and returns 204', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$device->id}")
        ->assertNoContent();

    expect(Device::withTrashed()->find($device->id)->deleted_at)->not->toBeNull();
});

it('returns 404 when deleting an already soft-deleted device', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $device->delete();

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$device->id}")
        ->assertNotFound();
});

it('returns 403 when deleting a device from another org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $otherDevice = Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/{$otherDevice->id}")
        ->assertForbidden();
});

// =============================================================================
// F — restore
// =============================================================================

it('restores a soft-deleted device', function () {
    $device = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $device->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$device->id}/restore")
        ->assertOk();

    expect($device->fresh()->deleted_at)->toBeNull();
});

it('returns 404 when restoring device from another org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $otherDevice = Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id]);
    $otherDevice->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->base}/{$otherDevice->id}/restore")
        ->assertNotFound();
});

// =============================================================================
// I — lookup
// =============================================================================

it('lookup returns only active devices for the org', function () {
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'status' => 'active']);
    Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id, 'status' => 'revoked']);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}/lookup");

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('lookup does not include devices from other orgs', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id, 'status' => 'active']);

    $response = $this->actingAs($this->user)->getJson("{$this->base}/lookup");

    $response->assertOk()->assertJsonCount(0, 'data');
});

// =============================================================================
// J — bulk-delete
// =============================================================================

it('bulk-deletes multiple devices', function () {
    $d1 = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $d2 = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);
    $d3 = Device::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/bulk-delete", ['ids' => [$d1->id, $d2->id, $d3->id]])
        ->assertOk();

    expect(Device::withTrashed()->find($d1->id)->deleted_at)->not->toBeNull()
        ->and(Device::withTrashed()->find($d2->id)->deleted_at)->not->toBeNull()
        ->and(Device::withTrashed()->find($d3->id)->deleted_at)->not->toBeNull();
});

it('bulk-delete silently ignores devices from other orgs', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create(['console_organization_id' => $otherOrgId, 'is_active' => true]);
    $otherDevice = Device::factory()->create(['organization_id' => $otherOrgId, 'branch_id' => $otherBranch->id]);

    $this->actingAs($this->user)
        ->postJson("{$this->base}/bulk-delete", ['ids' => [$otherDevice->id]])
        ->assertOk();

    expect($otherDevice->fresh()->deleted_at)->toBeNull();
});
