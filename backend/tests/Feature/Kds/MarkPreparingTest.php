<?php

use App\Events\OrderItemStatusChanged;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Facades\Event;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function markPreparingFixture(string $itemStatus = 'pending'): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_mp_'.uniqid(),
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
// 1. Happy path: pending item → 200 + KdsItemResource shape
// ---------------------------------------------------------------------------

it('mark-preparing: happy path returns 200 with KdsItemResource shape', function () {
    [$device, $order, $item] = markPreparingFixture();

    $response = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-happy-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing");

    $response->assertOk()
        ->assertJsonPath('data.status', 'preparing')
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

it('mark-preparing: returns 401 without token', function () {
    [, $order, $item] = markPreparingFixture();

    $this->withHeader('Idempotency-Key', 'idem-mp-auth-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// 3. Returns 422 when Idempotency-Key missing
// ---------------------------------------------------------------------------

it('mark-preparing: returns 422 when Idempotency-Key missing', function () {
    [$device, $order, $item] = markPreparingFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 4. Idempotency replay: second call with same key returns cached body
// ---------------------------------------------------------------------------

it('mark-preparing: returns idempotency replay on second call with same key', function () {
    [$device, $order, $item] = markPreparingFixture();

    $first = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->json();

    // Advance item further to detect false advance on replay
    $item->update(['status' => 'ready']);

    $second = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->json();

    expect($second)->toEqual($first);
    // Replay returns cached body — no further advancement
    expect($second['data']['status'])->toBe('preparing');
});

// ---------------------------------------------------------------------------
// 5. KDS_E001 when order Closed
// ---------------------------------------------------------------------------

it('mark-preparing: returns KDS_E001 when order is Closed', function () {
    [$device, $order, $item] = markPreparingFixture();
    $order->update(['status' => CustomerOrderStatusEnum::Closed]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-e001-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E001');
});

// ---------------------------------------------------------------------------
// 6. KDS_E006 when order in different branch
// ---------------------------------------------------------------------------

it('mark-preparing: returns KDS_E006 when order is in a different branch', function () {
    [$device] = markPreparingFixture();

    $otherBranch = Branch::factory()->create();
    $foreignOrder = CustomerOrder::factory()->create([
        'branch_id' => $otherBranch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $foreignItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $foreignOrder->id,
        'status' => 'pending',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-e006-1')
        ->postJson("/api/v1/kds/orders/{$foreignOrder->id}/items/{$foreignItem->id}/mark-preparing")
        ->assertStatus(403)
        ->assertJsonPath('code', 'KDS_E006');
});

// ---------------------------------------------------------------------------
// 7. KDS_E007 when item not in order
// ---------------------------------------------------------------------------

it('mark-preparing: returns KDS_E007 when item is not in order', function () {
    [$device, $order] = markPreparingFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-e007-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/non-existent-item-id/mark-preparing")
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 8. KDS_E007 cross-order: item belongs to different order in same branch
// ---------------------------------------------------------------------------

it('mark-preparing: returns KDS_E007 when item belongs to different order in same branch', function () {
    [$device, $order1] = markPreparingFixture();

    $order2 = CustomerOrder::factory()->create([
        'branch_id' => $device->branch_id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $itemOnOrder2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'status' => 'pending',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-cross-order-1')
        ->postJson("/api/v1/kds/orders/{$order1->id}/items/{$itemOnOrder2->id}/mark-preparing")
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 10. KDS_E005 throttle: second call within 3s without idempotency
// ---------------------------------------------------------------------------

it('mark-preparing: returns KDS_E005 on second call within 3s with different key', function () {
    [$device, $order, $item] = markPreparingFixture();

    // First call succeeds
    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-throttle-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertOk();

    // Revert to pending so the status transition doesn't block
    $item->update(['status' => 'pending']);

    // Second call with different idempotency key triggers throttle
    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-throttle-2')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertStatus(429)
        ->assertJsonPath('code', 'KDS_E005');
});

// ---------------------------------------------------------------------------
// 11. Item status changes to preparing in DB
// ---------------------------------------------------------------------------

it('mark-preparing: item status changes to preparing in DB', function () {
    [$device, $order, $item] = markPreparingFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-db-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertOk();

    expect($item->refresh()->status->value)->toBe('preparing');
});

// ---------------------------------------------------------------------------
// 13. Sets started_preparing_at when item transitions to preparing
// ---------------------------------------------------------------------------

it('mark-preparing: sets started_preparing_at when item transitions to preparing', function () {
    [$device, $order, $item] = markPreparingFixture();

    expect($item->started_preparing_at)->toBeNull();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-spa-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertOk();

    $item->refresh();
    expect($item->started_preparing_at)->not->toBeNull();
    expect($item->started_preparing_at->diffInSeconds(now()))->toBeLessThan(5);
});

// ---------------------------------------------------------------------------
// 14. Forward-only: reject KDS_E002 when item already past pending
// ---------------------------------------------------------------------------

it('mark-preparing: returns KDS_E002 when item is already ready (backward drag)', function () {
    [$device, $order, $item] = markPreparingFixture('ready');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-e002-back-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');

    // Item must not have been dragged backward to preparing.
    expect($item->refresh()->status->value)->toBe('ready');
});

it('mark-preparing: returns KDS_E002 when item is served (resurrect)', function () {
    [$device, $order, $item] = markPreparingFixture('served');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-e002-served-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');
});

// ---------------------------------------------------------------------------
// 12. Event OrderItemStatusChanged dispatched
// ---------------------------------------------------------------------------

it('mark-preparing: dispatches OrderItemStatusChanged event', function () {
    Event::fake([OrderItemStatusChanged::class]);

    [$device, $order, $item] = markPreparingFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-mp-event-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertOk();

    Event::assertDispatched(OrderItemStatusChanged::class, function ($event) use ($item) {
        return $event->item->id === $item->id;
    });
});
