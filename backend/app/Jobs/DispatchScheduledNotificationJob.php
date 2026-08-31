<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\EffectiveChannelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fires a scheduled notification at `scheduled_for` (plan-012 T4.14).
 *
 * NotificationService::dispatch queues this job with
 * `->delay($scheduled_for)` when the dispatch input carries a future
 * `scheduled_for`. The job:
 *
 *   1. Reloads the Notification row — bails silently if it was cancelled
 *      (DELETE endpoint hard-deletes between schedule and fire time).
 *   2. Picks up the recipient rows written at dispatch time, writes
 *      `notification_deliveries` using the effective-channel filter chain,
 *      dispatches one `NotificationChannelJob` per delivery.
 *   3. Marks `is_dispatched=true`.
 *
 * Re-entrant: `is_dispatched=true` short-circuits subsequent runs so the
 * health-check job (T4.14) can re-queue missed schedules without double-firing.
 */
class DispatchScheduledNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $notificationId) {}

    public function handle(EffectiveChannelService $effectiveChannels): void
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()->find($this->notificationId);
        if ($notification === null) {
            return;
        }

        // L3 (#555) — orphaned-delivery recovery. The happy path commits
        // is_dispatched=true AND the delivery rows in one transaction, then
        // dispatches one NotificationChannelJob per delivery OUTSIDE that
        // transaction. If the worker dies after the commit but before/during
        // that loop, the deliveries are stranded `pending` with attempts=0 and
        // were never queued — and this job used to short-circuit right here on
        // is_dispatched, so neither a retry nor the health-check could ever
        // fire them. Re-queue any such orphans instead of returning blind.
        if ($notification->is_dispatched) {
            $this->dispatchPendingDeliveries($notification);

            return;
        }

        $recipients = NotificationRecipient::query()
            ->with('recipient')
            ->where('notification_id', $notification->id)
            ->get();

        if ($recipients->isEmpty()) {
            $notification->is_dispatched = true;
            $notification->save();

            return;
        }

        $deliveryIds = [];

        DB::transaction(function () use ($notification, $recipients, $effectiveChannels, &$deliveryIds) {
            $recipientModels = [];
            $rowIdByKey = [];
            foreach ($recipients as $row) {
                $model = $row->recipient;
                if ($model === null) {
                    continue;
                }
                $key = $model->getMorphClass().':'.$model->getKey();
                $recipientModels[] = $model;
                $rowIdByKey[$key] = $row->id;
            }

            $channelsByRecipient = $effectiveChannels->effectiveChannelsForBatch($notification, $recipientModels);

            $rows = [];
            foreach ($channelsByRecipient as $key => $channels) {
                $rowId = $rowIdByKey[$key] ?? null;
                if ($rowId === null) {
                    continue;
                }
                foreach ($channels as $channel) {
                    $id = (string) Str::uuid();
                    $deliveryIds[] = $id;
                    $rows[] = [
                        'id' => $id,
                        'notification_recipient_id' => $rowId,
                        'channel' => $channel->value,
                        'status' => NotificationDeliveryStatusEnum::Pending->value,
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($rows !== []) {
                NotificationDelivery::insert($rows);
            }

            $notification->is_dispatched = true;
            $notification->save();
        });

        foreach ($deliveryIds as $id) {
            NotificationChannelJob::dispatch($id);
        }
    }

    /**
     * Re-queue delivery rows that were persisted but never dispatched.
     *
     * An orphan is a delivery still `pending` with `attempts=0`: the channel
     * job bumps `attempts` to 1 the instant it starts processing, so a row that
     * is pending AND untouched is one that was never handed to a worker. By the
     * time this recovery runs (a job retry under the backoff schedule, or the
     * hourly health-check with its 5-minute grace) any legitimately in-flight
     * delivery has already advanced past attempts=0, so this cannot double-send
     * a live one.
     */
    private function dispatchPendingDeliveries(Notification $notification): void
    {
        $orphanIds = NotificationDelivery::query()
            ->whereIn(
                'notification_recipient_id',
                NotificationRecipient::query()
                    ->where('notification_id', $notification->id)
                    ->select('id')
            )
            ->where('status', NotificationDeliveryStatusEnum::Pending->value)
            ->where('attempts', 0)
            ->pluck('id');

        foreach ($orphanIds as $id) {
            NotificationChannelJob::dispatch($id);
        }
    }
}
