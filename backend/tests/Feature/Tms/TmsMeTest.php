<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
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

    $this->deviceToken = Str::random(64);

    $this->device = Device::factory()->create([
        'type' => 'tms',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('returns device info with branch on GET /tms/me', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/me')
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'type', 'status', 'branch' => ['id', 'name']]]);
});

it('returns the correct device id in /tms/me', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/tms/me');

    $response->assertOk();
    expect($response->json('data.id'))->toBe($this->device->id);
});

it('returns 401 without auth token on /tms/me', function () {
    $this->getJson('/api/v1/tms/me')->assertUnauthorized();
});
