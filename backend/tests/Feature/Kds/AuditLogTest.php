<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Install a Monolog TestHandler on the kds-bumps channel and return it.
 * Call this inside a test AFTER any factory creates that could also trigger
 * logging, to keep the captured records clean.
 */
function tapKdsBumpsChannel(): TestHandler
{
    $handler = new TestHandler;
    /** @var Logger $channel */
    $channel = Log::channel('kds-bumps');
    $channel->getLogger()->pushHandler($handler);

    return $handler;
}

function auditLogFixture(string $itemStatus = 'pending'): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_audit_'.uniqid(),
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
// 1. markPreparing writes kds_direct_mark_preparing
// ---------------------------------------------------------------------------

it('markPreparing writes kds_direct_mark_preparing audit log', function () {
    [$device, $order, $item] = auditLogFixture('pending');
    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-mp-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertOk();

    expect($handler->hasInfoRecords())->toBeTrue();
    $record = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_mark_preparing');
    expect($record)->not->toBeNull();
    expect($record['context']['op'])->toBe('kds_direct_mark_preparing');
    expect($record['context']['order_id'])->toBe($order->id);
    expect($record['context']['item_id'])->toBe($item->id);
    expect($record['context']['device_id'])->toBe($device->id);
    expect($record['context']['idempotency_key'])->toBe('audit-mp-1');
});

// ---------------------------------------------------------------------------
// 2. markReady writes kds_direct_mark_ready
// ---------------------------------------------------------------------------

it('markReady writes kds_direct_mark_ready audit log', function () {
    [$device, $order, $item] = auditLogFixture('preparing');
    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-mready-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertOk();

    $record = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_mark_ready');
    expect($record)->not->toBeNull();
    expect($record['context']['op'])->toBe('kds_direct_mark_ready');
    expect($record['context']['order_id'])->toBe($order->id);
    expect($record['context']['item_id'])->toBe($item->id);
    expect($record['context']['device_id'])->toBe($device->id);
    expect($record['context']['idempotency_key'])->toBe('audit-mready-1');
});

// ---------------------------------------------------------------------------
// 3. markServed writes kds_direct_mark_served
// ---------------------------------------------------------------------------

it('markServed writes kds_direct_mark_served audit log', function () {
    [$device, $order, $item] = auditLogFixture('ready');
    $item->update(['ready_at' => now()->subSeconds(60)]);
    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-mserved-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-served")
        ->assertOk();

    $record = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_mark_served');
    expect($record)->not->toBeNull();
    expect($record['context']['op'])->toBe('kds_direct_mark_served');
    expect($record['context']['order_id'])->toBe($order->id);
    expect($record['context']['item_id'])->toBe($item->id);
    expect($record['context']['device_id'])->toBe($device->id);
    expect($record['context']['idempotency_key'])->toBe('audit-mserved-1');
});

// ---------------------------------------------------------------------------
// 4. revert writes kds_direct_revert
// ---------------------------------------------------------------------------

it('revert writes kds_direct_revert audit log', function () {
    [$device, $order, $item] = auditLogFixture('ready');
    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-revert-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/revert", ['to' => 'preparing'])
        ->assertOk();

    $record = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_revert');
    expect($record)->not->toBeNull();
    expect($record['context']['op'])->toBe('kds_direct_revert');
    expect($record['context']['order_id'])->toBe($order->id);
    expect($record['context']['item_id'])->toBe($item->id);
    expect($record['context']['device_id'])->toBe($device->id);
    expect($record['context']['idempotency_key'])->toBe('audit-revert-1');
});

// ---------------------------------------------------------------------------
// 5. bumpAll writes kds_direct_bump_all with affected_item_ids array
// ---------------------------------------------------------------------------

it('bumpAll writes kds_direct_bump_all audit log with affected_item_ids', function () {
    $branch = Branch::factory()->create();
    $device = Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_kds_bumpall_'.uniqid(),
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $items = CustomerOrderItem::factory()->count(2)->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-bumpall-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/bump-all", ['scope' => 'pending'])
        ->assertOk();

    $record = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_bump_all');
    expect($record)->not->toBeNull();
    expect($record['context']['op'])->toBe('kds_direct_bump_all');
    expect($record['context']['order_id'])->toBe($order->id);
    expect($record['context']['device_id'])->toBe($device->id);
    expect($record['context']['idempotency_key'])->toBe('audit-bumpall-1');
    expect($record['context']['affected_item_ids'])->toBeArray();
    expect(count($record['context']['affected_item_ids']))->toBe(2);
});

// ---------------------------------------------------------------------------
// 6. gen-1 PATCH writes audit log with via_gen1=true
// ---------------------------------------------------------------------------

it('gen-1 PATCH writes audit log with via_gen1=true flag', function () {
    [$device, $order, $item] = auditLogFixture('pending');
    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-gen1-1')
        ->patchJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/status", ['status' => 'preparing'])
        ->assertOk();

    // gen-1 dispatch logs as gen-2 op name with via_gen1=true
    $record = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_mark_preparing');
    expect($record)->not->toBeNull();
    expect($record['context']['via_gen1'])->toBeTrue();
    expect($record['context']['op'])->toBe('kds_direct_mark_preparing');
});

// ---------------------------------------------------------------------------
// 7. audit log payload includes required fields
// ---------------------------------------------------------------------------

it('audit log payload includes order_id, item_id, device_id, idempotency_key', function () {
    [$device, $order, $item] = auditLogFixture('preparing');
    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-fields-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-ready")
        ->assertOk();

    $record = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_mark_ready');
    expect($record)->not->toBeNull();

    $ctx = $record['context'];
    expect($ctx)->toHaveKey('order_id');
    expect($ctx)->toHaveKey('item_id');
    expect($ctx)->toHaveKey('device_id');
    expect($ctx)->toHaveKey('idempotency_key');
    expect($ctx['order_id'])->toBe($order->id);
    expect($ctx['item_id'])->toBe($item->id);
    expect($ctx['device_id'])->toBe($device->id);
    expect($ctx['idempotency_key'])->toBe('audit-fields-1');
});

// ---------------------------------------------------------------------------
// 8. audit log NOT written when guard throws (KDS_E001 case)
// ---------------------------------------------------------------------------

it('audit log is NOT written when a guard fails (KDS_E001)', function () {
    [$device, $order, $item] = auditLogFixture('pending');
    $order->update(['status' => CustomerOrderStatusEnum::Voided]);

    $handler = tapKdsBumpsChannel();

    $this->withHeader('Authorization', 'Bearer '.$device->device_token)
        ->withHeader('Idempotency-Key', 'audit-e001-nowrite-1')
        ->postJson("/api/v1/kds/orders/{$order->id}/items/{$item->id}/mark-preparing")
        ->assertStatus(409)
        ->assertJsonPath('code', 'KDS_E001');

    $auditRecord = collect($handler->getRecords())->first(fn ($r) => $r['message'] === 'kds_direct_mark_preparing');
    expect($auditRecord)->toBeNull();
});
