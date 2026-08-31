<?php

/**
 * plan-029 logic-risk — one source of truth for zero-decimal currencies.
 *
 * Regression: StripePaymentService.ZERO_DECIMAL, RoundingMode.ZERO_FRACTION and
 * the front-end table disagreed. StripePaymentService wrongly treated TWD and
 * IDR as zero-decimal (100× under-charge to Stripe) and omitted MGA (100×
 * over-charge); RoundingMode dropped DJF/MGA from its whole-unit set. These
 * tests pin the unified set so the divergence can't silently return.
 */

use App\Support\RoundingMode;
use App\Support\ZeroDecimalCurrency;

it('matches Stripe documented zero-decimal set exactly', function () {
    // https://stripe.com/docs/currencies#zero-decimal
    $stripe = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    sort($stripe);
    $codes = ZeroDecimalCurrency::CODES;
    sort($codes);

    expect($codes)->toBe($stripe);
});

it('does NOT treat TWD or IDR as zero-decimal (they carry a Stripe minor unit)', function () {
    // The core money bug: TWD/IDR were sent to Stripe without ×100 → 100× undercharge.
    expect(ZeroDecimalCurrency::contains('TWD'))->toBeFalse();
    expect(ZeroDecimalCurrency::contains('IDR'))->toBeFalse();
});

it('treats MGA as zero-decimal so it is not over-charged 100×', function () {
    expect(ZeroDecimalCurrency::contains('MGA'))->toBeTrue();
});

it('is case-insensitive and null-safe', function () {
    expect(ZeroDecimalCurrency::contains('jpy'))->toBeTrue();
    expect(ZeroDecimalCurrency::contains(null))->toBeFalse();
});

it('rounds every true zero-decimal currency to whole units in auto mode', function (string $code) {
    expect(RoundingMode::step('auto', $code))->toBe(1.0);
})->with(ZeroDecimalCurrency::CODES);

it('rounds DJF and MGA to whole units (previously dropped from the rounding table)', function () {
    // Before the fix these fell through to 0.01 — wrong for a currency with no minor unit.
    expect(RoundingMode::step('auto', 'DJF'))->toBe(1.0);
    expect(RoundingMode::step('auto', 'MGA'))->toBe(1.0);
});

it('keeps the whole-unit-cash extras (IDR/LAK/MMK/COP) at step 1 for split-bill', function (string $code) {
    expect(RoundingMode::step('auto', $code))->toBe(1.0);
})->with(['IDR', 'LAK', 'MMK', 'COP']);

it('still rounds ordinary two-decimal currencies to 0.01', function (string $code) {
    expect(RoundingMode::step('auto', $code))->toBe(0.01);
})->with(['USD', 'EUR', 'THB', 'TWD']);
