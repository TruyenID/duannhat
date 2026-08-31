<?php

namespace App\Events;

use App\Models\CustomerOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by OrderClosingService::close when an order finishes payment
 * (paid_amount >= total_amount → status flipped to Closed).
 *
 * Broadcasts on PUBLIC channel `customer.order.{id}` — guest customer-web
 * không authenticate, chỉ giữ order id (UUID v7 unguessable) trong
 * localStorage. Payload tối thiểu (không PII) để tránh leak nếu ID lộ.
 *
 * Use case: customer-web đang ở màn "Đang chờ thanh toán" với QR code,
 * khi kiosk pay xong nhận event này → swap UI sang "Thanh toán thành công".
 *
 * `ShouldRescue` (#1208): đây là `ShouldBroadcastNow`, tức đẩy đồng bộ ngay
 * trong request đóng đơn. Không có nó thì lần đầu bật Reverb cũng là lần đầu
 * khách trả tiền xong nhận HTTP 500 — tiền đã ghi, đơn đã closed, chỉ mỗi cú
 * broadcast hỏng mà làm hỏng cả response. `rescue()` vẫn report exception nên
 * sự cố không bị nuốt, chỉ là không còn kéo theo đường tiền.
 */
class OrderPaid implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly CustomerOrder $order) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("customer.order.{$this->order->id}")];
    }

    public function broadcastAs(): string
    {
        return 'order.paid';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_code' => $this->order->order_code,
            'paid_at' => $this->order->closed_at?->toISOString() ?? now()->toISOString(),
        ];
    }
}
