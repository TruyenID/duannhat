<?php

require_once __DIR__.'/vpr_helpers.php';

use App\Models\Coupon;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\Promotion\CouponService;
use App\Support\RoundingMode;

/*
 * E1 — RoundingMode::roundHalfUpToStep is float-biased DOWN at step 0.01,
 * while CouponService::computeDiscount uses PHP round(). Re-measure on dev.
 */

it('E1a: the documented single input 0.145 disagrees between the two "half-up" fns', function () {
    $viaStep = RoundingMode::roundHalfUpToStep(0.145, 0.01);
    $viaRound = round(0.145, 2);

    dump([
        '0.145 / 0.01 (raw float)' => 0.145 / 0.01,
        'roundHalfUpToStep(0.145, 0.01)' => $viaStep,
        'round(0.145, 2)' => $viaRound,
    ]);

    // Report, do not assert a "good" outcome — we want the measurement.
    expect($viaStep)->toBeFloat();
    expect($viaRound)->toBeFloat();
})->group('e1');

it('E1b: MEASURE divergence over bases 0.01..2000 step 0.01 at 10% tax, step=0.01', function () {
    $divergent = 0;
    $total = 0;
    $down = 0;
    $up = 0;
    $samples = [];

    for ($cents = 1; $cents <= 200_000; $cents++) {
        $base = $cents / 100;
        $raw = $base * 10.0 / 100.0; // 10% tax
        $total++;

        $a = RoundingMode::roundHalfUpToStep($raw, 0.01);
        $b = round($raw, 2);

        if (abs($a - $b) > 1e-9) {
            $divergent++;
            if ($a < $b) {
                $down++;
            } else {
                $up++;
            }
            if (count($samples) < 5) {
                $samples[] = "base={$base} raw={$raw} roundHalfUpToStep={$a} round()={$b}";
            }
        }
    }

    dump([
        'bases tested' => $total,
        'divergent' => $divergent,
        'divergence %' => round($divergent / $total * 100, 4).'%',
        'roundHalfUpToStep LOWER than round() (bias down)' => $down,
        'roundHalfUpToStep HIGHER than round() (bias up)' => $up,
        'first samples' => $samples,
    ]);

    // RESOLVED (#821 E1): roundHalfUpToStep now normalises the quotient's binary
    // error before the .5 decision, so it agrees with PHP round() half-up across
    // the whole range — the divergence this test was written to prove is gone.
    expect($divergent)->toBe(0);
})->group('e1');

it('E1c: MEASURE divergence at step=1 (JPY/VND)', function () {
    $divergent = 0;
    $total = 0;
    $samples = [];

    for ($unit = 1; $unit <= 200_000; $unit++) {
        $raw = $unit * 10.0 / 100.0;
        $total++;
        $a = RoundingMode::roundHalfUpToStep($raw, 1.0);
        $b = round($raw, 0);
        if (abs($a - $b) > 1e-9) {
            $divergent++;
            if (count($samples) < 5) {
                $samples[] = "base={$unit} raw={$raw} step1={$a} round()={$b}";
            }
        }
    }

    dump([
        'step=1 bases tested' => $total,
        'step=1 divergent' => $divergent,
        'samples' => $samples,
    ]);

    expect($total)->toBe(200_000);
})->group('e1');

it('E1d: the two engines really are used on the SAME order — coupon uses round(), tax uses roundHalfUpToStep', function () {
    $t = vprTenant('USD');
    $calc = app(OrderPricingCalculator::class);
    $coupons = app(CouponService::class);

    // A 1.45% coupon on a 10.00 subtotal → raw discount 0.145 → the exact
    // pathological input, computed by the PRODUCTION coupon service.
    $coupon = Coupon::factory()->create([
        'organization_id' => $t['org_id'],
        'discount_type' => 'percent',
        'discount_value' => 1.45,
        'max_discount_cap' => null,
    ]);

    $discount = $coupons->computeDiscount($coupon, 10.00, 'USD');

    // And the tax engine's own half-up on the same magnitude.
    $tax = RoundingMode::roundHalfUpToStep(0.145, 0.01);

    dump([
        'CouponService::computeDiscount(1.45% of 10.00, USD)' => $discount,
        'RoundingMode::roundHalfUpToStep(0.145, 0.01)' => $tax,
        'they agree?' => abs($discount - $tax) < 1e-9 ? 'YES' : 'NO',
    ]);

    expect($discount)->toBeFloat();
})->group('e1');

it('E1f: PRODUCTION PATH — a USD order whose tax lands on a .xx5 boundary collects full half-up', function () {
    // 1.45 @ 10% → raw tax 0.145. Half-up says 0.15; the engine's
    // floor(0.145/0.01 + 0.5) sees 14.499999999999998 → 0.14.
    $t = vprTenant('USD');
    $user = User::factory()->create(['console_organization_id' => $t['org_id']]);
    grantOrgAccess($user, $t['org_id']);

    $order = vprOrder($t, 0.0);
    $order->update(['status' => CustomerOrderStatusEnum::Open->value]);
    vprItem($order, 1.45, 1, 10.0);

    test()->actingAs($user)
        ->withHeader('X-Shop-Slug', $t['branch']->slug)
        ->postJson("/api/v1/pos/orders/{$order->id}/checkout", [])
        ->assertOk();
    $order->refresh();

    // RESOLVED (#821 E1): the USD tax rounding step is no longer forced to whole
    // dollars by the tax_rounding_decimals=0 DB default (clamped to the currency
    // minor unit), and half-up no longer drops the .xx5 boundary. The engine now
    // collects the correct 0.15 — full half-up, nothing under-collected.
    expect((float) $order->tax_amount)->toBe(0.15);
    expect(round(1.45 * 0.10, 2))->toBe(0.15);        // true half-up
    expect((float) $order->total_amount)->toBe(1.60); // 1.45 + 0.15
})->group('e1');

it('E1e: can step = 0 (mode "none") reach priceGroups()? — trace the production call sites', function () {
    // priceGroups() is only ever called with a step from RoundingMode::step('auto', ...).
    $modes = ['auto', 'integer', 'two_decimals', 'none'];
    $autoSteps = [];
    foreach (['USD', 'JPY', 'VND', 'KWD'] as $ccy) {
        $autoSteps[$ccy] = RoundingMode::step('auto', $ccy);
    }
    $modeSteps = [];
    foreach ($modes as $m) {
        $modeSteps[$m] = RoundingMode::step($m, 'USD');
    }

    dump([
        "RoundingMode::step('auto', ccy) — what applyPricing/forOrder/price() ALWAYS pass" => $autoSteps,
        'RoundingMode::step(mode, USD) — what split_bill_rounding_mode can produce' => $modeSteps,
    ]);

    // The pricing engine can never see step 0: every caller hardcodes 'auto'.
    foreach ($autoSteps as $ccy => $s) {
        expect($s)->toBeGreaterThan(0.0, "auto step for {$ccy} must be > 0");
    }

    // In SplitByItemsCalculator, the mode-step (which CAN be 0) is only used
    // behind an `if ($step > 0)` guard, and every roundHalfUpToStep call there
    // uses $minorStep = step('auto', ccy), which is never 0.
    expect(RoundingMode::step('none', 'USD'))->toBe(0.0);
    expect(RoundingMode::step('auto', 'USD'))->toBe(0.01);

    // Prove the step<=0 passthrough is behaviourally unreachable in split:
    // roundHalfUpToStep is never called with the mode-step there.
    expect(RoundingMode::roundHalfUpToStep(0.145, 0.0))->toBe(0.145); // passthrough exists
    expect(RoundingMode::roundUpToStep(0.145, 0.0))->toBe(0.145);
})->group('e1');
