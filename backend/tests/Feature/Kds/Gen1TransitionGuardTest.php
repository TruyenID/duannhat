<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;

/**
 * Gen-1 (deprecated PATCH .../status) forward-transition guard (plan-028
 * test-gap audit).
 *
 * TESTS.md Phase 4 promises "gen-1 calls still subject to all gen-2 business
 * rules (decoupled handler, single rule path)". UpdateItemStatusTest already
 * pins the gen-1 → KDS_E001/E003/E004/E006 paths, but the *invalid-transition*
 * rule (KDS_E002) via the deprecated PATCH endpoint is untested — even though
 * gen-2 covers it exhaustively (MarkReady/MarkServed/MarkPreparing/RevertTest).
 *
 * These lock the redispatch: a status-skip through the gen-1 path hits the same
 * `assertForwardTransition` guard and returns **409 KDS_E002** (RFC 7807),
 * NOT the 422 that TESTS.md line 69's wording loosely implies.
 */
function setupGen1GuardFixture(string $itemStatus, array $itemExtra = []): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_gen1_guard_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create(array_merge([
        'customer_order_id' => $order->id,
        'status' => $itemStatus,
    ], $itemExtra));

    return [$device, $order, $item];
}

// ---------------------------------------------------------------------------
// gen-1 status=ready on a pending item (skips preparing) → 409 KDS_E002
// ---------------------------------------------------------------------------

it('gen-1 PATCH status=ready on a pending item returns 409 KDS_E002 (skip preparing)', function () {
    [$device, $order, $item] = setupGen1GuardFixture('pending');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-gen1-e002-skip-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'ready',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');

    // Rule fired before mutation — item stays pending.
    expect($item->refresh()->status->value)->toBe('pending');
});

// ---------------------------------------------------------------------------
// gen-1 status=served on a pending item (ready_at null) → 409 KDS_E002
// ---------------------------------------------------------------------------

it('gen-1 PATCH status=served on a pending item returns 409 KDS_E002 (no ready_at)', function () {
    [$device, $order, $item] = setupGen1GuardFixture('pending');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-gen1-e002-served-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'served',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');

    expect($item->refresh()->status->value)->toBe('pending');
});

// ---------------------------------------------------------------------------
// gen-1 status=preparing on an already-ready item (backward drag) → 409 KDS_E002
// ---------------------------------------------------------------------------

it('gen-1 PATCH status=preparing on a ready item returns 409 KDS_E002 (backward drag)', function () {
    [$device, $order, $item] = setupGen1GuardFixture('ready', [
        'ready_at' => now()->subMinutes(5),
    ]);

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-gen1-e002-back-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002');

    expect($item->refresh()->status->value)->toBe('ready');
});

// ---------------------------------------------------------------------------
// gen-1 KDS_E002 body is RFC 7807 problem+json (parity with gen-2)
// ---------------------------------------------------------------------------

it('gen-1 PATCH KDS_E002 renders the RFC 7807 problem body', function () {
    [$device, $order, $item] = setupGen1GuardFixture('pending');

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'idem-gen1-e002-shape-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'ready',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E002')
        ->assertJsonPath('status', 409)
        ->assertJsonPath('type', 'https://godx-tempo.dev/errors/kds/invalid-transition')
        ->assertJsonStructure(['type', 'title', 'status', 'code', 'detail', 'remediation']);
});
