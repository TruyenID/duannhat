<?php

/**
 * plan-034 audit (test-gap MEDIUM) — no test pinned the realtime contract that
 * customer-web's useTableSessionRealtime hook depends on:
 *   - which channel each event broadcasts on,
 *   - the broadcastAs() event names the JS listener binds to,
 *   - the broadcastWith() payload shape/values,
 *   - and the tenant-safety branch: an order WITHOUT a table_session_id
 *     (takeaway / legacy) must NOT broadcast at all — otherwise a stray public
 *     `table-session.` channel with an empty id would leak.
 *
 * These are the private-channel-per-session guarantees from README §In-scope 4
 * and Phase 6.
 */

use App\Events\OrderEditingEnded;
use App\Events\OrderEditingStarted;
use App\Events\OrderItemAdded;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\TableSession;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
});

/** An order pinned to a fresh open session, with $itemCount served lines. */
function broadcastOrder(int $itemCount = 2, float $subtotal = 1000, float $total = 1200): CustomerOrder
{
    $table = Table::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => 'occupied',
        'is_active' => true,
        'qr_token' => (string) Str::uuid(),
    ]);
    $session = TableSession::create([
        'id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'table_id' => $table->id,
        'status' => TableSession::STATUS_OPEN,
        'opened_at' => now(),
    ]);
    $order = CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => CustomerOrderStatusEnum::Open->value,
        'table_session_id' => $session->id,
        'subtotal' => $subtotal,
        'total_amount' => $total,
    ]);
    for ($i = 0; $i < $itemCount; $i++) {
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => ProductSku::factory()->create()->id,
            'quantity' => 1,
            'status' => 'pending',
        ]);
    }

    return $order->fresh();
}

/** A session-less takeaway order (no table_session_id). */
function sessionlessOrder(): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => CustomerOrderStatusEnum::Open->value,
        'table_session_id' => null,
    ]);
}

it('OrderItemAdded broadcasts on the per-session channel with the item-added name', function () {
    $order = broadcastOrder();
    $event = new OrderItemAdded($order);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(Channel::class);
    expect($channels[0]->name)->toBe("table-session.{$order->table_session_id}");
    expect($event->broadcastAs())->toBe('order.item-added');
});

it('OrderItemAdded payload carries a live items_count, subtotal and total', function () {
    $order = broadcastOrder(itemCount: 3, subtotal: 900, total: 1080);
    $payload = (new OrderItemAdded($order))->broadcastWith();

    expect($payload['order_id'])->toBe($order->id);
    expect($payload['items_count'])->toBe(3);
    expect($payload['subtotal'])->toBe(900.0);
    expect($payload['total'])->toBe(1080.0);
});

it('OrderItemAdded does NOT broadcast for a session-less (takeaway) order', function () {
    $event = new OrderItemAdded(sessionlessOrder());

    // Empty channel list → Reverb ships nothing, no leaked `table-session.`
    // channel with an empty id.
    expect($event->broadcastOn())->toBe([]);
});

it('OrderEditingStarted broadcasts on the session channel with the editing-started name + timestamp', function () {
    $order = broadcastOrder();
    $order->forceFill(['editing_by_staff_at' => now()])->save();
    $event = new OrderEditingStarted($order->fresh());

    $channels = $event->broadcastOn();
    expect($channels[0]->name)->toBe("table-session.{$order->table_session_id}");
    expect($event->broadcastAs())->toBe('order.editing-started');

    $payload = $event->broadcastWith();
    expect($payload['order_id'])->toBe($order->id);
    expect($payload['editing_by_staff_at'])->not->toBeNull();
});

it('OrderEditingEnded broadcasts on the session channel with the editing-ended name', function () {
    $order = broadcastOrder();
    $event = new OrderEditingEnded($order);

    expect($event->broadcastOn()[0]->name)->toBe("table-session.{$order->table_session_id}");
    expect($event->broadcastAs())->toBe('order.editing-ended');
    expect($event->broadcastWith())->toBe(['order_id' => $order->id]);
});

it('editing events do NOT broadcast for a session-less order', function () {
    $order = sessionlessOrder();

    expect((new OrderEditingStarted($order))->broadcastOn())->toBe([]);
    expect((new OrderEditingEnded($order))->broadcastOn())->toBe([]);
});
