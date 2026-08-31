<?php

namespace App\Events;

use App\Models\CustomerOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * plan-034 — POS staff just opened an edit session on this order. Every
 * customer-web client subscribed to the table-session channel sees the
 * event, disables the "+" buttons, and shows a banner.
 *
 * The matching {@see OrderEditingEnded} event clears the lock.
 */
class OrderEditingStarted implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public CustomerOrder $order) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if (! $this->order->table_session_id) {
            return [];
        }

        return [
            new Channel("table-session.{$this->order->table_session_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.editing-started';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'editing_by_staff_at' => $this->order->editing_by_staff_at?->toIso8601String(),
        ];
    }
}
