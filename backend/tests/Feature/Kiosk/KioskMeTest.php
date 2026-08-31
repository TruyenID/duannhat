<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    $this->organization = Organization::factory()->create([
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

    $this->deviceToken = Str::random(32);

    $this->device = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('returns organization name + branch info on /kiosk/me', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/me')
        ->assertOk();

    $response->assertJsonPath('data.organization.id', $this->organization->id);
    $response->assertJsonPath('data.organization.name', $this->organization->name);
    $response->assertJsonPath('data.branch.id', $this->branch->id);
    $response->assertJsonPath('data.branch.name', $this->branch->name);
    $response->assertJsonPath('data.branch_id', $this->branch->id);
    $response->assertJsonPath('data.organization_id', $this->organization->id);
});
