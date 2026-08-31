<?php

use App\Jobs\DispatchScheduledNotificationJob;
use App\Jobs\NotificationChannelJob;
use App\Jobs\ScheduledNotificationHealthCheckJob;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\EffectiveChannelService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/*
 * #555 L3 — a scheduled notification commits is_dispatched=true AND its
 * delivery rows in one transaction, then dispatches NotificationChannelJob per
 * delivery OUTSIDE the tx. If the worker dies after the commit but before the
 * dispatch loop, deliveries are stranded pending/attempts=0 and were never
 * queued. The job used to short-circuit on is_dispatched, so neither a retry
 * nor the health-check (which only scanned is_dispatched=false) recovered them.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->recipient = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
});

function orphanNotification(int $attempts = 0, ?string $status = null, ?string $createdAt = null): array
{
    $notif = Notification::query()->create([
        'organization_id' => test()->orgId,
        'type' => 'recipe.approved',
        'template_key' => 'recipe.approved',
        'params' => [],
        'priority' => 'normal',
        'scheduled_for' => now()->subMinutes(20),
        'is_dispatched' => true, // committed by the worker that then died
    ]);

    $recipientRow = NotificationRecipient::query()->create([
        'notification_id' => $notif->id,
        'recipient_type' => test()->recipient->getMorphClass(),
        'recipient_id' => test()->recipient->id,
    ]);

    $delivery = NotificationDelivery::query()->create([
        'notification_recipient_id' => $recipientRow->id,
        'channel' => 'in_app',
        'status' => $status ?? NotificationDeliveryStatusEnum::Pending->value,
        'attempts' => $attempts,
    ]);

    // created_at is not fillable — stamp it directly so the health-check's
    // grace-window filter can be exercised.
    NotificationDelivery::query()
        ->where('id', $delivery->id)
        ->update(['created_at' => $createdAt ?? now()->subMinutes(20)->toDateTimeString()]);

    return [$notif, $delivery];
}

it('re-queues an orphaned pending delivery when the dispatch job is retried', function () {
    Bus::fake([NotificationChannelJob::class]);

    [$notif, $delivery] = orphanNotification();

    (new DispatchScheduledNotificationJob($notif->id))
        ->handle(app(EffectiveChannelService::class));

    Bus::assertDispatched(NotificationChannelJob::class, fn ($job) => $job->deliveryId === $delivery->id);

    // is_dispatched stays true and no duplicate delivery rows are written.
    expect($notif->fresh()->is_dispatched)->toBeTrue();
    expect(NotificationDelivery::query()->count())->toBe(1);
});

it('does NOT re-queue a delivery already picked up by a worker (attempts > 0)', function () {
    Bus::fake([NotificationChannelJob::class]);

    [$notif] = orphanNotification(attempts: 1);

    (new DispatchScheduledNotificationJob($notif->id))
        ->handle(app(EffectiveChannelService::class));

    Bus::assertNotDispatched(NotificationChannelJob::class);
});

it('does NOT re-queue a delivery already in a terminal state', function () {
    Bus::fake([NotificationChannelJob::class]);

    [$notif] = orphanNotification(status: NotificationDeliveryStatusEnum::Sent->value);

    (new DispatchScheduledNotificationJob($notif->id))
        ->handle(app(EffectiveChannelService::class));

    Bus::assertNotDispatched(NotificationChannelJob::class);
});

it('health-check re-queues a dispatched notification carrying orphaned deliveries', function () {
    Bus::fake([DispatchScheduledNotificationJob::class]);

    [$notif] = orphanNotification();

    (new ScheduledNotificationHealthCheckJob)->handle();

    Bus::assertDispatched(
        DispatchScheduledNotificationJob::class,
        fn ($job) => $job->notificationId === $notif->id,
    );
});

it('health-check ignores fresh orphans inside the 5-minute grace window', function () {
    Bus::fake([DispatchScheduledNotificationJob::class]);

    [$notif] = orphanNotification(createdAt: now()->subMinute()->toDateTimeString());

    (new ScheduledNotificationHealthCheckJob)->handle();

    Bus::assertNotDispatched(DispatchScheduledNotificationJob::class);
});
