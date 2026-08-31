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

function operationRouteFixture(): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_routes_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    return [$device, $order, $item];
}

// ---------------------------------------------------------------------------
// Route resolvable (not 404)
// ---------------------------------------------------------------------------

it('mark-preparing route is resolvable (not 404)', function () {
    [$device, $order, $item] = operationRouteFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-route-mp-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertStatus(200);
});

it('mark-ready route is resolvable (not 404)', function () {
    [$device, $order, $item] = operationRouteFixture();
    $item->update(['status' => 'preparing']);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-route-mr-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertStatus(200);
});

it('mark-served route is resolvable (not 404)', function () {
    [$device, $order, $item] = operationRouteFixture();
    $item->update(['status' => 'ready', 'ready_at' => now()->subSeconds(60)]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-route-ms-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertStatus(200);
});

it('revert route is resolvable (not 404)', function () {
    [$device, $order, $item] = operationRouteFixture();
    $item->update(['status' => 'preparing']);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-route-rv-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'pending'])
        ->assertStatus(200);
});

it('bump-all route is resolvable (not 404)', function () {
    [$device, $order] = operationRouteFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-route-ba-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertStatus(200);
});

// ---------------------------------------------------------------------------
// Auth middleware applied (401 without device token)
// ---------------------------------------------------------------------------

it('mark-preparing returns 401 without token', function () {
    [, $order, $item] = operationRouteFixture();

    $this->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertUnauthorized();
});

it('mark-ready returns 401 without token', function () {
    [, $order, $item] = operationRouteFixture();

    $this->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertUnauthorized();
});

it('mark-served returns 401 without token', function () {
    [, $order, $item] = operationRouteFixture();

    $this->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertUnauthorized();
});

it('revert returns 401 without token', function () {
    [, $order, $item] = operationRouteFixture();

    $this->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert")
        ->assertUnauthorized();
});

it('bump-all returns 401 without token', function () {
    [, $order] = operationRouteFixture();

    $this->postJson("/api/v1/kds/orders/{$order->id}/bump-all")
        ->assertUnauthorized();
});
