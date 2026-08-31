<?php

use App\Events\OrderItemStatusChanged;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Facades\Event;

it('dispatches OrderItemStatusChanged on status change', function () {
    Event::fake([OrderItemStatusChanged::class]);

    $order = CustomerOrder::factory()->create(['status' => 'open']);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    app(CustomerOrderService::class)->updateItem($order, $item->id, [
        'status' => 'preparing',
        '_idempotency_key' => 'idem-abc',
    ]);

    Event::assertDispatched(OrderItemStatusChanged::class, function ($e) use ($item) {
        return $e->item->id === $item->id
            && $e->previousStatus === 'pending'
            && $e->idempotencyKey === 'idem-abc';
    });
});

it('does not dispatch when status unchanged', function () {
    Event::fake([OrderItemStatusChanged::class]);

    $order = CustomerOrder::factory()->create(['status' => 'open']);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
        'quantity' => 1,
    ]);

    app(CustomerOrderService::class)->updateItem($order, $item->id, [
        'quantity' => 2,
    ]);

    Event::assertNotDispatched(OrderItemStatusChanged::class);
});
