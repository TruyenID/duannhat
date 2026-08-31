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
 * plan-034 — POS staff finished (committed or cancelled) their edit
 * session. Customer-web clients re-enable the "+" buttons and clear the
 * banner.
 */
class OrderEditingEnded implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
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
        return 'order.editing-ended';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
        ];
    }
}
