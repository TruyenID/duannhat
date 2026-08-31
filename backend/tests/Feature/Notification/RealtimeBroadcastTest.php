<?php

/**
 * Realtime broadcast tests (plan-012 T4.8 + T4.9 + T4.10).
 */

use App\Broadcasting\BrandAwareBroadcastManager;
use App\Events\NotificationReceived;
use App\Jobs\NotificationChannelJob;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\Channels\DeliveryResult;
use App\Services\Notification\Channels\RealtimeChannel;
use App\Services\Notification\NotificationService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Bus;
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
        'slug' => 'rt-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->brand->refresh();
});

describe('NotificationReceived event shape', function () {
    it('broadcasts on user.{id}.notifications for user recipients', function () {
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

        $event = new NotificationReceived($notif, $recipient);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        /** @var PrivateChannel $channel */
        $channel = $channels[0];
        expect($channel->name)->toBe("private-user.{$user->id}.notifications");
        expect($event->broadcastAs())->toBe('notification.received');
        expect($event->broadcastWith())->toMatchArray([
            'id' => $notif->id,
            'type' => 'recipe.approved',
        ]);
    });
});

describe('RealtimeChannel::send', function () {
    it('broadcasts NotificationReceived when the brand has reverb_app_id', function () {
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

        /** @var DeliveryResult $result */
        $result = app(RealtimeChannel::class)->send($notif, $recipient);

        expect($result->status)->toBe(NotificationDeliveryStatusEnum::Sent);
        expect(BrandAwareBroadcastManager::$recorded)->toHaveCount(1);
        expect(BrandAwareBroadcastManager::$recorded[0]['app_id'])->toBe($this->brand->reverb_app_id);
    });

    it('returns skipped (not failed) when the brand has no reverb_app_id', function () {
        $this->brand->forceFill(['reverb_app_id' => null])->saveQuietly();

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

        expect($result->status)->toBe(NotificationDeliveryStatusEnum::Skipped);
        expect(BrandAwareBroadcastManager::$recorded)->toBeEmpty();
    });
});

describe('dispatch integration', function () {
    it('fires NotificationReceived after commit when realtime is in the effective channel set', function () {
        Bus::fake([NotificationChannelJob::class]);

        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'recipe.approved',
            'channels' => ['in_app', 'realtime'],
        ]);

        $user = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);

        $notif = app(NotificationService::class)->dispatch([
            'type' => 'recipe.approved',
            'organization_id' => $this->orgId,
            'recipients' => [$user],
        ]);

        $channels = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notif->id))
            ->pluck('channel')
            ->map(fn ($c) => $c->value)
            ->sort()
            ->values()
            ->all();

        expect($channels)->toBe(['in_app', 'realtime']);
    });
});

describe('cross-brand isolation', function () {
    it('broadcasts with the correct per-brand app_id for notifications in two different brands', function () {
        $orgB = (string) Str::uuid();
        Organization::factory()->create(['id' => $orgB, 'console_organization_id' => $orgB]);
        $brandB = Brand::factory()->create([
            'console_organization_id' => $orgB,
            'slug' => 'rt-b-'.Str::random(4),
            'is_active' => true,
        ]);
        $brandB->refresh();

        $userA = User::factory()->create(['console_organization_id' => $this->orgId]);
        $userB = User::factory()->create(['console_organization_id' => $orgB]);

        $notifA = Notification::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'recipe.approved', 'template_key' => 'recipe.approved',
            'params' => [], 'priority' => 'normal',
        ]);
        $recA = NotificationRecipient::query()->create([
            'notification_id' => $notifA->id,
            'recipient_type' => $userA->getMorphClass(),
            'recipient_id' => $userA->id,
        ]);
        $notifB = Notification::query()->create([
            'organization_id' => $orgB,
            'type' => 'recipe.approved', 'template_key' => 'recipe.approved',
            'params' => [], 'priority' => 'normal',
        ]);
        $recB = NotificationRecipient::query()->create([
            'notification_id' => $notifB->id,
            'recipient_type' => $userB->getMorphClass(),
            'recipient_id' => $userB->id,
        ]);

        app(RealtimeChannel::class)->send($notifA, $recA);
        app(RealtimeChannel::class)->send($notifB, $recB);

        $appIds = collect(BrandAwareBroadcastManager::$recorded)->pluck('app_id')->values()->all();
        expect($appIds)->toBe([$this->brand->reverb_app_id, $brandB->reverb_app_id]);
    });
});
