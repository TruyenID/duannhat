<?php

require_once __DIR__.'/vpr_helpers.php';

use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\SplitByItemsCalculator;

/*
 * E8 — SplitByItemsCalculator rounds each bill's total UP (roundUpToStep with
 * the split_bill_rounding_mode step) while the ORDER total is half-up, and the
 * reconcile block dumps the whole drift into the LAST non-empty bill's `tax`:
 *
 *     $target['tax']   += $diff;      // SplitByItemsCalculator.php:216
 *     $target['total']  = $projected;
 *
 * $diff is negative whenever Σ ceiled bills > order total → the printed bill
 * shows a NEGATIVE tax. plan-043 only changed the PRE-reconcile per-rate tax
 * rounding ($minorStep); the reconcile line is untouched.
 *
 * Driven through the REAL preview route the POS/kiosk/customer surfaces call:
 *   GET /api/v1/pos/orders/{customerOrder}/split-by-items/preview?allocations=…
 */

it('E8a: admin API accepts split_bill_rounding_mode = integer on a USD shop (no cross-check)', function () {
    $t = vprTenant('USD');
    $user = vprActor($t);

    $res = test()->actingAs($user)
        ->patchJson("/api/v1/shops/{$t['branch']->slug}/settings/order", [
            'split_bill_rounding_mode' => 'integer',
        ]);

    $setting = ShopOrderSetting::where('branch_id', $t['branch']->id)->first();

    dump([
        'HTTP status' => $res->status(),
        'currency_code' => $setting->currency_code,
        '>>> split_bill_rounding_mode PERSISTED' => $setting->split_bill_rounding_mode,
        'cross-check against currency_code?' => 'none — Rule::in([auto,integer,two_decimals,none]) only',
    ]);

    expect($res->status())->toBe(200);
    expect($setting->currency_code)->toBe('USD');
    expect($setting->split_bill_rounding_mode)->toBe('integer');
})->group('e8');

it('E8b: HTTP preview — USD + integer mode + 3 bills → the LAST printed bill shows a NEGATIVE tax', function () {
    $t = vprTenant('USD', roundingMode: 'integer');
    $user = vprActor($t);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value, 'guest_count' => 3]);

    // 3 x 11.10 @ 10% → subtotal 33.30, tax 3.33, total 36.63 (half-up engine).
    $i1 = vprItem($order, 11.10, 1, 10.0);
    $i2 = vprItem($order, 11.10, 1, 10.0);
    $i3 = vprItem($order, 11.10, 1, 10.0);

    // Price through the REAL checkout path so total_amount is the engine's figure.
    test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/checkout", [])
        ->assertOk();
    $order->refresh();

    $allocations = [
        ['item_id' => (string) $i1->id, 'units' => 1, 'bill_index' => 0],
        ['item_id' => (string) $i2->id, 'units' => 1, 'bill_index' => 1],
        ['item_id' => (string) $i3->id, 'units' => 1, 'bill_index' => 2],
    ];

    $res = test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->getJson("/api/v1/pos/orders/{$order->id}/split-by-items/preview?allocations=".urlencode(json_encode($allocations)));

    $res->assertOk();
    $bills = $res->json('data.preview_bills');
    $lastTax = (float) $bills[2]['tax'];
    $sum = array_sum(array_map(fn ($b) => (float) $b['total'], $bills));

    dump([
        'HTTP status' => $res->status(),
        'rounding_mode returned by the API' => $res->json('data.rounding_mode'),
        'rounding_step returned by the API' => $res->json('data.rounding_step'),
        'currency_code' => $res->json('data.currency_code'),
        'order.subtotal' => (float) $order->subtotal,
        'order.tax_amount' => (float) $order->tax_amount,
        'order.total_amount' => (float) $order->total_amount,
        '--- preview_bills (what the POS prints)' => $bills,
        '>>> LAST BILL tax' => $lastTax,
        '>>> IS NEGATIVE?' => $lastTax < 0 ? 'YES — a printed bill shows a negative tax line' : 'no',
        'Σ bill.total' => round($sum, 2),
        'equals order.total_amount?' => abs($sum - (float) $order->total_amount) < 0.005 ? 'YES — no money lost' : 'NO — MONEY LOST',
    ]);

    expect($lastTax)->toBeLessThan(0.0);
    expect(round($sum, 2))->toBe(round((float) $order->total_amount, 2));
})->group('e8');

it('E8c: sweep — how often the reconcile drives a bill tax negative (USD, integer mode)', function () {
    // plan-043 rounds per-rate tax to the currency MINOR unit INSIDE the loop
    // ($minorStep = 0.01 for USD). That is the PRE-reconcile tax. The reconcile
    // block afterwards still does `$target['tax'] += $diff` with the raw drift.
    $t = vprTenant('USD', roundingMode: 'integer');
    $user = vprActor($t);

    $negatives = 0;
    $samples = [];
    $cases = [[11.10, 11.10, 11.10], [12.34, 11.11, 13.57], [10.90, 10.90, 10.90], [1.10, 1.10, 1.10], [5.05, 5.05, 5.05]];

    foreach ($cases as $prices) {
        $order = vprOrder($t, 0.0);
        $order->update(['status' => CustomerOrderStatusEnum::Open->value, 'guest_count' => 3]);
        $items = [];
        foreach ($prices as $p) {
            $items[] = vprItem($order, $p, 1, 10.0);
        }

        // Price through the production checkout endpoint.
        test()->actingAs($user)
            ->withHeader('X-Shop-Slug', $t['branch']->slug)
            ->postJson("/api/v1/pos/orders/{$order->id}/checkout", [])
            ->assertOk();
        $order->refresh();

        $allocs = [];
        foreach ($items as $idx => $it) {
            $allocs[] = ['item_id' => (string) $it->id, 'units' => 1, 'bill_index' => $idx];
        }

        $bills = test()->actingAs($user)
            ->withHeader('X-Shop-Slug', $t['branch']->slug)
            ->getJson("/api/v1/pos/orders/{$order->id}/split-by-items/preview?allocations=".urlencode(json_encode($allocs)))
            ->json('data.preview_bills');

        $taxes = array_map(fn ($b) => (float) $b['tax'], $bills);
        $totals = array_map(fn ($b) => (float) $b['total'], $bills);
        if (min($taxes) < 0) {
            $negatives++;
        }

        $samples[] = [
            'prices' => $prices,
            'order.total' => (float) $order->total_amount,
            'bill taxes' => $taxes,
            'bill totals' => $totals,
            'Σ totals == order.total' => abs(array_sum($totals) - (float) $order->total_amount) < 0.005 ? 'yes' : 'NO',
        ];
    }

    dump([
        'cases swept' => count($cases),
        'cases with a NEGATIVE bill tax' => $negatives,
        'detail' => $samples,
    ]);

    expect($negatives)->toBeGreaterThan(0);
})->group('e8');

it('E8d: with rounding_mode = auto (the default) on USD, no negative tax', function () {
    $t = vprTenant('USD', roundingMode: 'auto');
    $order = vprOrder($t, 0.0);
    $items = [vprItem($order, 11.10, 1, 10.0), vprItem($order, 11.10, 1, 10.0), vprItem($order, 11.10, 1, 10.0)];
    $order->update(['subtotal' => 33.30, 'tax_amount' => 3.33, 'total_amount' => 36.63, 'guest_count' => 3]);
    $order->load('items');

    $allocs = [];
    foreach ($items as $idx => $it) {
        $allocs[] = ['item_id' => (string) $it->id, 'units' => 1, 'bill_index' => $idx];
    }

    $res = app(SplitByItemsCalculator::class)->compute($order, $allocs, 'auto', 'USD', 0.0, 0.0, 3, true, false);

    dump([
        'rounding_mode' => 'auto (step 0.01)',
        'bill taxes' => array_map(fn ($b) => round((float) $b['tax'], 4), $res['bills']),
        'bill totals' => array_map(fn ($b) => round((float) $b['total'], 2), $res['bills']),
    ]);

    foreach ($res['bills'] as $b) {
        expect((float) $b['tax'])->toBeGreaterThanOrEqual(0.0);
    }
})->group('e8');
