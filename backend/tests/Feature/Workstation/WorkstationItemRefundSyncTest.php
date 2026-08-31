<?php

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use Illuminate\Support\Str;

/*
 * #2254 — this sync-UP endpoint 500'd on every call because
 * ApproveOrderItemRefundCommand could not be constructed (readonly promoted
 * property re-assigned in the constructor body). No test reached it, so the
 * suite stayed green while the workstation's `order.item_refund` queue op
 * dead-lettered.
 */

it('refunds an item through the workstation sync-UP endpoint', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_item_refund',
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 2,
        'unit_price' => 500,
        'status' => 'served',
    ]);

    $this->withHeader('Authorization', 'Bearer tok_ws_item_refund')
        ->withHeader('Idempotency-Key', 'ws-refund-idem-1')
        ->postJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/refund", [
            'quantity' => 1,
            'reason' => '  khách trả lại món  ',
        ])
        ->assertOk();

    $refundLine = $order->items()->whereNotNull('refund_of_item_id')->sole();

    expect((float) $item->refresh()->refunded_quantity)->toBe(1.0)
        ->and($refundLine->refund_of_item_id)->toBe($item->id)
        // The reason travelled through the command's safeToken() untouched but trimmed.
        ->and($refundLine->note)->toBe('khách trả lại món');
});

it('adopts the workstation-supplied refund line id so a queue retry stays idempotent', function () {
    $branch = Branch::factory()->create();
    Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'branch_id' => $branch->id,
        'device_token' => 'tok_ws_refund_line_id',
    ]);
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open,
    ]);
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 500,
        'status' => 'served',
    ]);
    $localRefundLineId = (string) Str::uuid();

    $this->withHeader('Authorization', 'Bearer tok_ws_refund_line_id')
        ->withHeader('Idempotency-Key', 'ws-refund-idem-line')
        ->postJson("/api/v1/workstation/orders/{$order->id}/items/{$item->id}/refund", [
            'quantity' => 1,
            'reason' => 'queue drain',
            'client_order_item_id' => $localRefundLineId,
        ])
        ->assertOk();

    expect($order->items()->whereNotNull('refund_of_item_id')->pluck('id')->all())
        ->toBe([$localRefundLineId]);
});
