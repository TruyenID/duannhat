<?php

require_once __DIR__.'/vpr_helpers.php';

use App\Services\Customer\CustomerOrderService;

/*
 * E7 — CustomerOrderService::splitBill() (CustomerOrderService.php:1876)
 *
 *     $baseAmount = floor($remaining / $splitCount);
 *     $remainder  = $remaining - ($baseAmount * $splitCount);
 *     // person #0 pays $baseAmount + $remainder
 *
 * floor() to WHOLE UNITS regardless of currency. Driven through the real
 * production service method (the one POS / customer-web /split-bill calls).
 */

it('E7a: HTTP — USD 100.00 / 3 → the by-people split is quantised to whole dollars', function () {
    $t = vprTenant('USD');
    $user = vprActor($t);
    $order = vprOrder($t, 100.00);
    $order->update(['guest_count' => 3]);
    vprItem($order, 100.00);

    // Real route: GET /api/v1/pos/orders/{customerOrder}/split-bill?split_count=3
    $res = test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->getJson("/api/v1/pos/orders/{$order->id}/split-bill?split_count=3");
    $res->assertOk();
    $result = $res->json();

    dump([
        'HTTP status' => $res->status(),
        'shop currency_code' => $t['setting']->currency_code,
        'order.total_amount' => (float) $order->total_amount,
        'split_count' => 3,
        '>>> per_person_amounts' => $result['per_person_amounts'],
        '>>> rounding_note' => $result['rounding_note'],
        'CORRECT for USD (step 0.01)' => ['33.34', '33.33', '33.33'],
        '>>> person #1 OVERPAYS by' => round((float) $result['per_person_amounts'][0] - 33.34, 2),
        'sum of splits' => array_sum(array_map('floatval', $result['per_person_amounts'])),
    ]);

    expect($result['per_person_amounts'])->toBe(['34.00', '33.00', '33.00']);
})->group('e7');

it('E7b: the worst case — USD 100.00 / 7', function () {
    $t = vprTenant('USD');
    $order = vprOrder($t, 100.00);
    $order->update(['guest_count' => 7]);

    $result = app(CustomerOrderService::class)->splitBill($order->refresh(), 7);

    $exact = round(100.00 / 7, 2); // 14.29

    dump([
        'order.total_amount' => 100.00,
        'split_count' => 7,
        '>>> per_person_amounts' => $result['per_person_amounts'],
        'exact fair share (USD)' => $exact,
        '>>> person #1 OVERPAYS by' => round((float) $result['per_person_amounts'][0] - $exact, 2),
        '>>> everyone else UNDERPAYS by' => round($exact - (float) $result['per_person_amounts'][1], 2),
        'sum still equals total?' => array_sum(array_map('floatval', $result['per_person_amounts'])) == 100.00 ? 'yes — no money lost' : 'NO',
    ]);

    expect($result['per_person_amounts'][0])->toBe('16.00');
    expect($result['per_person_amounts'][1])->toBe('14.00');
})->group('e7');

it('E7c: JPY/VND (integer currency) is unaffected — floor() is correct there', function () {
    $t = vprTenant('JPY');
    $order = vprOrder($t, 1000.0);
    $order->update(['guest_count' => 3]);

    $result = app(CustomerOrderService::class)->splitBill($order->refresh(), 3);

    dump([
        'JPY order.total_amount' => 1000.0,
        'per_person_amounts' => $result['per_person_amounts'],
        'rounding_note' => $result['rounding_note'],
    ]);

    expect($result['per_person_amounts'])->toBe(['334.00', '333.00', '333.00']);
})->group('e7');

it('E7d: splitBill never consults RoundingMode / currency at all', function () {
    // Same numeric total, different currencies → identical output.
    $usd = vprTenant('USD');
    $o1 = vprOrder($usd, 100.00);
    $o1->update(['guest_count' => 3]);
    $r1 = app(CustomerOrderService::class)->splitBill($o1->refresh(), 3);

    $kwd = vprTenant('KWD'); // 3-decimal currency, step 0.001
    $o2 = vprOrder($kwd, 100.00);
    $o2->update(['guest_count' => 3]);
    $r2 = app(CustomerOrderService::class)->splitBill($o2->refresh(), 3);

    dump([
        'USD (step 0.01) splits' => $r1['per_person_amounts'],
        'KWD (step 0.001) splits' => $r2['per_person_amounts'],
        'identical?' => $r1['per_person_amounts'] === $r2['per_person_amounts'] ? 'YES — currency ignored' : 'no',
    ]);

    expect($r1['per_person_amounts'])->toBe($r2['per_person_amounts']);
})->group('e7');
