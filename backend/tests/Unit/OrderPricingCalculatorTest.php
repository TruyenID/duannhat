<?php

use App\Services\Customer\OrderPricingCalculator;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * plan-043 T1.8 — the §8 pricing engine (priceGroups). Pure function over
 * per-rate subtotals; no DB, so it lives in Unit. Numbers are hand-computed
 * in the spec and here (JPY step = 1 unless noted).
 */

/**
 * #2753 — cần app BOOT (không cần DB).
 *
 * `Pest.php` gán `TestCase` theo TỪNG thư mục — `Unit/Promotion`,
 * `Unit/Exceptions`, `Unit/Casts`, `Unit/Services/Settlement` — và file này nằm
 * THẲNG trong `Unit/` nên không dính dòng nào.
 *
 * Nó vẫn xanh suốt vì một test khác đã boot app trước đó trong cùng tiến trình.
 * Khi một Feature test TEAR DOWN app (xoá facade root) rồi tới lượt nó,
 * `Log::spy()` dưới kia không còn container: `BindingResolutionException`.
 * `flake-hunt` bắt được ở lượt nightly 2026-08-12 (seed 69625575); tái hiện
 * trong 0,9s bằng cách cho MỘT Feature test chạy trước.
 *
 * KHÔNG kèm `RefreshDatabase`: docblock ngay trên nói đúng — đây là hàm thuần,
 * không đụng DB. Dựng DB cho nó là trả giá thật cho một nhu cầu không có.
 */
uses(TestCase::class);
beforeEach(function () {
    $this->calc = new OrderPricingCalculator;
});

it('proof case §1.2 — bentō 8% + beer 10% in one takeaway order, each group rounded once', function () {
    // bentō ¥1000 @8%, beer ¥500 @10%, excluded, JPY.
    $r = $this->calc->priceGroups(['8' => 1000.0, '10' => 500.0], 0, 0, 0, false, 1.0);

    expect($r->subtotal)->toBe(1500.0)
        ->and($r->taxAmount)->toBe(130.0)          // 80 (8% of 1000) + 50 (10% of 500)
        ->and($r->totalAmount)->toBe(1630.0)
        ->and($r->groups)->toHaveCount(2);

    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[8]->tax)->toBe(80.0)->and($byRate[10]->tax)->toBe(50.0);
});

it('rounds ONCE per group — 3×¥333 @8% = 80, not the per-line-summed 81', function () {
    // 3 lines of 333 collapse into one 8% group of 999.
    $r = $this->calc->priceGroups(['8' => 999.0], 0, 0, 0, false, 1.0);

    // round(999 × 0.08) = round(79.92) = 80. Per-line: round(333×.08)=27 ×3 = 81 (WRONG).
    expect($r->taxAmount)->toBe(80.0)
        ->and($r->taxAmount)->not->toBe(81.0);
});

it('excluded mode adds tax on top of the taxable base', function () {
    $r = $this->calc->priceGroups(['10' => 2000.0], 0, 0, 0, false, 1.0);

    expect($r->taxAmount)->toBe(200.0)->and($r->totalAmount)->toBe(2200.0);
});

it('included mode extracts tax from gross — total equals the gross subtotal (総額表示)', function () {
    // gross bentō 1080 @8% + gross beer 550 @10%.
    $r = $this->calc->priceGroups(['8' => 1080.0, '10' => 550.0], 0, 0, 0, true, 1.0);

    expect($r->totalAmount)->toBe(1630.0)      // Σ gross, tax NOT added again
        ->and($r->taxAmount)->toBe(130.0);     // 80 + 50 内税

    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[8]->tax)->toBe(80.0)->and($byRate[8]->taxable)->toBe(1000.0);
});

it('allocates a coupon pro-rata across rate groups before taxing (§8 step 2)', function () {
    // ¥500 coupon on 8%=3000 / 10%=2000 → 300 / 200.
    $r = $this->calc->priceGroups(['8' => 3000.0, '10' => 2000.0], 500, 0, 0, false, 1.0);

    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[8]->tax)->toBe(216.0)       // (3000−300) × 8%
        ->and($byRate[10]->tax)->toBe(180.0)   // (2000−200) × 10%
        ->and($r->totalAmount)->toBe(4896.0);  // 4500 + 396
});

it('taxes the service charge with its own rate and merges it into the matching group (gap #7)', function () {
    // one 10% group of 2000, service 10%, sc tax 10%.
    $r = $this->calc->priceGroups(['10' => 2000.0], 0, 10, 10, false, 1.0);

    expect($r->serviceCharge)->toBe(200.0)
        ->and($r->serviceChargeTax)->toBe(20.0)
        ->and($r->taxAmount)->toBe(220.0)      // 200 group + 20 sc
        ->and($r->totalAmount)->toBe(2420.0);  // 2000 + 200 + 200 + 20

    // sc tax joined the 10% group, not an orphan line.
    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[10]->tax)->toBe(220.0);
});

it('handles an all-exempt (0%) order with no division-by-zero in included mode', function () {
    $r = $this->calc->priceGroups(['0' => 1000.0], 0, 0, 0, true, 1.0);

    expect($r->taxAmount)->toBe(0.0)->and($r->totalAmount)->toBe(1000.0);
});

it('honours the currency step — USD rounds tax to 0.01', function () {
    // 8% of 100.10 = 8.008 → half-up to 0.01 = 8.01.
    $r = $this->calc->priceGroups(['8' => 100.10], 0, 0, 0, false, 0.01);

    expect($r->taxAmount)->toBe(8.01);
});

it('clamps discount to the subtotal and never produces negative tax', function () {
    $r = $this->calc->priceGroups(['10' => 1000.0], 5000, 0, 0, false, 1.0);

    expect($r->discount)->toBe(1000.0)->and($r->taxAmount)->toBe(0.0)->and($r->totalAmount)->toBe(0.0);
});

/**
 * plan-043 — per-line tax_amount allocation (largest remainder). The stored
 * snapshots must sum EXACTLY to the once-per-group tax the order total uses,
 * so reports that Σ them (Z-report, dashboards, tax_breakdown) reconcile and
 * never re-introduce the インボイス-forbidden per-line rounding.
 */
it('allocateGroupTax — 3×¥333 @8% sums to the group 80, not per-line 81', function () {
    // each line ideal = 26.64; group tax = round(999×0.08) = 80.
    $ideals = [26.64, 26.64, 26.64];
    $parts = $this->calc->allocateGroupTax($ideals, 80.0, 1.0);

    expect(array_sum($parts))->toBe(80.0)          // reconciles, NOT 81
        ->and($parts)->toEqual([27.0, 27.0, 26.0]) // whole steps to the top remainders
        ->and(array_sum($parts))->not->toBe(81.0);
});

it('allocateGroupTax — every line a whole step, deterministic tie-break by input order', function () {
    // 5 lines of ¥333 @8% → group round(1665×0.08=133.2)=133; ideal 26.64 each.
    $ideals = array_fill(0, 5, 26.64);
    $groupTax = $this->calc->groupTaxFor(1665.0, 8.0, false, 1.0);

    expect($groupTax)->toBe(133.0);

    $parts = $this->calc->allocateGroupTax($ideals, $groupTax, 1.0);
    // Σ floor = 130, deficit 3 → +1 to first three (equal remainders → input order).
    expect($parts)->toBe([27.0, 27.0, 27.0, 26.0, 26.0])
        ->and(array_sum($parts))->toBe(133.0);
});

it('allocateGroupTax — reconciles per group under a pro-rata discount', function () {
    // 3 lines ¥334/¥333/¥333 (subtotal 1000), ¥100 discount, 8%, JPY.
    $subtotal = 1000.0;
    $discount = 100.0;
    $lineSubs = [334.0, 333.0, 333.0];
    $nets = array_map(fn ($s) => $s - $discount * $s / $subtotal, $lineSubs);
    $netGroup = array_sum($nets); // == 900

    $groupTax = $this->calc->groupTaxFor($netGroup, 8.0, false, 1.0); // round(72.0) = 72
    $ideals = array_map(fn ($net) => $this->calc->lineTaxIdeal($net, 8.0, false), $nets);
    $parts = $this->calc->allocateGroupTax($ideals, $groupTax, 1.0);

    expect($netGroup)->toBe(900.0)
        ->and($groupTax)->toBe(72.0)
        ->and(array_sum($parts))->toBe(72.0)
        ->and(collect($parts)->every(fn ($p) => $p >= 0))->toBeTrue();
});

it('allocateGroupTax — included (内税) mode still sums exactly to the group tax', function () {
    // 3 gross lines of ¥360 @8% included; group net 1080, tax = 1080 − round(1080/1.08=1000) = 80.
    $nets = [360.0, 360.0, 360.0];
    $groupTax = $this->calc->groupTaxFor(1080.0, 8.0, true, 1.0);
    $ideals = array_map(fn ($net) => $this->calc->lineTaxIdeal($net, 8.0, true), $nets);
    $parts = $this->calc->allocateGroupTax($ideals, $groupTax, 1.0);

    expect($groupTax)->toBe(80.0)
        ->and(array_sum($parts))->toBe(80.0)
        ->and(collect($parts)->every(fn ($p) => $p >= 0))->toBeTrue();
});

it('allocateGroupTax — USD sub-unit step allocates and reconciles to the cent', function () {
    // 3 lines of $3.33 @8% = ideal 0.2664 each; group round(9.99×0.08=0.7992)=0.80.
    $ideals = [0.2664, 0.2664, 0.2664];
    $groupTax = $this->calc->groupTaxFor(9.99, 8.0, false, 0.01);
    $parts = $this->calc->allocateGroupTax($ideals, $groupTax, 0.01);

    expect($groupTax)->toBe(0.8)
        ->and(round(array_sum($parts), 2))->toBe(0.8);
});

it('allocateGroupTax — a single line simply takes the whole group tax', function () {
    $parts = $this->calc->allocateGroupTax([79.92], 80.0, 1.0);

    expect($parts)->toBe([80.0]);
});

it('allocateGroupTax — a 0% / exempt group allocates zero to every line', function () {
    $parts = $this->calc->allocateGroupTax([0.0, 0.0], 0.0, 1.0);

    expect($parts)->toBe([0.0, 0.0])->and(array_sum($parts))->toBe(0.0);
});

// ── plan-045 ─────────────────────────────────────────────────────────────────

it('priceGroups honours ceil / floor / round tax modes', function () {
    // 1234 @10% = 123.4 → round 123, ceil 124, floor 123 (JPY step 1).
    expect($this->calc->priceGroups(['10' => 1234.0], 0, 0, 0, false, 1.0, 1.0, 'ceil')->taxAmount)->toBe(124.0)
        ->and($this->calc->priceGroups(['10' => 1234.0], 0, 0, 0, false, 1.0, 1.0, 'floor')->taxAmount)->toBe(123.0)
        ->and($this->calc->priceGroups(['10' => 1234.0], 0, 0, 0, false, 1.0, 1.0, 'round')->taxAmount)->toBe(123.0)
        // default (no mode) is byte-identical to the round path.
        ->and($this->calc->priceGroups(['10' => 1234.0], 0, 0, 0, false, 1.0)->taxAmount)->toBe(123.0);
});

it('priceGroups still accepts the legacy half_up / round_up / round_down aliases', function () {
    // Orders snapshotted before the rev-B rename must price identically.
    expect($this->calc->priceGroups(['10' => 1234.0], 0, 0, 0, false, 1.0, 1.0, 'round_up')->taxAmount)->toBe(124.0)
        ->and($this->calc->priceGroups(['10' => 1234.0], 0, 0, 0, false, 1.0, 1.0, 'round_down')->taxAmount)->toBe(123.0)
        ->and($this->calc->priceGroups(['10' => 1234.0], 0, 0, 0, false, 1.0, 1.0, 'half_up')->taxAmount)->toBe(123.0);
});

it('applyRefundLines folds the negated refund snapshot without re-rounding', function () {
    // base: one ¥1000 @10% excluded line → tax 100, total 1100.
    $base = $this->calc->priceGroups(['10' => 1000.0], 0, 0, 0, false, 1.0);
    expect($base->taxAmount)->toBe(100.0)->and($base->totalAmount)->toBe(1100.0);

    // refund half the line: subtotal -500, tax -50 (copied+negated snapshot).
    $refund = (object) [
        'refund_of_item_id' => 'orig', 'status' => 'served',
        'subtotal' => -500.0, 'tax_amount' => -50.0, 'tax_rate' => 10.0,
    ];
    $r = $this->calc->applyRefundLines($base, [$refund]);

    expect($r->subtotal)->toBe(500.0)        // 1000 - 500
        ->and($r->taxAmount)->toBe(50.0)     // 100 - 50 (exact reversal)
        ->and($r->totalAmount)->toBe(550.0); // 1100 - (500 + 50)
    $byRate = collect($r->groups)->keyBy(fn ($g) => (int) $g->rate);
    expect($byRate[10]->tax)->toBe(50.0);
});

it('applyRefundLines is a no-op when no refund lines are present', function () {
    $base = $this->calc->priceGroups(['10' => 1000.0], 0, 0, 0, false, 1.0);
    $r = $this->calc->applyRefundLines($base, []);

    expect($r->taxAmount)->toBe($base->taxAmount)->and($r->totalAmount)->toBe($base->totalAmount);
});

it('#2257 — applyRefundLines drops refund lines with a null tax_rate snapshot (#2188 parity Go)', function () {
    Log::spy();

    $base = $this->calc->priceGroups(['10' => 1000.0], 0, 0, 0, false, 1.0);
    $brokenRefund = (object) [
        'refund_of_item_id' => 'orig',
        'status' => 'served',
        'customer_order_id' => 'ord-null-rate',
        'subtotal' => -500.0,
        'tax_amount' => -50.0,
        'tax_rate' => null,
    ];
    $r = $this->calc->applyRefundLines($base, [$brokenRefund]);

    expect($r->taxAmount)->toBe($base->taxAmount)
        ->and($r->totalAmount)->toBe($base->totalAmount)
        ->and($r->subtotal)->toBe($base->subtotal);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'dropped refund lines')
            && $ctx['order_id'] === 'ord-null-rate'
            && $ctx['dropped_lines'] === 1);
});
