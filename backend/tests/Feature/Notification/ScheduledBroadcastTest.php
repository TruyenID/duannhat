<?php

/**
 * Scheduled broadcast tests (plan-012 T4.14).
 */

use App\Jobs\DispatchScheduledNotificationJob;
use App\Jobs\NotificationChannelJob;
use App\Jobs\ScheduledNotificationHealthCheckJob;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\EffectiveChannelService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'sch-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
});

describe('dispatch path', function () {
    it('queues DispatchScheduledNotificationJob with a delay when scheduled_for is in the future', function () {
        Bus::fake([DispatchScheduledNotificationJob::class, NotificationChannelJob::class]);

        $recipient = User::factory()->create(['console_organization_id' => $this->orgId]);

        $notif = app(NotificationService::class)->dispatch([
            'type' => 'recipe.approved',
            'organization_id' => $this->orgId,
            'recipients' => [$recipient],
            'scheduled_for' => now()->addHour(),
        ]);

        Bus::assertDispatched(DispatchScheduledNotificationJob::class);
        Bus::assertNotDispatched(NotificationChannelJob::class);

        expect($notif->is_dispatched)->toBeFalse();
        $deliveries = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notif->id))
            ->count();
        expect($deliveries)->toBe(0);
    });
});

describe('DispatchScheduledNotificationJob::handle', function () {
    it('writes deliveries + marks is_dispatched=true when the time comes', function () {
        Bus::fake([NotificationChannelJob::class]);

        $recipient = User::factory()->create(['console_organization_id' => $this->orgId]);
        $notif = Notification::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'recipe.approved',
            'template_key' => 'recipe.approved',
            'params' => [],
            'priority' => 'normal',
            'scheduled_for' => now()->subMinute(),
            'is_dispatched' => false,
        ]);
        NotificationRecipient::query()->create([
            'notification_id' => $notif->id,
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->id,
        ]);

        (new DispatchScheduledNotificationJob($notif->id))
            ->handle(app(EffectiveChannelService::class));

        $notif->refresh();
        expect($notif->is_dispatched)->toBeTrue();

        $deliveries = NotificationDelivery::query()
            ->whereHas('notificationRecipient', fn ($q) => $q->where('notification_id', $notif->id))
            ->count();
        expect($deliveries)->toBe(1);
    });

    it('no-ops silently when the notification was cancelled between schedule and fire', function () {
        $fakeId = (string) Str::uuid();

        (new DispatchScheduledNotificationJob($fakeId))
            ->handle(app(EffectiveChannelService::class));

        // No exception thrown and no rows written.
        expect(NotificationDelivery::query()->count())->toBe(0);
    });
});

describe('cancellation endpoint', function () {
    it('DELETE on a scheduled notification > 60s out soft-deletes it', function () {
        Bus::fake([DispatchScheduledNotificationJob::class, NotificationChannelJob::class]);

        $recipient = User::factory()->create(['console_organization_id' => $this->orgId]);
        $notif = app(NotificationService::class)->dispatch([
            'type' => 'recipe.approved',
            'organization_id' => $this->orgId,
            'recipients' => [$recipient],
            'scheduled_for' => now()->addHour(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/notifications/{$notif->id}")
            ->assertNoContent();

        expect(Notification::query()->find($notif->id))->toBeNull();
    });

    it('DELETE rejected when the notification is within the 60s freeze window', function () {
        Bus::fake([DispatchScheduledNotificationJob::class]);

        $recipient = User::factory()->create(['console_organization_id' => $this->orgId]);
        $notif = app(NotificationService::class)->dispatch([
            'type' => 'recipe.approved',
            'organization_id' => $this->orgId,
            'recipients' => [$recipient],
            'scheduled_for' => now()->addSeconds(30),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/notifications/{$notif->id}")
            ->assertStatus(422);
    });

    it('DELETE rejected when the notification has already been dispatched', function () {
        // Non-scheduled dispatch → is_dispatched=true immediately.
        NotificationChannelRoute::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'recipe.approved',
            'channels' => ['in_app'],
        ]);
        Bus::fake([NotificationChannelJob::class]);

        $recipient = User::factory()->create(['console_organization_id' => $this->orgId]);
        $notif = app(NotificationService::class)->dispatch([
            'type' => 'recipe.approved',
            'organization_id' => $this->orgId,
            'recipients' => [$recipient],
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/hq/{$this->brand->slug}/notifications/{$notif->id}")
            ->assertStatus(422);
    });
});

describe('health check', function () {
    it('re-queues notifications scheduled more than 5 min ago whose is_dispatched is still false', function () {
        Bus::fake([DispatchScheduledNotificationJob::class]);

        $missed = Notification::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'recipe.approved',
            'template_key' => 'recipe.approved',
            'params' => [],
            'priority' => 'normal',
            'scheduled_for' => now()->subMinutes(10),
            'is_dispatched' => false,
        ]);

        $onTime = Notification::query()->create([
            'organization_id' => $this->orgId,
            'type' => 'recipe.approved',
            'template_key' => 'recipe.approved',
            'params' => [],
            'priority' => 'normal',
            'scheduled_for' => now()->subMinute(),
            'is_dispatched' => false,
        ]);

        (new ScheduledNotificationHealthCheckJob)->handle();

        Bus::assertDispatched(DispatchScheduledNotificationJob::class, function ($job) use ($missed) {
            return $job->notificationId === $missed->id;
        });
        Bus::assertNotDispatched(DispatchScheduledNotificationJob::class, function ($job) use ($onTime) {
            return $job->notificationId === $onTime->id;
        });
    });
});
