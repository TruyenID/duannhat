<?php

use App\Models\Branch;
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

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);

    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('allows workstation device to GET /tms/me', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk();
});

it('allows workstation device to GET /tms/zones', function () {
    Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'Khu A',
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/tms/zones')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('allows workstation device to GET /tms/tables', function () {
    $zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $zone->id,
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/tms/tables')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('forbids workstation device from TMS-only write endpoint', function () {
    $zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $table = Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $zone->id,
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson("/api/v1/tms/tables/{$table->id}/status", ['status' => 'occupied'])
        ->assertForbidden();
});
