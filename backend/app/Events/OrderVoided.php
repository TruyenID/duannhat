<?php

namespace App\Events;

use App\Models\CustomerOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by CustomerOrderService::voidOrder when an order is voided (or
 * cancelled — cancel() funnels through voidOrder). All merged tables are
 * released → Free in the same transaction.
 *
 * Broadcasts on the PRIVATE channel `branch.{branch_id}.pos-events` so POS
 * staff terminals (pos-web, authenticated as SSO users) still see the table
 * released in realtime when the workstation LAN WebSocket is unreachable —
 * i.e. the "huỷ order nhưng bàn chưa được dọn khi WS không sync kịp" case.
 * On LAN, pos-web already gets an equivalent `order_voided` frame straight
 * from the workstation hub; this cloud event is the fallback for that same
 * signal, keyed by the same event name so the client handler is symmetric.
 *
 * Distinct from the KDS channel (`branch.{id}.kds-events`), whose auth
 * callback only admits Device tokens — SSO users are rejected there.
 */
class OrderVoided implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $releasedTableIds  Tables freed by the void.
     */
    public function __construct(
        public readonly CustomerOrder $order,
        public readonly array $releasedTableIds,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("branch.{$this->order->branch_id}.pos-events")];
    }

    public function broadcastAs(): string
    {
        return 'order_voided';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'status' => $this->order->status instanceof \BackedEnum
                ? $this->order->status->value
                : $this->order->status,
            'released_table_ids' => array_values($this->releasedTableIds),
            'voided_at' => $this->order->voided_at?->toISOString() ?? now()->toISOString(),
            'occurred_at' => now()->toISOString(),
        ];
    }
}
