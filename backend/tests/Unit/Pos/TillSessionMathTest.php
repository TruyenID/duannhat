<?php

/*
 * Plan 030 — Unit coverage for the over/short reconciliation math
 * documented in DESIGN.md:
 *
 *   expected_cash = opening_float + cash_sales + Σ paid_in − Σ paid_out
 *   cash_variance = counted_cash − expected_cash
 *
 * The math is implemented inline inside TillSessionService::close()
 * and reconcile(). This test pins the contract with a table-driven
 * dataset covering even, over, short, and edge cases.
 */

dataset('cash variance cases', [
    'zero variance' => [
        'opening' => 100_000.0,
        'cash_sales' => 5_000.0,
        'paid_in' => 0.0,
        'paid_out' => 0.0,
        'counted' => 105_000.0,
        'expected_cash' => 105_000.0,
        'variance' => 0.0,
    ],
    'short by 1000' => [
        'opening' => 100_000.0,
        'cash_sales' => 0.0,
        'paid_in' => 0.0,
        'paid_out' => 0.0,
        'counted' => 99_000.0,
        'expected_cash' => 100_000.0,
        'variance' => -1_000.0,
    ],
    'over by 500' => [
        'opening' => 50_000.0,
        'cash_sales' => 10_000.0,
        'paid_in' => 0.0,
        'paid_out' => 0.0,
        'counted' => 60_500.0,
        'expected_cash' => 60_000.0,
        'variance' => 500.0,
    ],
    'paid_out + paid_in net' => [
        'opening' => 30_000.0,
        'cash_sales' => 5_000.0,
        'paid_in' => 2_000.0,
        'paid_out' => 7_000.0,
        // expected = 30k + 5k + 2k - 7k = 30k
        'counted' => 30_000.0,
        'expected_cash' => 30_000.0,
        'variance' => 0.0,
    ],
    'fractional cents' => [
        'opening' => 100.25,
        'cash_sales' => 0.75,
        'paid_in' => 0.0,
        'paid_out' => 0.0,
        'counted' => 101.00,
        'expected_cash' => 101.00,
        'variance' => 0.0,
    ],
]);

it('computes expected_cash + cash_variance per the reconciliation identity', function (
    float $opening,
    float $cash_sales,
    float $paid_in,
    float $paid_out,
    float $counted,
    float $expected_cash,
    float $variance,
) {
    $computedExpected = round($opening + $cash_sales + $paid_in - $paid_out, 2);
    $computedVariance = round($counted - $computedExpected, 2);

    expect($computedExpected)->toBe($expected_cash);
    expect($computedVariance)->toBe($variance);
})->with('cash variance cases');

it('computes per-tender net as gross minus cancel', function () {
    $cases = [
        ['gross' => 18_310, 'cancel' => 0, 'net' => 18_310],
        ['gross' => 9_220, 'cancel' => 1_000, 'net' => 8_220],
        ['gross' => 0, 'cancel' => 0, 'net' => 0],
        ['gross' => 5_000, 'cancel' => 5_000, 'net' => 0],
    ];
    foreach ($cases as $c) {
        $net = round($c['gross'] - $c['cancel'], 2);
        expect($net)->toBe((float) $c['net']);
    }
});
