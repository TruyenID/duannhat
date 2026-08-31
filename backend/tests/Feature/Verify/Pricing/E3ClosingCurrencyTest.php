<?php

require_once __DIR__.'/vpr_helpers.php';

use App\Models\Customer;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderClosingService;
use App\Support\RoundingMode;

/*
 * E3 — OrderClosingService::isPaidEnough (OrderClosingService.php:61-64)
 *
 *     $currency  = (string) ($order->branch?->currency ?? 'JPY');
 *     $tolerance = 2 * RoundingMode::step('auto', $currency);
 *     return paid_amount >= total_amount - $tolerance;
 *
 * reads `branches.currency` (NULLABLE, fed by the SSO console), NOT
 * `shop_order_settings.currency_code` (what every pricing path uses).
 * close() then OVERWRITES paid_amount = total_amount (line 136).
 */

it('E3a: branches.currency NULL on a USD shop → isPaidEnough now resolves USD from settings', function () {
    $t = vprTenant('USD', branchCurrency: null);
    $order = vprOrder($t, 100.00, paid: 98.01);
    $order->load('branch');

    // Mirror the (now fixed) resolution order used by isPaidEnough.
    $resolvedCurrency = (string) ($t['setting']->currency_code ?? $order->branch?->currency ?? 'JPY');

    dump([
        'shop_order_settings.currency_code (what pricing uses)' => $t['setting']->currency_code,
        'branches.currency (what isPaidEnough uses)' => $t['branch']->currency,
        'currency isPaidEnough RESOLVES TO' => $resolvedCurrency,
        'tolerance = 2 x step' => 2 * RoundingMode::step('auto', $resolvedCurrency),
        'CORRECT tolerance for USD (2 x 0.01)' => 2 * RoundingMode::step('auto', 'USD'),
        '--- order.total_amount' => (float) $order->total_amount,
        '--- order.paid_amount' => (float) $order->paid_amount,
        '--- SHORTFALL' => 100.00 - 98.01,
        '>>> isPaidEnough()' => OrderClosingService::isPaidEnough($order) ? 'TRUE — closes' : 'false',
    ]);

    // RESOLVED (#821 E3): branches.currency is still null, but isPaidEnough now
    // reads shop_order_settings.currency_code (USD) like the pricing engine, so a
    // 98.01 payment on a 100.00 USD bill is NOT paid enough (0.02 tolerance).
    expect($t['branch']->currency)->toBeNull();
    expect($resolvedCurrency)->toBe('USD');
    expect(OrderClosingService::isPaidEnough($order))->toBeFalse();
})->group('e3');

it('E3b: HTTP — a 98.01 payment on a 100.00 USD order does NOT close it; no phantom revenue', function () {
    // Real cashier flow: POST /api/v1/pos/orders/{id}/payments → OrderPaymentService::create
    // → isPaidEnough (JPY fallback, tolerance 2.00) → OrderClosingService::close.
    $t = vprTenant('USD', branchCurrency: null);
    $user = vprActor($t);
    vprOpenShift($t);

    // A customer is attached so the walk-in partial-payment guard (a DIFFERENT
    // rule) does not mask the closing bug being measured.
    $customer = Customer::factory()->create(['organization_id' => $t['org_id']]);

    $order = vprOrder($t, 100.00);
    vprItem($order, 100.00);
    $order->update(['customer_id' => $customer->id]);

    $card = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $t['org_id']]);

    $res = test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/payments", [
            'payment_method_id' => $card->id,
            'amount' => 98.01,
        ]);

    $res->assertStatus(201);
    $order->refresh();
    $cashCollected = (float) OrderPayment::where('customer_order_id', $order->id)->sum('amount');

    dump([
        'HTTP status' => $res->status(),
        'order.total_amount' => (float) $order->total_amount,
        'ACTUAL cash collected (Σ payments.amount)' => $cashCollected,
        'order.status AFTER the payment' => $order->status instanceof BackedEnum ? $order->status->value : $order->status,
        '>>> order.paid_amount AFTER close' => (float) $order->paid_amount,
        '>>> PHANTOM REVENUE booked' => round((float) $order->paid_amount - $cashCollected, 2),
    ]);

    // RESOLVED (#821 E3): the USD-sized 0.02 tolerance leaves the order short, so
    // it stays open, paid_amount tracks the ACTUAL cash, and no revenue is booked
    // that was never collected.
    // RESOLVED (#821 E3): the USD-sized 0.02 tolerance leaves the order short, so
    // it stays open and — the crux — books NO revenue that was never collected:
    // paid_amount tracks the actual cash exactly (phantom = 0). (The card path
    // still stores the amount at whole-unit precision for a null-branch-currency
    // shop; that cent-precision loss is the separate #815 card-currency finding.)
    expect($order->status instanceof BackedEnum ? $order->status->value : $order->status)
        ->not->toBe(CustomerOrderStatusEnum::Closed->value);
    expect((float) $order->paid_amount)->toBe($cashCollected);
    expect(round((float) $order->paid_amount - $cashCollected, 2))->toBe(0.0);
})->group('e3');

it('E3c: with branches.currency = USD set, the SAME 98.01 payment does NOT close the order', function () {
    // Proves the defect is the currency SOURCE, not the tolerance formula.
    $t = vprTenant('USD', branchCurrency: 'USD');
    $user = vprActor($t);
    vprOpenShift($t);
    $customer = Customer::factory()->create(['organization_id' => $t['org_id']]);

    $order = vprOrder($t, 100.00);
    vprItem($order, 100.00);
    $order->update(['customer_id' => $customer->id]);
    $card = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $t['org_id']]);

    test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/payments", [
            'payment_method_id' => $card->id,
            'amount' => 98.01,
        ])->assertStatus(201);

    $order->refresh();

    dump([
        'branches.currency' => $t['branch']->currency,
        'tolerance = 2 x step(USD)' => 2 * RoundingMode::step('auto', 'USD'),
        'order.status' => $order->status instanceof BackedEnum ? $order->status->value : $order->status,
        'order.paid_amount' => (float) $order->paid_amount,
    ]);

    expect($order->status instanceof BackedEnum ? $order->status->value : $order->status)
        ->not->toBe(CustomerOrderStatusEnum::Closed->value);
    expect((float) $order->paid_amount)->toBe(98.01);
})->group('e3');

it('E3d: a USD shop can only underpay within the USD 0.02 rounding window now', function () {
    $t = vprTenant('USD', branchCurrency: null);

    $worst = null;
    foreach ([99.99, 99.00, 98.50, 98.01, 98.00, 97.99] as $paid) {
        $o = vprOrder($t, 100.00, paid: $paid);
        $o->load('branch');
        if (OrderClosingService::isPaidEnough($o)) {
            $worst = $paid;
        }
    }

    dump([
        'order.total_amount' => 100.00,
        'LOWEST paid_amount that still CLOSES the order' => $worst,
        'max write-off per order (USD)' => round(100.00 - $worst, 2),
    ]);

    // RESOLVED (#821 E3): the write-off window is the USD minor unit × 2 = 0.02,
    // so the lowest payment that still closes 100.00 is 99.99 — not the old
    // JPY-fallback 98.00 that let ~2 whole dollars slip.
    expect($worst)->toBe(99.99);
})->group('e3');
