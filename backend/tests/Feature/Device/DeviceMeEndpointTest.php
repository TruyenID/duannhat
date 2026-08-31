<?php

use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

it('accepts any active device type at /devices/me', function (DeviceTypeEnum $type) {
    $device = Device::factory()->create([
        'type' => $type,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_'.$type->value,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer tok_'.$type->value)
        ->getJson('/api/v1/devices/me');

    $response->assertOk()
        ->assertJsonPath('data.id', $device->id)
        ->assertJsonPath('data.type', $type->value)
        ->assertJsonMissing(['device_token' => 'tok_'.$type->value]);
})->with([
    DeviceTypeEnum::Kiosk,
    DeviceTypeEnum::Tms,
    DeviceTypeEnum::Workstation,
    DeviceTypeEnum::Kds,
]);

it('rejects missing bearer token at /devices/me', function () {
    $this->getJson('/api/v1/devices/me')->assertUnauthorized();
});

it('rejects revoked device at /devices/me', function () {
    Device::factory()->create([
        'status' => DeviceStatusEnum::Revoked,
        'device_token' => 'tok_revoked',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_revoked')
        ->getJson('/api/v1/devices/me')
        ->assertUnauthorized();
});
