<?php

/**
 * Plan-023 M8 T8.4 — DeviceOfflineDetectionJob tests.
 */

use App\Jobs\Notification\DeviceOfflineDetectionJob;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    Cache::flush();
    $this->threshold = 15;
    $this->cooldown = 60;
    config(['notifications.device_offline_threshold_minutes' => $this->threshold]);
    config(['notifications.device_offline_cooldown_minutes' => $this->cooldown]);
});

it('M8-9: device offline detected and event dispatched for stale devices', function () {
    // 3 active devices with stale heartbeats
    Device::factory()->count(3)->create([
        'status' => DeviceStatusEnum::Active,
        'last_seen_at' => Carbon::now()->subMinutes($this->threshold + 5),
    ]);

    (new DeviceOfflineDetectionJob)->handle();

    Event::assertDispatched('custom.device.offline.detected', 3);
});

it('M8-10: device offline cooldown prevents double-fire when a slot is held', function () {
    $device = Device::factory()->create([
        'status' => DeviceStatusEnum::Active,
        'last_seen_at' => Carbon::now()->subMinutes($this->threshold + 5),
    ]);

    // The cooldown slot is already claimed within the window.
    Cache::add(
        "notifications:device-offline:cooldown:{$device->id}",
        Carbon::now()->toIso8601String(),
        Carbon::now()->addMinutes($this->cooldown),
    );

    (new DeviceOfflineDetectionJob)->handle();

    // Should not fire because the cooldown slot is held.
    Event::assertNotDispatched('custom.device.offline.detected');
});

it('M8-11: device with fresh heartbeat is skipped', function () {
    // Device active but with fresh heartbeat (within threshold)
    Device::factory()->create([
        'status' => DeviceStatusEnum::Active,
        'last_seen_at' => Carbon::now()->subMinutes($this->threshold - 5),
    ]);

    (new DeviceOfflineDetectionJob)->handle();

    Event::assertNotDispatched('custom.device.offline.detected');
});

it('M8-12: a second scan within the window does not re-fire (no storm without a matched firing)', function () {
    // Regression for the plan-audit logic-risk: the cooldown must NOT depend on
    // a downstream `outcome=matched` firing (shadow mode / no active rule / async
    // lag never writes one). Two consecutive scans must still fire exactly once.
    Device::factory()->create([
        'status' => DeviceStatusEnum::Active,
        'last_seen_at' => Carbon::now()->subMinutes($this->threshold + 5),
    ]);

    (new DeviceOfflineDetectionJob)->handle();
    (new DeviceOfflineDetectionJob)->handle();

    Event::assertDispatched('custom.device.offline.detected', 1);
});

it('M8-13: device re-fires once the cooldown window has elapsed', function () {
    Device::factory()->create([
        'status' => DeviceStatusEnum::Active,
        'last_seen_at' => Carbon::now()->subMinutes($this->threshold + 5),
    ]);

    (new DeviceOfflineDetectionJob)->handle();

    // Fast-forward past the cooldown window — the held slot has expired.
    Carbon::setTestNow(Carbon::now()->addMinutes($this->cooldown + 1));

    (new DeviceOfflineDetectionJob)->handle();

    Carbon::setTestNow();

    Event::assertDispatched('custom.device.offline.detected', 2);
});
