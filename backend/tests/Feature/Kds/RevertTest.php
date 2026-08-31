<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function revertFixture(string $itemStatus = 'preparing'): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_rv_'.uniqid(),
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
// 1. Happy path: revert preparing → pending
// ---------------------------------------------------------------------------

it('revert: preparing → pending succeeds (200)', function () {
    [$device, $order, $item] = revertFixture('preparing');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-pp-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'pending'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect($item->refresh()->status->value)->toBe('pending');
});

// ---------------------------------------------------------------------------
// 2. Happy path: revert ready → preparing
// ---------------------------------------------------------------------------

it('revert: ready → preparing succeeds (200)', function () {
    [$device, $order, $item] = revertFixture('ready');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-rp-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'preparing'])
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');

    expect($item->refresh()->status->value)->toBe('preparing');
});

// ---------------------------------------------------------------------------
// 3. KDS_E002: revert from served is terminal
// ---------------------------------------------------------------------------

it('revert: from served returns KDS_E002 (served is terminal)', function () {
    [$device, $order, $item] = revertFixture('served');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-served-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'preparing'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');
});

// ---------------------------------------------------------------------------
// 4. KDS_E002: revert forward (preparing → ready) is rejected
// ---------------------------------------------------------------------------

it('revert: forward transition (preparing → ready) returns KDS_E002', function () {
    [$device, $order, $item] = revertFixture('preparing');

    // 'ready' is not in valid `to` values — this should be caught by validation first (422)
    // But if validation catches it, 422 is correct; if business logic, 409 is correct.
    // Here `ready` is not in the allowed `to` values ['pending','preparing'], so 422.
    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-fwd-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'ready'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 5. KDS_E002: revert same level (pending → pending)
// ---------------------------------------------------------------------------

it('revert: same-level revert (pending → pending) returns KDS_E002', function () {
    [$device, $order, $item] = revertFixture('pending');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-same-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'pending'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');
});

// ---------------------------------------------------------------------------
// 6. Invalid `to` value → 422 validation error
// ---------------------------------------------------------------------------

it('revert: invalid `to` value returns 422', function () {
    [$device, $order, $item] = revertFixture('preparing');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-invalid-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'served'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 7. Returns 401 without token
// ---------------------------------------------------------------------------

it('revert: returns 401 without token', function () {
    [, $order, $item] = revertFixture();

    $this->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'pending'])
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// 8. Returns 422 when Idempotency-Key missing
// ---------------------------------------------------------------------------

it('revert: returns 422 when Idempotency-Key missing', function () {
    [$device, $order, $item] = revertFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'pending'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// 9. KDS_E006 when order in different branch
// ---------------------------------------------------------------------------

it('revert: returns KDS_E006 when order is in a different branch', function () {
    [$device] = revertFixture();

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
        ->withHeader('Idempotency-Key', 'idem-rv-e006-1')
        ->postJson("/api/v1/kds/orders/{$foreignOrder->id}/items/{$foreignItem->id}/revert", ['to' => 'pending'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'KDS_E006');
});

// ---------------------------------------------------------------------------
// 10. KDS_E007 when item not in order
// ---------------------------------------------------------------------------

it('revert: returns KDS_E007 when item is not in order', function () {
    [$device, $order] = revertFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-e007-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/non-existent-item-id/revert", ['to' => 'pending'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 11. KDS_E007 cross-order: item belongs to different order in same branch
// ---------------------------------------------------------------------------

it('revert: returns KDS_E007 when item belongs to different order in same branch', function () {
    [$device, $order1] = revertFixture();

    $order2 = CustomerOrder::factory()->create([
        'branch_id' => $device->branch_id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $itemOnOrder2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'status' => 'preparing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-cross-order-1')
        ->postJson("/api/v1/kds/orders/{$order1->id}/items/{$itemOnOrder2->id}/revert", ['to' => 'pending'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'KDS_E007');
});

// ---------------------------------------------------------------------------
// 12. Idempotency replay
// ---------------------------------------------------------------------------

it('revert: idempotency replay returns cached body', function () {
    [$device, $order, $item] = revertFixture('ready');

    $first = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'preparing'])
        ->json();

    // Advance item further
    $item->update(['status' => 'served']);

    $second = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-rv-replay-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'preparing'])
        ->json();

    expect($second)->toEqual($first);
    expect($second['data']['status'])->toBe('preparing');
});
