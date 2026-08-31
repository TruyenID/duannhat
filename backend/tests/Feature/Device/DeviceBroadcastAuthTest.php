<?php

/**
 * /api/v1/devices/broadcasting/auth tests (plan-027 T0.3).
 *
 * Verifies that device-paired clients can subscribe to their own Reverb
 * private channels and are forbidden from subscribing to other devices'
 * channels.
 */

use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

it('signs subscription for device-owned notifications channel', function () {
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kiosk,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_kiosk_a',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kiosk_a')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => "private-device.{$device->id}.notifications",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('rejects subscription to other device channels', function () {
    $a = Device::factory()->create(['device_token' => 'tok_a', 'status' => DeviceStatusEnum::Active]);
    $b = Device::factory()->create(['status' => DeviceStatusEnum::Active]);

    $this->withHeader('Authorization', 'Bearer tok_a')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => "private-device.{$b->id}.notifications",
        ])
        ->assertForbidden();
});

it('signs with the ACTIVE connection, not a hardcoded reverb connection', function () {
    // Production runs BROADCAST_CONNECTION=pusher. The signature must use the
    // app the client dialed — hardcoding `connections.reverb` 500'd every
    // device channel auth on production (Tsukiji poke, 2026-08-18) while the
    // reverb-driver test env stayed green. Credentials go on the ACTIVE
    // connection here (the test env's `null`) — swapping `default` mid-test
    // would discard the channel callbacks registered at boot.
    $driver = (string) config('broadcasting.default');
    config([
        "broadcasting.connections.{$driver}.key" => 'active-key',
        "broadcasting.connections.{$driver}.secret" => 'active-secret',
    ]);

    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kiosk,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_active_drv',
    ]);

    $channel = "private-device.{$device->id}.notifications";
    $expected = 'active-key:'.hash_hmac('sha256', "12345.67890:{$channel}", 'active-secret');

    $this->withHeader('Authorization', 'Bearer tok_active_drv')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => $channel,
        ])
        ->assertOk()
        ->assertJson(['auth' => $expected]);
});

it('returns 500 when no signing-capable connection is configured', function () {
    // Active connection carries no credentials AND the reverb fallback is
    // emptied — nothing can sign, and that must be loud, not a bad signature.
    config(['broadcasting.connections.reverb.secret' => '']);

    Device::factory()->create([
        'type' => DeviceTypeEnum::Kiosk,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_misconf',
    ]);
    $device = Device::where('device_token', 'tok_misconf')->first();

    $this->withHeader('Authorization', 'Bearer tok_misconf')
        ->postJson('/api/v1/devices/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => "private-device.{$device->id}.notifications",
        ])
        ->assertStatus(500);
});
