<?php

/**
 * NotificationService::dispatch channel fan-out tests (plan-012 T3.8).
 *
 * Covers the effective-channel selection pipeline described in plan-008
 * DESIGN.md §Effective-channel selection: routes → priority overrides →
 * user preferences → quiet hours → master mute + urgent bypass.
 */

use App\Models\NotificationChannelRoute;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Omnify\Enums\NotificationPriorityEnum;
use App\Services\Notification\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
});

function makeUser(string $orgId): User
{
    return User::factory()->create([
        'console_organization_id' => $orgId,
    ]);
}

it('creates one delivery per (recipient × route channel) — 3 × 2 = 6 rows', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'channels' => ['in_app', 'email'],
    ]);

    $users = collect([makeUser($this->orgId), makeUser($this->orgId), makeUser($this->orgId)]);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'recipe.approved',
        'organization_id' => $this->orgId,
        'recipients' => $users,
    ]);

    $deliveries = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->get();

    expect($deliveries)->toHaveCount(6);
    expect($deliveries->pluck('channel')->map(fn ($c) => $c->value)->unique()->sort()->values()->all())
        ->toBe(['email', 'in_app']);
    // NotificationService now runs channel jobs inline (QUEUE_CONNECTION=sync), so
    // deliveries end at terminal statuses (Sent for in_app, Skipped for email
    // when no mailer driver is configured in tests) rather than staying Pending.
    expect($deliveries->pluck('status')->every(fn ($s) => in_array(
        $s,
        [NotificationDeliveryStatusEnum::Sent, NotificationDeliveryStatusEnum::Skipped, NotificationDeliveryStatusEnum::Failed],
        true,
    )))->toBeTrue();
});

it('master mute on priority=normal keeps in_app but strips realtime/email', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'channels' => ['in_app', 'realtime', 'email'],
    ]);

    $user = makeUser($this->orgId);
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => '*',
        'channel' => '*',
        'enabled' => true,
        'master_mute' => true,
    ]);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'recipe.approved',
        'organization_id' => $this->orgId,
        'recipients' => [$user],
        'priority' => NotificationPriorityEnum::Normal,
    ]);

    $channels = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->pluck('channel')
        ->map(fn ($c) => $c->value)
        ->all();

    expect($channels)->toBe(['in_app']);
});

it('master mute on priority=urgent is bypassed — all channels delivered', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'system.critical',
        'channels' => ['in_app', 'realtime', 'email'],
    ]);

    $user = makeUser($this->orgId);
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => '*',
        'channel' => '*',
        'enabled' => true,
        'master_mute' => true,
    ]);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'system.critical',
        'organization_id' => $this->orgId,
        'recipients' => [$user],
        'priority' => NotificationPriorityEnum::Urgent,
    ]);

    $channels = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->pluck('channel')
        ->map(fn ($c) => $c->value)
        ->sort()
        ->values()
        ->all();

    expect($channels)->toBe(['email', 'in_app', 'realtime']);
});

it('type-specific opt-out wins even on urgent dispatch', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'stock.alert.out',
        'channels' => ['in_app', 'email'],
    ]);

    $user = makeUser($this->orgId);
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => 'stock.alert.out',
        'channel' => 'email',
        'enabled' => false,
        'master_mute' => false,
    ]);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'stock.alert.out',
        'organization_id' => $this->orgId,
        'recipients' => [$user],
        'priority' => NotificationPriorityEnum::Urgent,
    ]);

    $channels = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->pluck('channel')
        ->map(fn ($c) => $c->value)
        ->all();

    expect($channels)->toBe(['in_app']);
});

it('quiet hours 22:00–07:00 Asia/Tokyo skips realtime+email at 23:00', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 23:00:00', 'Asia/Tokyo'));

    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'channels' => ['in_app', 'realtime', 'email'],
    ]);

    $user = makeUser($this->orgId);
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => '*',
        'channel' => '*',
        'enabled' => true,
        'master_mute' => false,
        'quiet_from' => '22:00',
        'quiet_to' => '07:00',
        'quiet_timezone' => 'Asia/Tokyo',
    ]);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'recipe.approved',
        'organization_id' => $this->orgId,
        'recipients' => [$user],
        'priority' => NotificationPriorityEnum::Normal,
    ]);

    $channels = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->pluck('channel')
        ->map(fn ($c) => $c->value)
        ->all();

    expect($channels)->toBe(['in_app']);

    CarbonImmutable::setTestNow();
});

// Issue #172 — push channel was missing from QUIET_HOURS_CHANNELS, so push
// notifications would interrupt during user's quiet hours. Push is the most
// disruptive channel (vibrate / lockscreen), so it MUST be silenced in
// quiet hours alongside realtime + email.
it('quiet hours strips push (issue #172)', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-22 23:00:00', 'Asia/Tokyo'));

    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'channels' => ['in_app', 'realtime', 'email', 'push'],
    ]);

    $user = makeUser($this->orgId);
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'type' => '*',
        'channel' => '*',
        'enabled' => true,
        'master_mute' => false,
        'quiet_from' => '22:00',
        'quiet_to' => '07:00',
        'quiet_timezone' => 'Asia/Tokyo',
    ]);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'recipe.approved',
        'organization_id' => $this->orgId,
        'recipients' => [$user],
        'priority' => NotificationPriorityEnum::Normal,
    ]);

    $channels = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->pluck('channel')
        ->map(fn ($c) => $c->value)
        ->all();

    // Only in_app survives — realtime, email, AND push all get stripped.
    expect($channels)->toBe(['in_app']);

    CarbonImmutable::setTestNow();
});

it('falls back to [in_app] when no routes + no template.default_channels exist', function () {
    $user = makeUser($this->orgId);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'ad.hoc.unknown',
        'organization_id' => $this->orgId,
        'recipients' => [$user],
    ]);

    $deliveries = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->get();

    expect($deliveries)->toHaveCount(1);
    expect($deliveries->first()->channel)->toBe(NotificationChannelEnum::InApp);
});

it('priority override on the route supersedes base channels', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'recipe.approved',
        'channels' => ['in_app'],
        'priority_overrides' => ['urgent' => ['in_app', 'realtime', 'email']],
    ]);

    $user = makeUser($this->orgId);

    $service = app(NotificationService::class);
    $notification = $service->dispatch([
        'type' => 'recipe.approved',
        'organization_id' => $this->orgId,
        'recipients' => [$user],
        'priority' => NotificationPriorityEnum::Urgent,
    ]);

    $channels = NotificationDelivery::query()
        ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notification->id))
        ->pluck('channel')
        ->map(fn ($c) => $c->value)
        ->sort()
        ->values()
        ->all();

    expect($channels)->toBe(['email', 'in_app', 'realtime']);
});
