<?php

use App\Models\Branch;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

it('allows kds device in matching branch', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_b',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kds_b')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '11111.22222',
            'channel_name' => "private-branch.{$branch->id}.kds-events",
        ])
        ->assertOk();
});

it('blocks kds device from other branch', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branchA->id,
        'device_token' => 'tok_kds_a',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kds_a')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '11111.22222',
            'channel_name' => "private-branch.{$branchB->id}.kds-events",
        ])
        ->assertForbidden();
});

it('blocks non-kds device types from kds-events', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kiosk,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kio_b',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kio_b')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '11111.22222',
            'channel_name' => "private-branch.{$branch->id}.kds-events",
        ])
        ->assertForbidden();
});
