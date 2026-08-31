<?php

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * plan-045 T4.1 / T8 — configurable tax rounding: the order's snapshot mode
 * governs (immutable), and changing the shop setting never re-rounds history.
 */
function roundingOrder(string $mode): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => $mode,
        'tax_rounding_decimals' => 0,
    ]);
    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => $mode,
        ]
    );
    // 1 unit @ ¥1234 net, 10% → 123.4 (the boundary that separates the modes).
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 1234,
        'topping_subtotal' => 0,
        'subtotal' => 1234,
        'tax_rate' => 10,
        'tax_amount' => 0,
        'status' => 'served',
    ]);

    return $order->fresh('items');
}

it('rounds tax with the order snapshot mode (ceil → 124, floor → 123)', function () {
    $up = roundingOrder('ceil');
    app(CustomerOrderService::class)->refreshOrderTotals($up);
    expect((float) $up->fresh()->tax_amount)->toBe(124.0); // ceil(123.4)

    $down = roundingOrder('floor');
    app(CustomerOrderService::class)->refreshOrderTotals($down);
    expect((float) $down->fresh()->tax_amount)->toBe(123.0); // floor(123.4)
});

it('a later shop-setting change does NOT re-round an existing order', function () {
    $order = roundingOrder('ceil');
    app(CustomerOrderService::class)->refreshOrderTotals($order);
    expect((float) $order->fresh()->tax_amount)->toBe(124.0);

    // Flip the SHOP setting to floor after the order exists.
    ShopOrderSetting::query()->where('branch_id', $order->branch_id)
        ->update(['tax_rounding_mode' => 'floor']);

    // Recompute the SAME order — it must keep its own snapshot (ceil → 124),
    // never the new setting (which would give 123).
    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));
    expect((float) $order->fresh()->tax_amount)->toBe(124.0);
});

it('a legacy round_up snapshot still prices via the alias', function () {
    // An order stamped before the rev-B rename carries 'round_up'; the engine
    // must alias it to ceil (124), not fall through to the round default (123).
    $legacy = roundingOrder('round_up');
    app(CustomerOrderService::class)->refreshOrderTotals($legacy);
    expect((float) $legacy->fresh()->tax_amount)->toBe(124.0);
});
