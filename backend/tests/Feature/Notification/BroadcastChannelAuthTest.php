<?php

/**
 * Private channel authorization tests (plan-012 T4.10).
 *
 * The registered channel closures live in routes/channels.php. We look
 * them up on the `Broadcast` facade's driver and invoke them directly —
 * bypassing Laravel's HTTP auth pipeline, which is what the `/broadcasting/auth`
 * endpoint would exercise in prod.
 */

use App\Models\Device;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
});

function channelCallback(string $pattern): Closure
{
    $broadcaster = Broadcast::driver();
    $refl = new ReflectionClass($broadcaster);
    $prop = $refl->getProperty('channels');
    $prop->setAccessible(true);
    $map = $prop->getValue($broadcaster);

    expect($map)->toHaveKey($pattern);

    return $map[$pattern];
}

it('user.{id}.notifications — same user passes', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $cb = channelCallback('user.{userId}.notifications');
    expect($cb($user, (string) $user->id))->toBeTrue();
});

it('user.{id}.notifications — different user is rejected', function () {
    $userA = User::factory()->create(['console_organization_id' => $this->orgId]);
    $userB = User::factory()->create(['console_organization_id' => $this->orgId]);
    $cb = channelCallback('user.{userId}.notifications');
    expect($cb($userA, (string) $userB->id))->toBeFalse();
});

it('device.{id}.notifications — User subject is rejected', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $device = Device::factory()->create();
    $cb = channelCallback('device.{deviceId}.notifications');
    expect($cb($user, (string) $device->id))->toBeFalse();
});

it('device.{id}.notifications — matching device passes', function () {
    $device = Device::factory()->create();
    $cb = channelCallback('device.{deviceId}.notifications');
    expect($cb($device, (string) $device->id))->toBeTrue();
});

it('device.{id}.notifications — mismatching device is rejected', function () {
    $d1 = Device::factory()->create();
    $d2 = Device::factory()->create();
    $cb = channelCallback('device.{deviceId}.notifications');
    expect($cb($d1, (string) $d2->id))->toBeFalse();
});
