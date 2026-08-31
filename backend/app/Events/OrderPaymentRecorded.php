<?php

namespace App\Events;

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by OrderPaymentService::create after any payment row is persisted —
 * not just the one that closes the order. The close-only OrderPaid event
 * (`order.paid`) still fires from OrderClosingService; this one covers every
 * intermediate partial payment so customer-web's dine-in payment view can
 * react in real time (refetch order → re-render the by-items panel so items
 * paid by a sibling guest at the kiosk become disabled without the customer
 * having to reload the page).
 *
 * Broadcasts on the same PUBLIC channel `customer.order.{id}` used by
 * OrderPaid — guest customer-web is unauthenticated and only knows the
 * order UUID v7. Payload is minimal (no PII, no item allocations) so the
 * client refetches the canonical state from /tables/{qr}/order rather than
 * trusting the broadcast payload.
 */
class OrderPaymentRecorded implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly CustomerOrder $order,
        public readonly OrderPayment $payment,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("customer.order.{$this->order->id}")];
    }

    public function broadcastAs(): string
    {
        return 'payment.recorded';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $paid = (float) $this->order->paid_amount;
        $total = (float) $this->order->total_amount;

        return [
            'order_id' => $this->order->id,
            'payment_id' => $this->payment->id,
            'paid_amount' => $paid,
            'remaining_amount' => max(0, $total - $paid),
            'recorded_at' => now()->toISOString(),
        ];
    }
}
