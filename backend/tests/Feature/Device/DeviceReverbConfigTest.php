<?php

/**
 * /api/v1/devices/reverb-config tests (plan-027 T0.2).
 *
 * Device-token version mirrors /me/reverb-config so device-paired Echo
 * clients can bootstrap Reverb connection details with their device token.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Str;

it('returns reverb config for any active device', function () {
    $orgId = (string) Str::uuid();
    $brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
        'slug' => 'rc-'.Str::random(4),
        'is_active' => true,
    ]);
    $brand->refresh();

    $branch = Branch::factory()->create([
        'console_brand_id' => $brand->console_brand_id,
        'console_organization_id' => $orgId,
    ]);

    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_kds_x',
        'branch_id' => $branch->id,
        'organization_id' => $orgId,
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kds_x')
        ->getJson('/api/v1/devices/reverb-config')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['app_key', 'cluster', 'host', 'port', 'scheme'],
        ])
        ->assertJsonPath('data.app_key', $brand->reverb_app_key);
});

it('rejects unauthenticated reverb-config', function () {
    $this->getJson('/api/v1/devices/reverb-config')->assertUnauthorized();
});

it('returns 422 when device branch relationship fails to load', function () {
    // Create device with a valid branch, then delete the branch
    // to simulate a race condition where the relationship returns null
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_deleted_branch',
        'branch_id' => $branch->id,
    ]);

    // Delete the branch (this would cascade in production, but in test
    // we can manually delete to trigger the defensive check)
    $branch->forceDelete();

    $this->withHeader('Authorization', 'Bearer tok_deleted_branch')
        ->getJson('/api/v1/devices/reverb-config')
        ->assertStatus(422);
});

it('returns 422 when branch has no matching brand', function () {
    $branch = Branch::factory()->create([
        'console_brand_id' => (string) Str::uuid(), // orphaned
    ]);
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_orphan_brand',
        'branch_id' => $branch->id,
    ]);

    $this->withHeader('Authorization', 'Bearer tok_orphan_brand')
        ->getJson('/api/v1/devices/reverb-config')
        ->assertStatus(422);
});
