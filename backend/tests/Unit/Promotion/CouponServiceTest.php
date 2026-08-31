<?php

/**
 * Plan-019 — pure-function unit tests for CouponService.
 *
 * computeStatus and computeDiscount are deterministic; cover them
 * without booting the full request/route stack.
 */

use App\Models\Coupon;
use App\Services\Promotion\CouponService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = app(CouponService::class);
});

// ─── computeDiscount ─────────────────────────────────────────────────────

it('computes fixed discount and clamps to subtotal', function () {
    $coupon = new Coupon(['discount_type' => 'fixed', 'discount_value' => 50000, 'max_discount_cap' => null]);

    expect($this->service->computeDiscount($coupon, 200000.0))->toBe(50000.0);
    expect($this->service->computeDiscount($coupon, 30000.0))->toBe(30000.0);
    expect($this->service->computeDiscount($coupon, 0.0))->toBe(0.0);
});

it('computes percent discount with cap', function () {
    $coupon = new Coupon(['discount_type' => 'percent', 'discount_value' => 10, 'max_discount_cap' => 50000]);

    // 10% of 200_000 = 20_000 — under cap.
    expect($this->service->computeDiscount($coupon, 200000.0))->toBe(20000.0);
    // 10% of 1_000_000 = 100_000 — capped at 50_000.
    expect($this->service->computeDiscount($coupon, 1000000.0))->toBe(50000.0);
});

it('computes percent discount without cap', function () {
    $coupon = new Coupon(['discount_type' => 'percent', 'discount_value' => 25, 'max_discount_cap' => null]);

    expect($this->service->computeDiscount($coupon, 100000.0))->toBe(25000.0);
});

it('rounds to whole yen on percent (JPY is zero-decimal)', function () {
    $coupon = new Coupon(['discount_type' => 'percent', 'discount_value' => 10, 'max_discount_cap' => null]);

    // 10% of 12345 = 1234.5 → rounds half-away-from-zero to 1235 (JPY mode).
    expect($this->service->computeDiscount($coupon, 12345.0))->toBe(1235.0);
    // 10% of 5347 = 534.7 → 535 (matches the customer-facing display).
    expect($this->service->computeDiscount($coupon, 5347.0))->toBe(535.0);
});

it('rounds to the ORDER currency, not a global Stripe config', function () {
    // Regression (plan-019): isZeroDecimalCurrency() used to key off the
    // global config('services.stripe.currency'), ignoring the order's
    // ShopOrderSetting.currency. Simulate a 2-decimal global config and prove
    // the per-order currency now drives the rounding step instead.
    config(['services.stripe.currency' => 'usd']);

    $coupon = new Coupon(['discount_type' => 'percent', 'discount_value' => 10, 'max_discount_cap' => null]);

    // VND is zero-decimal → whole units, even though the global config is usd.
    // 10% of 12345 = 1234.5 → 1235 (before the fix this leaked to 1234.5).
    expect($this->service->computeDiscount($coupon, 12345.0, 'VND'))->toBe(1235.0);

    // USD is a 2-decimal currency → keep the sub-unit precision.
    // 10% of 100.05 = 10.005 → 10.01 (before the fix the jpy default zeroed it to 10.0).
    expect($this->service->computeDiscount($coupon, 100.05, 'USD'))->toBe(10.01);
});

it('defaults to zero-decimal (VND) when no currency is passed', function () {
    config(['services.stripe.currency' => 'usd']);

    $coupon = new Coupon(['discount_type' => 'percent', 'discount_value' => 10, 'max_discount_cap' => null]);

    // Null currency → project-default VND → zero decimals, regardless of the
    // global Stripe config.
    expect($this->service->computeDiscount($coupon, 12345.0))->toBe(1235.0);
});

// ─── computeStatus ──────────────────────────────────────────────────────

it('returns paused when storable status is paused, regardless of time', function () {
    $coupon = makeCoupon(status: 'paused', validFrom: '-1 day', validUntil: '+1 day');

    expect($this->service->computeStatus($coupon))->toBe('paused');
});

it('returns scheduled when valid_from is in the future', function () {
    $coupon = makeCoupon(status: 'draft', validFrom: '+1 day', validUntil: '+10 days');

    expect($this->service->computeStatus($coupon))->toBe('scheduled');
});

it('returns expired when now is past valid_until', function () {
    $coupon = makeCoupon(status: 'draft', validFrom: '-5 days', validUntil: '-1 day');

    expect($this->service->computeStatus($coupon))->toBe('expired');
});

it('returns exhausted when times_used >= usage_limit_total', function () {
    $coupon = makeCoupon(status: 'draft', validFrom: '-1 day', validUntil: '+1 day', limit: 100, used: 100);

    expect($this->service->computeStatus($coupon))->toBe('exhausted');
});

it('returns active when in window and not exhausted', function () {
    $coupon = makeCoupon(status: 'draft', validFrom: '-1 day', validUntil: '+1 day', limit: 100, used: 50);

    expect($this->service->computeStatus($coupon))->toBe('active');
});

it('returns active when usage_limit_total is null (unlimited)', function () {
    $coupon = makeCoupon(status: 'draft', validFrom: '-1 day', validUntil: '+1 day', limit: null, used: 9999);

    expect($this->service->computeStatus($coupon))->toBe('active');
});

it('respects an explicit `at` timestamp for time-travel scenarios', function () {
    $coupon = makeCoupon(status: 'draft', validFrom: '-10 days', validUntil: '-1 day');

    // Default (now) → expired.
    expect($this->service->computeStatus($coupon))->toBe('expired');
    // Time-travel back → active.
    expect($this->service->computeStatus($coupon, CarbonImmutable::parse('-5 days')))->toBe('active');
});

// ─── helper ─────────────────────────────────────────────────────────────

function makeCoupon(string $status, string $validFrom, string $validUntil, ?int $limit = null, int $used = 0): Coupon
{
    return new Coupon([
        'status' => $status,
        'valid_from' => CarbonImmutable::parse($validFrom),
        'valid_until' => CarbonImmutable::parse($validUntil),
        'usage_limit_total' => $limit,
        'times_used' => $used,
    ]);
}
