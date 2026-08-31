<?php

namespace App\Events;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by CustomerOrderService::updateItem when an item's status changes.
 * Broadcasts on `branch.{branch_id}.kds-events` so all KDS devices in the
 * branch (and pos-web staff dashboards if they subscribe) see the change
 * in realtime.
 *
 * Idempotency key is included so clients can dedup self-echoes of bumps
 * they originated.
 */
class OrderItemStatusChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly CustomerOrder $order,
        public readonly CustomerOrderItem $item,
        public readonly string $previousStatus,
        public readonly string $idempotencyKey,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("branch.{$this->order->branch_id}.kds-events")];
    }

    public function broadcastAs(): string
    {
        return 'order_item.status_changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'item_id' => $this->item->id,
            'previous_status' => $this->previousStatus,
            'status' => $this->item->status instanceof \BackedEnum ? $this->item->status->value : $this->item->status,
            'served_at' => $this->item->served_at?->toISOString(),
            'voided_at' => $this->item->voided_at?->toISOString(),
            'idempotency_key' => $this->idempotencyKey,
            'occurred_at' => now()->toISOString(),
        ];
    }
}
