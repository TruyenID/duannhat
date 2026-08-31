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
 * plan-034 — broadcast after an item is added to a session-pinned order
 * so the other devices in the same dine-in session refresh their cart
 * within < 1s.
 *
 * Channel `private-table-session.{sessionId}`. The customer-web hook
 * {@see useTableSessionRealtime} listens for `order.item-added` and
 * invalidates the React Query order cache.
 */
class OrderItemAdded implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
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

        // Public channel — customer-web is anonymous (guest dine-in flow),
        // there's no User to authorise. UUID sessionId provides the
        // (light) secrecy: anyone who guesses the right session can
        // listen, but it's a UUID v4 and dies on payment / 4h timeout.
        return [
            new Channel("table-session.{$this->order->table_session_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.item-added';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'items_count' => $this->order->items()->count(),
            'subtotal' => (float) $this->order->subtotal,
            'total' => (float) $this->order->total_amount,
        ];
    }
}
