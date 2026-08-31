<?php

use App\Events\OrderItemStatusChanged;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\OrderItemTopping;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Facades\Event;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function markReadyFixture(string $itemStatus = 'preparing'): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_mr_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => $itemStatus,
    ]);

    return [$device, $order, $item];
}

// ---------------------------------------------------------------------------
// 1. Happy path: preparing item → 200 + KdsItemResource shape
// ---------------------------------------------------------------------------

it('mark-ready: happy path returns 200 with KdsItemResource shape', function () {
    [$device, $order, $item] = markReadyFixture();

    $response = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-happy-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready");

    $response->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonStructure(['data' => [
            'id', 'menu_item_name', 'quantity', 'status', 'note',
            'aging_minutes', 'time_in_current_status_seconds',
            'is_blocked_by_toppings', 'allowed_transitions',
            'started_preparing_at', 'ready_at', 'served_at', 'toppings',
        ]]);
});

// ---------------------------------------------------------------------------
// 2. Returns 401 without token
// ---------------------------------------------------------------------------

it('mark-ready: returns 401 without token', function () {
    [, $order, $item] = markReadyFixture();

    $this->withHeader('Idempotency-Key', 'idem-mr-auth-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// 3. Returns 422 when Idempotency-Key missing
// ---------------------------------------------------------------------------

it('mark-ready: returns 422 when Idempotency-Key missing', function () {
    [$device, $order, $item] = markReadyFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 4. Idempotency replay
// ---------------------------------------------------------------------------

it('mark-ready: returns idempotency replay on second call with same key', function () {
    [$device, $order, $item] = markReadyFixture();

    $first = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->json();

    $item->update(['status' => 'served']);

    $second = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->json();

    expect($second)->toEqual($first);
    expect($second['data']['status'])->toBe('ready');
});

// ---------------------------------------------------------------------------
// 5. KDS_E001 when order Closed
// ---------------------------------------------------------------------------

it('mark-ready: returns KDS_E001 when order is Closed', function () {
    [$device, $order, $item] = markReadyFixture();
    $order->update(['status' => CustomerOrderStatusEnum::Closed]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-e001-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E001');
});

// ---------------------------------------------------------------------------
// 6. KDS_E006 when order in different branch
// ---------------------------------------------------------------------------

it('mark-ready: returns KDS_E006 when order is in a different branch', function () {
    [$device] = markReadyFixture();

    $otherBranch = Branch::factory()->create();
    $foreignOrder = CustomerOrder::factory()->create([
        'branch_id' => $otherBranch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $foreignItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $foreignOrder->id,
        'status' => 'preparing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-e006-1')
        ->postJson("/api/v1/kds/orders/{$foreignOrder->id}/items/{$foreignItem->id}/mark-ready")
        ->assertStatus(403)
        ->assertJsonPath('code', 'KDS_E006');
});

// ---------------------------------------------------------------------------
// 7. KDS_E007 when item not in order
// ---------------------------------------------------------------------------

it('mark-ready: returns KDS_E007 when item is not in order', function () {
    [$device, $order] = markReadyFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-e007-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/non-existent-item-id/mark-ready")
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 8. KDS_E007 cross-order: item belongs to different order in same branch
// ---------------------------------------------------------------------------

it('mark-ready: returns KDS_E007 when item belongs to different order in same branch', function () {
    [$device, $order1] = markReadyFixture();

    $order2 = CustomerOrder::factory()->create([
        'branch_id' => $device->branch_id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    // No toppings so KDS_E004 is never triggered before KDS_E007
    $itemOnOrder2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'status' => 'preparing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-cross-order-1')
        ->postJson("/api/v1/kds/orders/{$order1->id}/items/{$itemOnOrder2->id}/mark-ready")
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 10. KDS_E005 throttle
// ---------------------------------------------------------------------------

it('mark-ready: returns KDS_E005 on second call within 3s with different key', function () {
    [$device, $order, $item] = markReadyFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-throttle-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertOk();

    $item->update(['status' => 'preparing']);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-throttle-2')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertStatus(429)
        ->assertJsonPath('code', 'KDS_E005');
});

// ---------------------------------------------------------------------------
// 11. KDS_E004 toppings dependency — item with unready topping
// ---------------------------------------------------------------------------

it('mark-ready: returns KDS_E004 when parent has unready toppings', function () {
    [$device, $order, $item] = markReadyFixture();

    // Attach an unready topping to the item
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'status' => 'preparing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-e004-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E004');
});

// ---------------------------------------------------------------------------
// 12. ready_at is set in DB after mark-ready
// ---------------------------------------------------------------------------

it('mark-ready: sets ready_at timestamp in DB', function () {
    [$device, $order, $item] = markReadyFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-readyat-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertOk();

    $refreshed = $item->refresh();
    expect($refreshed->status->value)->toBe('ready');
    expect($refreshed->ready_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 14. Forward-only: reject KDS_E002 when item is not preparing
// ---------------------------------------------------------------------------

it('mark-ready: returns KDS_E002 when item is still pending (skips preparing)', function () {
    [$device, $order, $item] = markReadyFixture('pending');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-e002-skip-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');

    expect($item->refresh()->status->value)->toBe('pending');
});

it('mark-ready: returns KDS_E002 when item is already served (backward drag)', function () {
    [$device, $order, $item] = markReadyFixture('served');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-e002-back-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');

    expect($item->refresh()->status->value)->toBe('served');
});

// ---------------------------------------------------------------------------
// 15. ready_at is first-write-wins (COALESCE) — matches workstation LAN mirror
// ---------------------------------------------------------------------------

it('mark-ready: preserves an existing ready_at (first-write-wins, not last)', function () {
    [$device, $order, $item] = markReadyFixture('preparing');

    // Simulate a historical ready anchor left by a prior ready→revert cycle.
    $anchor = now()->subMinutes(5)->startOfSecond();
    $item->update(['ready_at' => $anchor]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-fww-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertOk()
        ->assertJsonPath('data.status', 'ready');

    // ready_at must remain the original anchor, not be overwritten with now().
    expect($item->refresh()->ready_at->equalTo($anchor))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 13. Event OrderItemStatusChanged dispatched
// ---------------------------------------------------------------------------

it('mark-ready: dispatches OrderItemStatusChanged event', function () {
    Event::fake([OrderItemStatusChanged::class]);

    [$device, $order, $item] = markReadyFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mr-event-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertOk();

    Event::assertDispatched(OrderItemStatusChanged::class, function ($event) use ($item) {
        return $event->item->id === $item->id;
    });
});
