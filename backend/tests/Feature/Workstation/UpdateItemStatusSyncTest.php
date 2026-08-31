<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\ProductSku;
use App\Models\TaxType;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Str;

it('updates item status via workstation sync-UP path', function () {
    $branch = Branch::factory()->create();
    $ws = Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_sync',
    ]);
    $order = CustomerOrder::factory()->create(['branch_id' => $branch->id, 'status' => CustomerOrderStatusEnum::Open]);
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'status' => 'pending']);

    $this->withHeader('Authorization', 'Bearer tok_ws_sync')
        ->withHeader('Idempotency-Key', 'sync-idem-1')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
            'actor_kds_device_id' => '01HXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
        ])
        ->assertOk();

    expect($item->refresh()->status->value)->toBe('preparing');
});

it('accepts a revert to pending (POS StatusPicker / KDS revert op)', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_sync_rev',
    ]);
    $order = CustomerOrder::factory()->create(['branch_id' => $branch->id, 'status' => CustomerOrderStatusEnum::Open]);
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'status' => 'served']);

    // The domain allows any active→active transition (#129); rejecting
    // `pending` at validation 422'd (non-retryable) the LAN sync of every
    // revert-to-pending and silently lost the change.
    $this->withHeader('Authorization', 'Bearer tok_ws_sync_rev')
        ->withHeader('Idempotency-Key', 'sync-idem-rev-1')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'pending',
        ])
        ->assertOk();

    expect($item->refresh()->status->value)->toBe('pending');
});

it('rejects sync-UP from non-workstation device', function () {
    Device::factory()->create([
        'type' => DeviceTypeEnum::Kds,
        'status' => DeviceStatusEnum::Active,
        'device_token' => 'tok_kds_not_ws',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_kds_not_ws')
        ->withHeader('Idempotency-Key', 'sync-idem-x')
        ->patchJson('/api/v1/workstation/orders/00000000-0000-0000-0000-000000000000/items/00000000-0000-0000-0000-000000000000/status', [
            'status' => 'preparing',
        ])
        ->assertForbidden();
});

it('rejects workstation sync-UP for foreign branch', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branchA->id,
        'device_token' => 'tok_ws_a',
    ]);
    $foreignOrder = CustomerOrder::factory()->create([
        'branch_id' => $branchB->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $foreignItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $foreignOrder->id,
        'status' => 'pending',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_ws_a')
        ->withHeader('Idempotency-Key', 'sync-cross-1')
        ->patchJson("/api/v1/workstation/orders/{$foreignOrder->id}/items/{$foreignItem->id}/status", [
            'status' => 'preparing',
        ])
        ->assertForbidden();
});

it('replays cached response on idempotency key reuse', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_replay',
    ]);
    $order = CustomerOrder::factory()->create(['branch_id' => $branch->id, 'status' => CustomerOrderStatusEnum::Open]);
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'status' => 'pending']);

    $first = $this->withHeader('Authorization', 'Bearer tok_ws_replay')
        ->withHeader('Idempotency-Key', 'sync-replay-1')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])->json();

    $second = $this->withHeader('Authorization', 'Bearer tok_ws_replay')
        ->withHeader('Idempotency-Key', 'sync-replay-1')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'ready',
        ])->json();

    expect($second)->toEqual($first);
    expect($item->refresh()->status->value)->toBe('preparing');
});

it('rejects sync-UP without Idempotency-Key header', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_no_idem',
    ]);
    $order = CustomerOrder::factory()->create(['branch_id' => $branch->id, 'status' => CustomerOrderStatusEnum::Open]);
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'status' => 'pending']);

    $this->withHeader('Authorization', 'Bearer tok_ws_no_idem')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertStatus(422);

    expect($item->refresh()->status->value)->toBe('pending');
});

it('preserves the item note when ghost-creating from a KDS-bump snapshot (takeaway)', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_ghost_note',
    ]);
    // A takeaway order whose line reaches Cloud for the first time via a KDS
    // status bump (item was never synced through lifecycle addItems).
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $sku = ProductSku::factory()->create();
    $itemId = (string) Str::uuid();

    $this->withHeader('Authorization', 'Bearer tok_ws_ghost_note')
        ->withHeader('Idempotency-Key', 'sync-ghost-note-1')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$itemId}/status", [
            'status' => 'preparing',
            'item_snapshot' => [
                'product_sku_id' => $sku->id,
                'quantity' => 1,
                'unit_price' => 500,
                'note' => 'KHONG LAY HANH',
            ],
        ])
        ->assertOk();

    $ghost = CustomerOrderItem::findOrFail($itemId);
    expect($ghost->note)->toBe('KHONG LAY HANH')
        ->and($ghost->status->value)->toBe('preparing');
});

it('#2411 — dòng ma từ KDS-bump mang ảnh chụp thuế, không ra đời trống', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_ghost_tax',
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);

    // 標準 10% neo ở tầng 4 (sản phẩm) — tầng thấp nhất còn giải ra được, tức ca
    // yếu nhất mà đường ghi vẫn phải đóng dấu.
    $std = TaxType::factory()->standard()->create([
        'organization_id' => $order->organization_id,
        'brand_id' => $order->brand_id,
    ]);
    $sku = ProductSku::factory()->create();
    $sku->product->update(['tax_type_id' => $std->id]);

    $itemId = (string) Str::uuid();

    $this->withHeader('Authorization', 'Bearer tok_ws_ghost_tax')
        ->withHeader('Idempotency-Key', 'sync-ghost-tax-1')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$itemId}/status", [
            'status' => 'preparing',
            'item_snapshot' => [
                'product_sku_id' => $sku->id,
                'quantity' => 1,
                'unit_price' => 500,
            ],
        ])
        ->assertOk();

    // Không có bước nào chạy sau ghost-create để đóng dấu hộ: thiếu ở đây là
    // thiếu vĩnh viễn, và các đường tổng thuế DROP dòng NULL — món bán ra mà
    // không nộp thuế.
    $ghost = CustomerOrderItem::findOrFail($itemId);
    expect($ghost->tax_rate)->not->toBeNull()
        ->and((float) $ghost->tax_rate)->toBe(10.0)
        ->and($ghost->tax_type_id)->toBe($std->id);
});

it('accepts sync-UP without actor_kds_device_id (nullable)', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_no_actor',
    ]);
    $order = CustomerOrder::factory()->create(['branch_id' => $branch->id, 'status' => CustomerOrderStatusEnum::Open]);
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'status' => 'pending']);

    $this->withHeader('Authorization', 'Bearer tok_ws_no_actor')
        ->withHeader('Idempotency-Key', 'sync-no-actor-1')
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/status", [
            'status' => 'preparing',
        ])
        ->assertOk();

    expect($item->refresh()->status->value)->toBe('preparing');
});
