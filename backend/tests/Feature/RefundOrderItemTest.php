<?php

use App\Exceptions\RefundException;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * plan-045 T5.1 / T4.2 — refund a line by appending a negative-value order item
 * (never mutating the original), with the engine folding the negated tax exactly.
 */
function makeRefundableOrder(): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ]);
    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
        ]
    );
    // 3 units @ ¥100 net, 10% → line net 300, tax 30.
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 3,
        'unit_price' => 100,
        'topping_subtotal' => 0,
        'subtotal' => 300,
        'tax_rate' => 10,
        'tax_amount' => 30,
        'status' => 'served',
    ]);

    return $order->fresh('items');
}

it('refunds N units: appends a negative line, bumps refunded_quantity, drops the total exactly', function () {
    $order = makeRefundableOrder();
    $item = $order->items->first();

    $updated = app(CustomerOrderService::class)->refundItem($order, $item->id, 2.0, 'damaged');

    // A negative refund line exists, linked to the original, tax negated pro-rata.
    $refund = $updated->items->firstWhere('refund_of_item_id', $item->id);
    expect($refund)->not->toBeNull()
        ->and((float) $refund->quantity)->toBe(-2.0)
        ->and((float) $refund->subtotal)->toBe(-200.0)
        ->and((float) $refund->tax_amount)->toBe(-20.0)   // 30 × 2/3 = 20, negated
        ->and($refund->product_sku_id)->toBe($item->product_sku_id); // carried for LAN sync

    // Accumulator bumped; original untouched otherwise.
    expect((float) $item->fresh()->refunded_quantity)->toBe(2.0);

    // Totals reflect only the 1 un-refunded unit: net 100 + tax 10 = 110.
    expect((float) $updated->subtotal)->toBe(100.0)
        ->and((float) $updated->tax_amount)->toBe(10.0)
        ->and((float) $updated->total_amount)->toBe(110.0);
});

it('rejects an over-refund (cumulative > original quantity) with REFUND_EXCEEDS_QUANTITY', function () {
    $order = makeRefundableOrder();
    $item = $order->items->first();

    app(CustomerOrderService::class)->refundItem($order, $item->id, 2.0);

    expect(fn () => app(CustomerOrderService::class)->refundItem($order->fresh('items'), $item->id, 2.0))
        ->toThrow(RefundException::class); // 2 + 2 > 3
});

it('cannot refund a refund line', function () {
    $order = makeRefundableOrder();
    $item = $order->items->first();
    $updated = app(CustomerOrderService::class)->refundItem($order, $item->id, 1.0);
    $refundLine = $updated->items->firstWhere('refund_of_item_id', $item->id);

    expect(fn () => app(CustomerOrderService::class)->refundItem($updated, $refundLine->id, 1.0))
        ->toThrow(RefundException::class);
});

it('full-line refund reverses the tax exactly (zeroes the net tax)', function () {
    $order = makeRefundableOrder();
    $item = $order->items->first();

    $updated = app(CustomerOrderService::class)->refundItem($order, $item->id, 3.0);

    expect((float) $updated->subtotal)->toBe(0.0)
        ->and((float) $updated->tax_amount)->toBe(0.0)
        ->and((float) $updated->total_amount)->toBe(0.0);
});

it('writes tax conditions that reconcile + an append-only refund condition', function () {
    $order = makeRefundableOrder();
    $item = $order->items->first();
    $updated = app(CustomerOrderService::class)->refundItem($order, $item->id, 2.0, 'spill');

    // Refund condition (append-only) on the refund line — negated gross 2×110.
    $refundLine = $updated->items->firstWhere('refund_of_item_id', $item->id);
    $refundCond = OrderCondition::query()
        ->where('type', 'refund')->where('conditionable_id', $refundLine->id)->first();
    expect($refundCond)->not->toBeNull()
        ->and((float) $refundCond->amount)->toBe(-220.0);

    // Tax conditions (folded) sum to the order's tax_amount.
    $taxSum = (float) OrderCondition::query()
        ->where('type', 'tax')
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id)->sum('amount');
    expect($taxSum)->toBe((float) $updated->tax_amount);
});

it('refundItem with an explicit refund-line id stamps it (durable sync idempotency)', function () {
    $order = makeRefundableOrder();
    $item = $order->items->first();
    $lineId = '019f0000-0000-7000-8000-00000000abcd';

    app(CustomerOrderService::class)->refundItem($order, $item->id, 1.0, null, $lineId);

    // The refund line carries the workstation UUID → a re-drain finds it.
    expect(CustomerOrderItem::query()->where('id', $lineId)->where('refund_of_item_id', $item->id)->exists())->toBeTrue();
});
