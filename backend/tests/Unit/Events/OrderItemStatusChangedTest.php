<?php

use App\Events\OrderItemStatusChanged;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('broadcasts on branch-scoped kds-events channel', function () {
    $order = CustomerOrder::factory()->create();
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id]);

    $event = new OrderItemStatusChanged($order, $item, 'pending', 'idem-key-123');
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe("private-branch.{$order->branch_id}.kds-events");
});

it('payload includes idempotency key for client dedup', function () {
    $order = CustomerOrder::factory()->create();
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
    ]);

    $event = new OrderItemStatusChanged($order, $item, 'pending', 'idem-key-xyz');
    $payload = $event->broadcastWith();

    expect($payload)
        ->toHaveKey('order_id', $order->id)
        ->toHaveKey('item_id', $item->id)
        ->toHaveKey('previous_status', 'pending')
        ->toHaveKey('status', 'preparing')
        ->toHaveKey('idempotency_key', 'idem-key-xyz')
        ->toHaveKey('occurred_at');
});
