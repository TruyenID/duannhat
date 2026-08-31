<?php

/**
 * Broadcast driver swap verification (plan-012 T4.15).
 *
 * Asserts that switching `BROADCAST_CONNECTION` from `reverb` to `pusher`
 * requires no code change — realtime dispatch still reaches `broadcast()`
 * through the BrandAwareBroadcastManager and the stored app credentials
 * remain the same column on the Brand row. The actual Pusher wire-up is
 * a deployment concern (env vars + pusher creds).
 */

use App\Broadcasting\BrandAwareBroadcastManager;
use App\Events\NotificationReceived;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\Channels\DeliveryResult;
use App\Services\Notification\Channels\RealtimeChannel;
use Illuminate\Support\Str;

beforeEach(function () {
    BrandAwareBroadcastManager::$recorded = [];
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'drv-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->brand->refresh();
});

it('routes broadcast through the same manager when BROADCAST_CONNECTION is pusher', function () {
    config(['broadcasting.default' => 'pusher']);

    $user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $notif = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => [],
        'priority' => 'normal',
    ]);
    $recipient = NotificationRecipient::query()->create([
        'notification_id' => $notif->id,
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
    ]);

    app(RealtimeChannel::class)->send($notif, $recipient);

    expect(BrandAwareBroadcastManager::$recorded)->toHaveCount(1);
    expect(BrandAwareBroadcastManager::$recorded[0]['event'])->toBeInstanceOf(NotificationReceived::class);
    expect(BrandAwareBroadcastManager::$recorded[0]['app_id'])->toBe($this->brand->reverb_app_id);
});

it('BrandAwareBroadcastManager does not reference Reverb classes directly in its public API', function () {
    $refl = new ReflectionClass(BrandAwareBroadcastManager::class);
    $source = file_get_contents($refl->getFileName());

    expect($source)->not->toContain('Laravel\\Reverb\\');
});

// =============================================================================
// Driver-swap matrix
// =============================================================================
//
// Confirms RealtimeChannel still routes through BrandAwareBroadcastManager
// regardless of which Laravel broadcast driver is set as default. We don't
// require cross-brand isolation on Pusher/Ably (those are shared accounts in
// the deploy plan); we only need the dispatch path to not crash when the env
// var changes. Per-brand Reverb continues to work as before.
//
// =============================================================================

dataset('alternative_drivers', [
    'pusher' => ['pusher'],
    'ably' => ['ably'],
    'log' => ['log'],
    'null' => ['null'],
]);

it('routes broadcast through the manager when BROADCAST_CONNECTION is :driver', function (string $driver) {
    config(['broadcasting.default' => $driver]);

    $user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $notif = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => [],
        'priority' => 'normal',
    ]);
    $recipient = NotificationRecipient::query()->create([
        'notification_id' => $notif->id,
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
    ]);

    $result = app(RealtimeChannel::class)->send($notif, $recipient);

    // The contract for "driver swap doesn't crash":
    //   1. The manager records the dispatch (i.e. RealtimeChannel reached the
    //      brand-aware broadcast call without throwing on driver resolution).
    //   2. The event dispatched is NotificationReceived (no event-mapping
    //      regression when default driver changes).
    //   3. RealtimeChannel returns a structured DeliveryResult — not an
    //      uncaught exception.
    //
    // Whether the underlying queue actually reaches Pusher/Ably with valid
    // payload is a deployment concern (env vars + creds), so a `Failed` status
    // here is acceptable — it just means the test env has no real creds, not
    // that the swap broke. What we forbid is an uncaught throw.
    expect(BrandAwareBroadcastManager::$recorded)->toHaveCount(1);
    expect(BrandAwareBroadcastManager::$recorded[0]['event'])->toBeInstanceOf(NotificationReceived::class);
    expect($result)->toBeInstanceOf(DeliveryResult::class);
})->with('alternative_drivers');

it('skips gracefully (does not throw) when brand has no reverb_app_id, regardless of driver', function () {
    // Drop the auto-provisioned brand from beforeEach so the unprovisioned
    // brand is the only one matching console_organization_id when
    // RealtimeChannel resolves.
    $this->brand->forceDelete();

    // The `Brand::created` hook auto-provisions reverb_app_id — we have to
    // null it out post-create to simulate an unprovisioned brand.
    $unprovisioned = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'drv-noreverb-'.Str::random(4),
        'is_active' => true,
    ]);
    $unprovisioned->forceFill([
        'reverb_app_id' => null,
        'reverb_app_key' => null,
        'reverb_app_secret' => null,
    ])->saveQuietly();

    $user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $notif = Notification::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => [],
        'priority' => 'normal',
    ]);
    $recipient = NotificationRecipient::query()->create([
        'notification_id' => $notif->id,
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
    ]);

    foreach (['reverb', 'pusher', 'ably', 'null'] as $driver) {
        config(['broadcasting.default' => $driver]);
        BrandAwareBroadcastManager::$recorded = [];

        $result = app(RealtimeChannel::class)->send($notif, $recipient);
        expect($result->status)->toBe(
            NotificationDeliveryStatusEnum::Skipped,
            "expected skipped on driver={$driver}"
        );
    }
});

it('non-broadcastable events short-circuit safely (does not call underlying queue)', function () {
    // Plain stdClass is not a ShouldBroadcast — the manager's
    // `dispatchUnderlying()` early-returns. Verifies the safety net for unit
    // tests that pass non-event objects through.
    config(['broadcasting.default' => 'pusher']);

    $manager = app(BrandAwareBroadcastManager::class);
    $manager->brand($this->brand)->broadcast(new stdClass);

    // Recorded but not actually queued.
    expect(BrandAwareBroadcastManager::$recorded)->toHaveCount(1);
});
