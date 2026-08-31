<?php

use App\Events\OrderItemStatusChanged;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function markServedFixture(int $readySecondsAgo = 60): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_ms_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'ready',
        'ready_at' => now()->subSeconds($readySecondsAgo),
    ]);

    return [$device, $order, $item];
}

// ---------------------------------------------------------------------------
// 1. Happy path: ready item (60s ago) → 200 + KdsItemResource shape
// ---------------------------------------------------------------------------

it('mark-served: happy path returns 200 with KdsItemResource shape', function () {
    [$device, $order, $item] = markServedFixture(60);

    $response = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-happy-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served");

    $response->assertOk()
        ->assertJsonPath('data.status', 'served')
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

it('mark-served: returns 401 without token', function () {
    [, $order, $item] = markServedFixture();

    $this->withHeader('Idempotency-Key', 'idem-ms-auth-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// 3. Returns 422 when Idempotency-Key missing
// ---------------------------------------------------------------------------

it('mark-served: returns 422 when Idempotency-Key missing', function () {
    [$device, $order, $item] = markServedFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 4. Idempotency replay
// ---------------------------------------------------------------------------

it('mark-served: returns idempotency replay on second call with same key', function () {
    [$device, $order, $item] = markServedFixture(60);

    $first = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->json();

    // State irrelevant after cache hit
    $second = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->json();

    expect($second)->toEqual($first);
    expect($second['data']['status'])->toBe('served');
});

// ---------------------------------------------------------------------------
// 5. KDS_E001 when order Closed
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E001 when order is Closed', function () {
    [$device, $order, $item] = markServedFixture();
    $order->update(['status' => CustomerOrderStatusEnum::Closed]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-e001-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E001');
});

// ---------------------------------------------------------------------------
// 6. KDS_E006 when order in different branch
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E006 when order is in a different branch', function () {
    [$device] = markServedFixture();

    $otherBranch = Branch::factory()->create();
    $foreignOrder = CustomerOrder::factory()->create([
        'branch_id' => $otherBranch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $foreignItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $foreignOrder->id,
        'status' => 'ready',
        'ready_at' => now()->subSeconds(60),
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-e006-1')
        ->postJson("/api/v1/kds/orders/{$foreignOrder->id}/items/{$foreignItem->id}/mark-served")
        ->assertStatus(403)
        ->assertJsonPath('code', 'KDS_E006');
});

// ---------------------------------------------------------------------------
// 7. KDS_E007 when item not in order
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E007 when item is not in order', function () {
    [$device, $order] = markServedFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-e007-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/non-existent-item-id/mark-served")
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 8. KDS_E007 cross-order: item belongs to different order in same branch
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E007 when item belongs to different order in same branch', function () {
    [$device, $order1] = markServedFixture();

    $order2 = CustomerOrder::factory()->create([
        'branch_id' => $device->branch_id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    // ready_at set so KDS_E002 is never triggered before KDS_E007
    $itemOnOrder2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'status' => 'ready',
        'ready_at' => now()->subSeconds(60),
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-cross-order-1')
        ->postJson("/api/v1/kds/orders/{$order1->id}/items/{$itemOnOrder2->id}/mark-served")
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 10. KDS_E002 when item not ready (ready_at is null)
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E002 when item has no ready_at', function () {
    [$device, $order] = markServedFixture();

    // Create item without ready_at set
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'ready',
        'ready_at' => null,
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-e002-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');
});

// ---------------------------------------------------------------------------
// 11. KDS_E003 anti-misclick — less than 30s after ready
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E003 when item was readied less than 30s ago', function () {
    [$device, $order, $item] = markServedFixture(15); // only 15s ago

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-e003-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E003');
});

// ---------------------------------------------------------------------------
// 12. KDS_E003 passes after 30s using Carbon::setTestNow
// ---------------------------------------------------------------------------

it('mark-served: passes after 30s elapsed since ready', function () {
    [$device, $order, $item] = markServedFixture(0); // just readied

    // Travel 31 seconds forward
    Carbon::setTestNow(now()->addSeconds(31));

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-time-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertOk()
        ->assertJsonPath('data.status', 'served');

    Carbon::setTestNow(); // reset
});

// ---------------------------------------------------------------------------
// 13. KDS_E005 throttle
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E005 on second call within 3s with different key', function () {
    [$device, $order, $item] = markServedFixture(60);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-throttle-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertOk();

    // Revert for next call to not fail on transition
    $item->update(['status' => 'ready', 'ready_at' => now()->subSeconds(60)]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-throttle-2')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertStatus(429)
        ->assertJsonPath('code', 'KDS_E005');
});

// ---------------------------------------------------------------------------
// 15. Forward-only: a preparing item with a stale ready_at cannot slip to
//     served (the revert→re-preparing gap). Must return KDS_E002.
// ---------------------------------------------------------------------------

it('mark-served: returns KDS_E002 when item is preparing despite a stale ready_at', function () {
    [$device, $order] = markServedFixture();

    // Item reverted back to preparing but still carries a historical ready_at
    // well past the 30s anti-misclick window.
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
        'ready_at' => now()->subMinutes(5),
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-e002-prep-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');

    expect($item->refresh()->status->value)->toBe('preparing');
});

// ---------------------------------------------------------------------------
// 14. Event OrderItemStatusChanged dispatched
// ---------------------------------------------------------------------------

it('mark-served: dispatches OrderItemStatusChanged event', function () {
    Event::fake([OrderItemStatusChanged::class]);

    [$device, $order, $item] = markServedFixture(60);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ms-event-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertOk();

    Event::assertDispatched(OrderItemStatusChanged::class, function ($event) use ($item) {
        return $event->item->id === $item->id;
    });
});
