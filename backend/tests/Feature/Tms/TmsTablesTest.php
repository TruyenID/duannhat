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
        'is_active' => true,
    ]);

    $this->otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->deviceToken = Str::random(64);

    $this->device = Device::factory()->create([
        'type' => 'tms',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('returns active tables for the device branch', function () {
    Table::factory()->count(3)->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables');

    $response->assertOk()->assertJsonCount(3, 'data');
});

it('excludes inactive tables', function () {
    Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
    ]);
    Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => false,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('excludes tables from other branches', function () {
    $otherZone = Zone::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
    ]);
    Table::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $otherZone->id,
        'is_active' => true,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('excludes soft-deleted tables', function () {
    $table = Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
    ]);
    $table->delete();

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables');

    $response->assertOk()->assertJsonCount(0, 'data');
});

it('returns tables ordered by code ascending', function () {
    Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId, 'zone_id' => $this->zone->id, 'is_active' => true, 'code' => 'T-03']);
    Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId, 'zone_id' => $this->zone->id, 'is_active' => true, 'code' => 'T-01']);
    Table::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId, 'zone_id' => $this->zone->id, 'is_active' => true, 'code' => 'T-02']);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables');

    $codes = collect($response->json('data'))->pluck('code')->all();
    expect($codes)->toBe(['T-01', 'T-02', 'T-03']);
});

it('response shape includes required fields and nested zone', function () {
    Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'seat_count', 'status', 'paid_at', 'call_requested_at', 'zone_id', 'zone', 'qr_token']]]);
});

it('exposes qr_token so workstation can serve /customer/tables/{qrToken} from its local replica', function () {
    Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
        'qr_token' => 'qr-abc-123',
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables');

    $response->assertOk()->assertJsonPath('data.0.qr_token', 'qr-abc-123');
});

it('returns empty data when branch has no active tables', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/tables')
        ->assertOk()
        ->assertJson(['data' => []]);
});

it('returns 401 without auth', function () {
    $this->getJson('/api/v1/tms/tables')->assertUnauthorized();
});
