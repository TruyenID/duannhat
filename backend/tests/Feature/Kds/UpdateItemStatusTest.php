<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\OrderItemTopping;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

it('updates item status from pending to preparing', function () {
    [$device, $order, $item] = setupKdsBumpFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-bump-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');

    $item->refresh();
    expect($item->status->value)->toBe('preparing');
    expect($item->started_preparing_at)->not->toBeNull();
});

it('rejects bump from device in another branch', function () {
    [$device] = setupKdsBumpFixture();
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
        ->withHeader('Idempotency-Key', 'idem-cross-1')
        ->patchJson("/api/v1/kds/orders/{$foreignOrder->id}/items/{$foreignItem->id}/status", [
            'status' => 'preparing',
        ])
        ->assertForbidden();
});

it('rejects pending / voided status from KDS controller', function () {
    [$device, $order, $item] = setupKdsBumpFixture();
    $item->update(['status' => 'preparing']);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-bad-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'pending',
        ])
        ->assertStatus(422);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-bad-2')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'voided',
        ])
        ->assertStatus(422);
});

it('returns cached response on idempotency replay (same status)', function () {
    [$device, $order, $item] = setupKdsBumpFixture();

    $first = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-replay-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])->json();

    // Tamper the DB state to verify the replay returns cached data and does not re-execute.
    $item->update(['status' => 'served']);

    $second = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-replay-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])->json();

    expect($second)->toEqual($first);
    expect($second['data']['status'])->toBe('preparing');
});

it('rejects bump without Idempotency-Key header', function () {
    [$device, $order, $item] = setupKdsBumpFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertStatus(422);

    // Verify item state unchanged
    expect($item->refresh()->status->value)->toBe('pending');
});

function setupKdsBumpFixture(): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_bump_'.uniqid(),
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
// Deprecation headers
// ---------------------------------------------------------------------------

it('gen-1 PATCH includes Deprecation: true header', function () {
    [$device, $order, $item] = setupKdsBumpFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-dep-header-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertOk()
        ->assertHeader('Deprecation', 'true');
});

it('gen-1 PATCH includes Sunset header', function () {
    [$device, $order, $item] = setupKdsBumpFixture();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-dep-sunset-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertOk()
        ->assertHeader('Sunset', 'Sat, 12 Jul 2026 00:00:00 GMT');
});

it('gen-1 PATCH includes Link header pointing to mark-preparing successor', function () {
    [$device, $order, $item] = setupKdsBumpFixture();

    $response = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-dep-link-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertOk();

    expect($response->headers->get('Link'))->toContain('mark-preparing');
    expect($response->headers->get('Link'))->toContain('successor-version');
});

it('gen-1 PATCH Link header points to mark-ready for status=ready', function () {
    [$device, $order, $item] = setupKdsBumpFixture();
    $item->update(['status' => 'preparing']);

    $response = $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-dep-link-ready-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'ready',
        ])
        ->assertOk();

    expect($response->headers->get('Link'))->toContain('mark-ready');
});

// ---------------------------------------------------------------------------
// Business rules now enforced through gen-2 re-dispatch
// ---------------------------------------------------------------------------

it('gen-1 PATCH applies anti-misclick rule (KDS_E003) for served too soon', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_e003_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'ready',
        'ready_at' => now()->subSeconds(10),
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-gen1-e003-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'served',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E003');
});

it('gen-1 PATCH applies toppings dependency rule (KDS_E004)', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_e004_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'preparing',
    ]);
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'status' => 'preparing',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-gen1-e004-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'ready',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E004');
});

it('gen-1 PATCH enforces KDS_E001 for finalized (Voided) order', function () {
    [$device, $order, $item] = setupKdsBumpFixture();
    $order->update(['status' => CustomerOrderStatusEnum::Voided]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-gen1-e001-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E001');
});

it('gen-1 PATCH enforces KDS_E006 for cross-branch order', function () {
    [$device] = setupKdsBumpFixture();
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
        ->withHeader('Idempotency-Key', 'idem-gen1-e006-1')
        ->patchJson("/api/v1/kds/orders/{$foreignOrder->id}/items/{$foreignItem->id}/status", [
            'status' => 'preparing',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'KDS_E006');
});
