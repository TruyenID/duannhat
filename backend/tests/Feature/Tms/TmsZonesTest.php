<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
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

    $this->deviceToken = Str::random(64);

    $this->device = Device::factory()->create([
        'type' => 'tms',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('returns only active zones for the device branch', function () {
    Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'Zone A',
    ]);
    Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
        'name' => 'Zone B',
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/zones');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

it('excludes inactive zones', function () {
    Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => false,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/zones');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('excludes zones from other branches', function () {
    Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    Zone::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/zones');

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('returns empty data array when branch has no active zones', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/zones');

    $response->assertOk()->assertJson(['data' => []]);
});

it('returns zones ordered by name ascending', function () {
    Zone::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Zeta']);
    Zone::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Alpha']);
    Zone::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId, 'is_active' => true, 'name' => 'Mango']);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/zones');

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Alpha', 'Mango', 'Zeta']);
});

it('response shape includes id, name, branch_id', function () {
    Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/zones')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'branch_id']]]);
});

it('returns 401 without auth', function () {
    $this->getJson('/api/v1/tms/zones')->assertUnauthorized();
});
