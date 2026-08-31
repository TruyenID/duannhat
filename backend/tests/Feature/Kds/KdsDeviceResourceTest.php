<?php

use App\Http\Resources\Kds\KdsDeviceResource;
use App\Models\Branch;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Http\Request;

it('KdsDeviceResource hides internal FK columns', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
    ]);

    $device->load('branch');
    $resource = (new KdsDeviceResource($device))->toArray(Request::create('/'));

    expect($resource)
        ->not->toHaveKey('organization_id')
        ->not->toHaveKey('console_organization_id')
        ->not->toHaveKey('console_brand_id')
        ->not->toHaveKey('created_by_id')
        ->not->toHaveKey('updated_by_id')
        ->not->toHaveKey('device_token')
        ->not->toHaveKey('pairing_code')
        ->not->toHaveKey('pairing_code_expires_at')
        ->not->toHaveKey('pairing_expires_at')
        ->not->toHaveKey('branch_id');
});

it('KdsDeviceResource exposes semantic fields', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'name' => 'Kitchen 1 — Hot',
        'branch_id' => $branch->id,
    ]);

    $device->load('branch');
    $resource = (new KdsDeviceResource($device))->toArray(Request::create('/'));

    expect($resource)->toHaveKey('id');
    expect($resource)->toHaveKey('type');
    expect($resource)->toHaveKey('name');
    expect($resource)->toHaveKey('status');
    expect($resource)->toHaveKey('branch');
    expect($resource)->toHaveKey('paired_at');
    expect($resource)->toHaveKey('last_seen_at');
    expect($resource)->toHaveKey('capabilities');

    expect($resource['name'])->toBe('Kitchen 1 — Hot');
});

it('KdsDeviceResource embeds branch with id/name/code only', function () {
    $branch = Branch::factory()->create([
        'name' => 'Quán Bún Bò Trung Tâm',
        'code' => 'HCM01',
    ]);
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
    ]);

    $device->load('branch');
    $resource = (new KdsDeviceResource($device))->toArray(Request::create('/'));

    expect($resource['branch'])->toBeArray();
    expect($resource['branch'])->toHaveKeys(['id', 'name', 'code']);
    expect(array_keys($resource['branch']))->toHaveCount(3);
    expect($resource['branch']['id'])->toBe($branch->id);
    expect($resource['branch']['name'])->toBe('Quán Bún Bò Trung Tâm');
    expect($resource['branch']['code'])->toBe('HCM01');
});

it('KdsDeviceResource includes capabilities flags', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
    ]);

    $device->load('branch');
    $resource = (new KdsDeviceResource($device))->toArray(Request::create('/'));

    $caps = $resource['capabilities'];

    expect($caps)->toBeArray();
    expect($caps)->toMatchArray([
        'supports_offline' => true,
        'supports_wake_lock' => true,
        'supports_audio' => true,
    ]);
});
