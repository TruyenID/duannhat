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

function bumpAllFixture(): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_ba_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);

    return [$device, $order];
}

// ---------------------------------------------------------------------------
// 1. scope=pending: 3 pending + 1 preparing + 1 ready → 3 now preparing
// ---------------------------------------------------------------------------

it('bump-all: scope=pending advances all pending items to preparing', function () {
    [$device, $order] = bumpAllFixture();

    $pendingItems = CustomerOrderItem::factory()->count(3)->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);
    $preparingItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
    ]);
    $readyItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'ready',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-pending-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertOk();

    foreach ($pendingItems as $pi) {
        expect($pi->refresh()->status->value)->toBe('preparing');
    }
    expect($preparingItem->refresh()->status->value)->toBe('preparing'); // unchanged
    expect($readyItem->refresh()->status->value)->toBe('ready');         // unchanged
});

// ---------------------------------------------------------------------------
// 2. scope=preparing: 2 pending + 3 preparing → 2 pending + 3 ready
// ---------------------------------------------------------------------------

it('bump-all: scope=preparing advances all preparing items to ready', function () {
    [$device, $order] = bumpAllFixture();

    $pendingItems = CustomerOrderItem::factory()->count(2)->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);
    $preparingItems = CustomerOrderItem::factory()->count(3)->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-preparing-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'preparing'])
        ->assertOk();

    foreach ($pendingItems as $pi) {
        expect($pi->refresh()->status->value)->toBe('pending'); // unchanged
    }
    foreach ($preparingItems as $pi) {
        expect($pi->refresh()->status->value)->toBe('ready');
    }
});

// ---------------------------------------------------------------------------
// 3. Returns updated KdsOrderResource
// ---------------------------------------------------------------------------

it('bump-all: returns updated KdsOrderResource shape', function () {
    [$device, $order] = bumpAllFixture();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-shape-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending']);

    $response->assertOk()
        ->assertJsonStructure(['data' => [
            'id', 'order_code', 'order_type', 'guest_count', 'note',
            'opened_at', 'aging_minutes', 'is_late', 'priority',
            'pending_items_count', 'preparing_items_count', 'ready_items_count',
            'oldest_pending_age_minutes', 'can_bump_all', 'items',
        ]]);
});

// ---------------------------------------------------------------------------
// 4. scope=preparing sets ready_at on bumped items
// ---------------------------------------------------------------------------

it('bump-all: scope=preparing sets ready_at on items', function () {
    [$device, $order] = bumpAllFixture();

    $preparingItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
        'ready_at' => null,
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-readyat-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'preparing'])
        ->assertOk();

    expect($preparingItem->refresh()->ready_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 4b. scope=pending sets started_preparing_at on bumped items
// ---------------------------------------------------------------------------

it('bump-all: scope=pending sets started_preparing_at on items bumped pending→preparing', function () {
    [$device, $order] = bumpAllFixture();

    $pendingItems = CustomerOrderItem::factory()->count(2)->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
        'started_preparing_at' => null,
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-spa-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertOk();

    foreach ($pendingItems as $pi) {
        $pi->refresh();
        expect($pi->started_preparing_at)->not->toBeNull();
        expect($pi->started_preparing_at->diffInSeconds(now()))->toBeLessThan(5);
    }
});

// ---------------------------------------------------------------------------
// 5. Idempotency replay returns same body
// ---------------------------------------------------------------------------

it('bump-all: idempotency replay returns cached body', function () {
    [$device, $order] = bumpAllFixture();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    $first = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->json();

    $second = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->json();

    expect($second)->toEqual($first);
});

// ---------------------------------------------------------------------------
// 6. KDS_E001 if order Closed
// ---------------------------------------------------------------------------

it('bump-all: returns KDS_E001 if order is Closed', function () {
    [$device, $order] = bumpAllFixture();
    $order->update(['status' => CustomerOrderStatusEnum::Closed]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-e001-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E001');
});

// ---------------------------------------------------------------------------
// 7. KDS_E006 if different branch
// ---------------------------------------------------------------------------

it('bump-all: returns KDS_E006 if order is in a different branch', function () {
    [$device] = bumpAllFixture();

    $otherBranch = Branch::factory()->create();
    $foreignOrder = CustomerOrder::factory()->create([
        'branch_id' => $otherBranch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-e006-1')
        ->postJson("/api/v1/kds/orders/{$foreignOrder->id}/bump-all", ['scope' => 'pending'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'KDS_E006');
});

// ---------------------------------------------------------------------------
// 8. Returns 401 without token
// ---------------------------------------------------------------------------

it('bump-all: returns 401 without token', function () {
    [, $order] = bumpAllFixture();

    $this->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// 9. Returns 422 when Idempotency-Key missing
// ---------------------------------------------------------------------------

it('bump-all: returns 422 when Idempotency-Key missing', function () {
    [$device, $order] = bumpAllFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 10. Empty scope (no items match): returns 200 with unchanged order
// ---------------------------------------------------------------------------

it('bump-all: empty scope returns 200 with no-op (order unchanged)', function () {
    [$device, $order] = bumpAllFixture();

    // Only ready items — no pending items to bump
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'ready',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-empty-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertOk();

    // pending_items_count still 0, ready_items_count still 1
    expect($response->json('data.pending_items_count'))->toBe(0);
    expect($response->json('data.ready_items_count'))->toBe(1);
});

// ---------------------------------------------------------------------------
// 11. Invalid scope value → 422
// ---------------------------------------------------------------------------

it('bump-all: invalid scope returns 422', function () {
    [$device, $order] = bumpAllFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-invalid-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'served'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 13. KDS_E004: bump-all scope=preparing must honour the toppings-parent-ready
//     guard just like single mark-ready. A parent with an unready topping
//     fails the whole batch atomically (nothing advances).
// ---------------------------------------------------------------------------

it('bump-all: scope=preparing returns KDS_E004 when a parent has unready toppings', function () {
    [$device, $order] = bumpAllFixture();

    $blockedParent = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
    ]);
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $blockedParent->id,
        'status' => 'preparing', // not ready → blocks parent
    ]);

    $otherPreparing = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-e004-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'preparing'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E004');

    // Atomic: neither item advanced.
    expect($blockedParent->refresh()->status->value)->toBe('preparing');
    expect($otherPreparing->refresh()->status->value)->toBe('preparing');
});

it('bump-all: scope=preparing succeeds when all toppings are ready', function () {
    [$device, $order] = bumpAllFixture();

    $parent = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
    ]);
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $parent->id,
        'status' => 'ready',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-ba-e004-ok-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'preparing'])
        ->assertOk();

    expect($parent->refresh()->status->value)->toBe('ready');
});

// ---------------------------------------------------------------------------
// 12. FE↔BE CONTRACT: each OrderItemStatusChanged event broadcasts
//     idempotency_key = "{batchKey}:{itemId}". godx-kds useBumpAll pre-records
//     keys in that exact format for self-echo dedup; this test locks the
//     format so changing it server-side fails CI loudly.
// ---------------------------------------------------------------------------

it('bump-all broadcasts OrderItemStatusChanged with idempotency_key = "{batchKey}:{itemId}" per item', function () {
    [$device, $order] = bumpAllFixture();

    $pendingItems = CustomerOrderItem::factory()->count(3)->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    Event::fake([OrderItemStatusChanged::class]);

    $batchKey = 'ba-contract-'.uniqid();
    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', $batchKey)
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertOk();

    Event::assertDispatchedTimes(OrderItemStatusChanged::class, 3);
    foreach ($pendingItems as $item) {
        $expected = "{$batchKey}:{$item->id}";
        Event::assertDispatched(
            OrderItemStatusChanged::class,
            fn (OrderItemStatusChanged $e) => $e->idempotencyKey === $expected,
        );
    }
});
