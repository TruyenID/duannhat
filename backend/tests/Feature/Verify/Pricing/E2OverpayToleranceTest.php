<?php

require_once __DIR__.'/vpr_helpers.php';

use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\RoundingMode;

/*
 * E2 — the overpay clamp tolerance in OrderPaymentService::create()
 * (app/Services/Customer/OrderPaymentService.php:255-260):
 *
 *     $tolerance = 1.0;                                   // MAJOR units
 *     if (metadata.split_mode === 'by_items')
 *         $tolerance = max(1.0, total_bills * 2.0);       // MAJOR units
 *     if ($overpay <= $tolerance) { $amount = $outstanding; }   // silent clamp
 *
 * never multiplied by RoundingMode::step().
 *
 * Driven end-to-end over the REAL cashier HTTP route:
 *   POST /api/v1/pos/orders/{customerOrder}/payments
 *   (SSO auth + X-Shop-Slug + ResolveOpenTillSession shift lock).
 * Request validation on `amount` is only ['required','numeric','gt:0'] — no
 * max — so the clamp is reachable from the wire.
 *
 * A card-terminal method is used (requires_tendered = false, auto-confirm):
 * there is no tendered/change field to absorb the difference, so the delta is
 * neither tip nor change — it simply disappears from the ledger.
 */

function vprPayHttp(array $t, User $user, string $orderId, float $amount, string $methodId, array $metadata = [])
{
    $payload = ['payment_method_id' => $methodId, 'amount' => $amount];
    if ($metadata !== []) {
        $payload['metadata'] = $metadata;
    }

    return test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$orderId}/payments", $payload);
}

it('E2a: HTTP — USD order of 100.00 charged 100.99 on a card terminal → the 0.99 vanishes', function () {
    $t = vprTenant('USD');
    $user = vprActor($t);
    vprOpenShift($t);

    $order = vprOrder($t, 100.00);
    vprItem($order, 100.00);
    $card = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $t['org_id']]);

    $res = vprPayHttp($t, $user, $order->id, 100.99, $card->id);
    $res->assertStatus(201);

    $payment = OrderPayment::where('customer_order_id', $order->id)->firstOrFail();
    $order->refresh();

    dump([
        'shop currency_code' => $t['setting']->currency_code,
        'currency step (RoundingMode::step)' => RoundingMode::step('auto', 'USD'),
        'tolerance the code actually used' => 1.0,
        'HTTP status' => $res->status(),
        '--- order.total_amount' => (float) $order->total_amount,
        '--- amount REQUESTED (charged on the terminal)' => 100.99,
        '--- payment.amount RECORDED' => (float) $payment->amount,
        '--- payment.tip_amount' => (float) $payment->tip_amount,
        '--- payment.change_amount' => $payment->change_amount,
        '--- order.paid_amount' => (float) $order->paid_amount,
        '>>> USD UNACCOUNTED FOR' => round(100.99 - (float) $payment->amount - (float) $payment->tip_amount - (float) ($payment->change_amount ?? 0), 2),
    ]);

    expect((float) $payment->amount)->toBe(100.00);
    expect((float) $payment->tip_amount)->toBe(0.0);
    expect($payment->change_amount)->toBeNull();
})->group('e2');

it('E2b: HTTP — by_items + total_bills = 4 → an $8.00 silent clamp window on a USD shop', function () {
    $t = vprTenant('USD');
    $user = vprActor($t);
    vprOpenShift($t);

    $order = vprOrder($t, 100.00);
    vprItem($order, 100.00);
    $card = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $t['org_id']]);

    // tolerance = max(1.0, 4 * 2.0) = 8.0 MAJOR units = $8.00.
    // 107.50 is $7.50 over — well inside that window → clamped, not rejected.
    $res = vprPayHttp($t, $user, $order->id, 107.50, $card->id, [
        'split_mode' => 'by_items',
        'total_bills' => 4,
    ]);

    $payment = OrderPayment::where('customer_order_id', $order->id)->first();
    $order->refresh();

    dump([
        'HTTP status' => $res->status(),
        'order.total_amount' => (float) $order->total_amount,
        'amount REQUESTED' => 107.50,
        'overpay' => 7.50,
        'tolerance applied (max(1.0, 4 x 2.0))' => 8.0,
        'payment.amount RECORDED' => $payment ? (float) $payment->amount : null,
        'order.paid_amount' => (float) $order->paid_amount,
        '>>> USD SILENTLY SWALLOWED' => $payment ? round(107.50 - (float) $payment->amount, 2) : null,
    ]);

    expect($res->status())->toBe(201);
    expect((float) $payment->amount)->toBe(100.00);
})->group('e2');

it('E2c: HTTP — the window is exactly 1.00, not 1 step: 100.99 clamps, 101.01 is rejected', function () {
    $t = vprTenant('USD');
    $user = vprActor($t);
    vprOpenShift($t);
    $card = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $t['org_id']]);

    $o1 = vprOrder($t, 100.00);
    vprItem($o1, 100.00);
    $rejected = vprPayHttp($t, $user, $o1->id, 101.01, $card->id);

    $o2 = vprOrder($t, 100.00);
    vprItem($o2, 100.00);
    $clamped = vprPayHttp($t, $user, $o2->id, 100.99, $card->id);
    $p2 = OrderPayment::where('customer_order_id', $o2->id)->firstOrFail();

    dump([
        '101.01 on a 100.00 USD order (overpay 1.01 > 1.0)' => $rejected->status().' '.($rejected->json('code') ?? ''),
        '100.99 on a 100.00 USD order (overpay 0.99 <= 1.0)' => $clamped->status().' → recorded '.(float) $p2->amount,
        'CORRECT window for USD would be ~1 step' => RoundingMode::step('auto', 'USD'),
    ]);

    expect($rejected->status())->toBe(422);
    expect($rejected->json('code'))->toBe('overpayment_blocked');
    expect((float) $p2->amount)->toBe(100.00);
})->group('e2');

it('E2d: contrast — the split validator 370 lines below DOES scale its tolerance by the step', function () {
    // OrderPaymentService.php:625
    //   $tolerance = max(RoundingMode::step($roundingMode, $currencyCode), 0.01);
    expect(max(RoundingMode::step('auto', 'USD'), 0.01))->toBe(0.01);
    expect(max(RoundingMode::step('auto', 'JPY'), 0.01))->toBe(1.0);
    // vs the overpay clamp's hardcoded 1.0 for every currency.
})->group('e2');
