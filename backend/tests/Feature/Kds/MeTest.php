<?php

use App\Models\Branch;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

it('returns device info for kds token', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'name' => 'KDS-Hotline',
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_me',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kds_me')
        ->getJson('/api/v1/kds/me')
        ->assertOk()
        ->assertJsonPath('data.id', $device->id)
        ->assertJsonPath('data.type', 'kds')
        ->assertJsonPath('data.name', 'KDS-Hotline')
        ->assertJsonPath('data.branch.id', $branch->id)
        ->assertJsonPath('data.capabilities.supports_offline', true)
        ->assertJsonMissing(['device_token' => 'tok_kds_me']);
});

it('rejects non-kds device type at /kds/me', function () {
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kiosk,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_kio_kds',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kio_kds')
        ->getJson('/api/v1/kds/me')
        ->assertForbidden();
});
