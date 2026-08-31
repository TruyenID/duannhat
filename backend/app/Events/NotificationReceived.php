<?php

namespace App\Events;

use App\Models\Device;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a Notification fans out to a recipient over the realtime channel
 * (plan-012 T4.8). Subscribes admin-web / tms-app / workstation-app via
 * Laravel Echo's private channel.
 *
 * `ShouldBroadcastNow` skips the queue worker — realtime delivery must be
 * instant; retries are not meaningful for WS. `ShouldDispatchAfterCommit`
 * delays firing until the parent `NotificationService::dispatch` transaction
 * commits so subscribers can always fetch the row that just went out.
 *
 * The broadcast payload is deliberately minimal — just an envelope. Clients
 * refetch `/me/notifications` to pick up authoritative content (title, body,
 * actor, subject) rendered server-side against their locale.
 */
class NotificationReceived implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Notification $notification,
        public readonly NotificationRecipient $recipient,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $model = $this->recipient->recipient;
        if ($model instanceof User) {
            return [new PrivateChannel("user.{$model->id}.notifications")];
        }
        if ($model instanceof Device) {
            return [new PrivateChannel("device.{$model->id}.notifications")];
        }

        return [];
    }

    public function broadcastAs(): string
    {
        return 'notification.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'priority' => $this->notification->priority?->value ?? $this->notification->priority,
            'created_at' => $this->notification->created_at?->toISOString(),
        ];
    }
}
