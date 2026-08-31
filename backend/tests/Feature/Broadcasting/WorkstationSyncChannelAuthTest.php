<?php

use App\Models\Branch;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

/*
 * #1175 — private-workstation.sync.{branchId} carries only the contentless
 * `sync.poke` hint (WorkstationSyncPoke). Only an ACTIVE workstation paired
 * to that exact branch may subscribe.
 */

it('allows a workstation device on its own branch sync channel', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_sync',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_ws_sync')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '11111.22222',
            'channel_name' => "private-workstation.sync.{$branch->id}",
        ])
        ->assertOk();
});

it('blocks a workstation from another branch sync channel', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branchA->id,
        'device_token' => 'tok_ws_sync_a',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_ws_sync_a')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '11111.22222',
            'channel_name' => "private-workstation.sync.{$branchB->id}",
        ])
        ->assertForbidden();
});

it('blocks non-workstation device types from the sync channel', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_sync',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kds_sync')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '11111.22222',
            'channel_name' => "private-workstation.sync.{$branch->id}",
        ])
        ->assertForbidden();
});

it('blocks a revoked workstation before it ever reaches the channel callback', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Revoked,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_sync_revoked',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_ws_sync_revoked')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '11111.22222',
            'channel_name' => "private-workstation.sync.{$branch->id}",
        ])
        ->assertUnauthorized();
});
