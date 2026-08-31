<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hourly safety net for scheduled notifications (plan-012 T4.14, Decision 18).
 *
 * Delayed jobs can be evicted on a redis crash, a manual queue flush, or a
 * worker restart during the scheduled window. This job scans for any
 * Notification whose `scheduled_for` has already passed by at least 5 min
 * and whose `is_dispatched` flag is still false, and re-queues the
 * DispatchScheduledNotificationJob with zero delay.
 *
 * The 5-minute grace window prevents races with delayed jobs that are
 * about to fire — we only touch rows that are demonstrably late.
 *
 * Registered in routes/console.php: `Schedule::job(...)->hourly()`.
 */
class ScheduledNotificationHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $cutoff = now()->subMinutes(5);

        $missed = Notification::query()
            ->where('is_dispatched', false)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $cutoff)
            ->pluck('id');

        foreach ($missed as $id) {
            Log::warning('notification.scheduled.missed', ['notification_id' => $id]);
            DispatchScheduledNotificationJob::dispatch($id);
        }

        // L3 (#555) — orphaned-delivery safety net. A notification whose worker
        // died mid-dispatch commits is_dispatched=true yet leaves deliveries
        // stranded `pending` with attempts=0. Those are invisible to the
        // is_dispatched=false scan above, and if the job exhausted its retries
        // nothing else re-queues them. Sweep for dispatched notifications that
        // still carry untouched pending deliveries older than the grace window
        // and re-queue — DispatchScheduledNotificationJob now heals orphans on
        // re-entry. The created_at floor avoids racing a delivery that is
        // legitimately queued-but-not-yet-started.
        $orphanNotificationIds = Notification::query()
            ->where('is_dispatched', true)
            ->whereIn(
                'id',
                NotificationRecipient::query()
                    ->select('notification_id')
                    ->whereIn(
                        'id',
                        NotificationDelivery::query()
                            ->select('notification_recipient_id')
                            ->where('status', NotificationDeliveryStatusEnum::Pending->value)
                            ->where('attempts', 0)
                            ->where('created_at', '<=', $cutoff)
                    )
            )
            ->pluck('id');

        foreach ($orphanNotificationIds as $id) {
            Log::warning('notification.scheduled.orphaned_deliveries', ['notification_id' => $id]);
            DispatchScheduledNotificationJob::dispatch($id);
        }
    }
}
