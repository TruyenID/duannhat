<?php

use App\Services\Customer\OrderPricingCalculator;

/**
 * plan-audit test-gap (plan-043) — tax-INCLUDED mode (総額表示 / 内税 extraction)
 * crossed with the coupon-discount and service-charge-tax steps of the §8
 * engine.
 *
 * The existing OrderPricingCalculatorTest exercises each of these in ISOLATION:
 * included-mode extraction with no discount + no service charge; coupon
 * pro-rata only in EXCLUDED mode; service-charge tax only in EXCLUDED mode.
 * Nothing crossed 内税 extraction WITH a coupon or WITH a taxed service charge —
 * the two hardest interactions of the algorithm (extract-from-discounted-gross
 * and extract-the-service-charge-tax-then-merge). These cases pin the exact yen.
 *
 * Pure function over per-rate subtotals; no DB → Unit. JPY step = 1.0.
 */
beforeEach(function () {
    $this->calc = new OrderPricingCalculator;
});

it('included mode extracts tax from the DISCOUNTED gross, coupon pro-rata per group', function () {
    // gross 8% = ¥3,300, gross 10% = ¥2,200; ¥550 fixed coupon, included, JPY.
    // Coupon splits pro-rata on gross: 550 × 3300/5500 = 330 off the 8% group,
    // 550 × 2200/5500 = 220 off the 10% group. Tax is then EXTRACTED from the
    // discounted gross of each group (端数処理は税率ごとに1回).
    $r = $this->calc->priceGroups(['8' => 3300.0, '10' => 2200.0], 550, 0, 0, true, 1.0);

    expect($r->discount)->toBe(550.0)
        // 8%: gross 3300 − 330 = 2970 → tax = 2970 − round(2970/1.08=2750) = 220.
        // 10%: gross 2200 − 220 = 1980 → tax = 1980 − round(1980/1.10=1800) = 180.
        ->and($r->taxAmount)->toBe(400.0)
        // total = Σ discounted gross (tax already inside) = 2970 + 1980.
        ->and($r->totalAmount)->toBe(4950.0)
        ->and($r->groups)->toHaveCount(2);

    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[8]->tax)->toBe(220.0)->and($byRate[8]->taxable)->toBe(2750.0)
        ->and($byRate[10]->tax)->toBe(180.0)->and($byRate[10]->taxable)->toBe(1800.0);
});

it('included mode extracts the service-charge tax and merges it into the matching rate group', function () {
    // one 10% gross group of ¥2,200, service 10%, sc tax 10%, 内税.
    $r = $this->calc->priceGroups(['10' => 2200.0], 0, 10, 10, true, 1.0);

    // sc on gross taxable base 2200 → 220; its tax is EXTRACTED (内税):
    // 220 − round(220/1.10 = 200) = 20.
    expect($r->serviceCharge)->toBe(220.0)
        ->and($r->serviceChargeTax)->toBe(20.0)
        // group tax = 2200 − round(2200/1.1 = 2000) = 200; + 20 sc tax = 220.
        ->and($r->taxAmount)->toBe(220.0)
        // total = Σ gross (2200, tax inside) + service charge (220, tax inside).
        ->and($r->totalAmount)->toBe(2420.0)
        ->and($r->groups)->toHaveCount(1);

    // sc tax joined the 10% group (net share 200 folded in), not an orphan line.
    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[10]->tax)->toBe(220.0)->and($byRate[10]->taxable)->toBe(2200.0);
});

it('included mode with a service-charge tax rate that matches no item group forms its own group', function () {
    // one 8% gross group of ¥1,080; service 10%, sc tax 10% → the sc tax lands
    // in a NEW 10% group (no item is at 10%).
    $r = $this->calc->priceGroups(['8' => 1080.0], 0, 10, 10, true, 1.0);

    // sc = round(1080 × 10%) = 108; sc tax = 108 − round(108/1.10 = 98.18→98) = 10.
    expect($r->serviceCharge)->toBe(108.0)
        ->and($r->serviceChargeTax)->toBe(10.0)
        // 8% group tax = 1080 − round(1080/1.08 = 1000) = 80; + 10 sc tax = 90.
        ->and($r->taxAmount)->toBe(90.0)
        ->and($r->totalAmount)->toBe(1188.0) // 1080 gross + 108 service charge
        ->and($r->groups)->toHaveCount(2);

    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[8]->tax)->toBe(80.0)->and($byRate[8]->taxable)->toBe(1000.0)
        // orphan sc group: net share 98, extracted tax 10.
        ->and($byRate[10]->tax)->toBe(10.0)->and($byRate[10]->taxable)->toBe(98.0);
});

it('included mode crosses coupon AND a taxed service charge in one order', function () {
    // gross 8% = 3,300, gross 10% = 2,200; ¥550 coupon; service 10%, sc tax 10%.
    // Every §8 step active at once, 内税 throughout.
    $r = $this->calc->priceGroups(['8' => 3300.0, '10' => 2200.0], 550, 10, 10, true, 1.0);

    // service charge on (subtotal − discount = 4950) gross: round(4950 × 10%) = 495.
    // sc tax extracted: 495 − round(495/1.10 = 450) = 45.
    expect($r->discount)->toBe(550.0)
        ->and($r->serviceCharge)->toBe(495.0)
        ->and($r->serviceChargeTax)->toBe(45.0)
        // group taxes: 8% = 220, 10% = 180 (as in the coupon-only case) → 400,
        // + 45 sc tax = 445.
        ->and($r->taxAmount)->toBe(445.0)
        // total = Σ discounted gross (4950) + service charge (495).
        ->and($r->totalAmount)->toBe(5445.0)
        ->and($r->groups)->toHaveCount(2);

    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    // the 45 sc tax + its 450 net share merge into the existing 10% group.
    expect($byRate[8]->tax)->toBe(220.0)
        ->and($byRate[10]->tax)->toBe(225.0)   // 180 item + 45 sc
        ->and($byRate[10]->taxable)->toBe(2250.0); // 1800 item + 450 sc net
});
