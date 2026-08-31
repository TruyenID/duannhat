<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
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
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->deviceToken = Str::random(32);

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
        'call_requested_at' => now(),
    ]);
});

it('clears the call_requested_at timestamp and returns 204', function () {
    expect($this->table->fresh()->call_requested_at)->not->toBeNull();

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->deleteJson("/api/v1/tms/tables/{$this->table->id}/call")
        ->assertNoContent();

    expect($this->table->fresh()->call_requested_at)->toBeNull();
});

it('is idempotent — second call on an already-cleared table still returns 204', function () {
    $this->table->update(['call_requested_at' => null]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->deleteJson("/api/v1/tms/tables/{$this->table->id}/call")
        ->assertNoContent();

    expect($this->table->fresh()->call_requested_at)->toBeNull();
});

it('returns 404 for a table in another branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $otherZone = Zone::factory()->create([
        'branch_id' => $otherBranch->id,
        'organization_id' => $this->orgId,
    ]);
    $otherTable = Table::factory()->create([
        'branch_id' => $otherBranch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $otherZone->id,
        'is_active' => true,
        'call_requested_at' => now(),
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->deleteJson("/api/v1/tms/tables/{$otherTable->id}/call")
        ->assertNotFound();

    expect($otherTable->fresh()->call_requested_at)->not->toBeNull();
});

it('returns 401 when no device token is sent', function () {
    $this->deleteJson("/api/v1/tms/tables/{$this->table->id}/call")
        ->assertUnauthorized();
});

it('returns 401 when device token is wrong', function () {
    $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
        ->deleteJson("/api/v1/tms/tables/{$this->table->id}/call")
        ->assertUnauthorized();
});

it('rejects devices of a non-tms type', function () {
    $posToken = Str::random(32);
    Device::factory()->create([
        'type' => 'pos',
        'status' => 'active',
        'device_token' => $posToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$posToken}"])
        ->deleteJson("/api/v1/tms/tables/{$this->table->id}/call")
        ->assertForbidden();
});
