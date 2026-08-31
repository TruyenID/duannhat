<?php

use App\Services\Customer\OrderPricingCalculator;

/**
 * Per-rate group tax is rounded ONCE per rate group and then allocated back to
 * the lines by largest remainder. Cloud and the workstation each implement that
 * allocator, and until now the only thing asserting they agree was a comment on
 * the Go side ("Mirrors CustomerOrderService::stampLineTaxAmounts on Cloud").
 *
 * #1219 is what an unpinned parity claim costs: a payment filter carried the
 * same promise in a docstring, one caller quietly did something else, and the
 * cashier's 過不足 stopped matching the money that was recorded. Rate RESOLUTION
 * was already pinned by tax_resolution_golden.json; the rounding and allocation
 * step — the half that has tie-breaks — was not.
 *
 * tests/Fixtures/tax_allocation_golden.json is byte-identical to
 * workstation/internal/service/testdata/tax_allocation_golden.json. This
 * side generated it, so this test is the regression guard: changing PHP's
 * allocator without regenerating (and re-checking Go) breaks here.
 *
 * The cases exist for what can DIVERGE between two implementations, not for
 * happy-path coverage: exact ties in the fractional remainder — the only place a
 * tie-break rule is observable — a residual landing on a different index than
 * the whole steps, step = 0, and the rare 内税 branch where the floored sum
 * overshoots the group tax.
 */
it('allocates group tax exactly as the shared golden fixture says', function () {
    $path = base_path('tests/Fixtures/tax_allocation_golden.json');

    expect(file_exists($path))->toBeTrue();

    $golden = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    // A truncated or empty fixture would otherwise make every assertion below
    // pass by never running.
    expect($golden['cases'])->toHaveCount($golden['case_count'])
        ->and($golden['case_count'])->toBeGreaterThan(0);

    $calculator = app(OrderPricingCalculator::class);

    foreach ($golden['cases'] as $case) {
        $allocated = array_values($calculator->allocateGroupTax(
            $case['ideals'],
            (float) $case['group_tax'],
            (float) $case['step'],
        ));

        expect($allocated)->toHaveCount(count($case['expected']), $case['name']);

        foreach ($case['expected'] as $i => $want) {
            expect(abs($allocated[$i] - (float) $want))->toBeLessThan(
                1e-6,
                sprintf('%s: line %d got %s, want %s', $case['name'], $i, $allocated[$i], $want),
            );
        }

        // Whatever the split, the group total is the invariant: a per-line
        // disagreement that still sums correctly is a display bug, one that does
        // not is a money bug.
        expect(abs(array_sum($allocated) - (float) $case['group_tax']))->toBeLessThan(1e-6, $case['name']);
    }
});

it('keeps the two copies of the fixture identical', function () {
    // The fixture is only worth anything while BOTH engines read the same bytes.
    // Two copies that drift is the failure this whole file exists to prevent,
    // one level up.
    //
    // This used to return early — `expect(true)->toBeTrue(); return;` — when the
    // workstation submodule was absent, on the reasoning that a missing checkout
    // is not a tax bug. True, but the shape it produced was worse than the
    // problem: a GREEN line, counted in the assertion total, for a comparison
    // that never happened. #2082 is what that costs — two Cloud-side rounding
    // fixes drifted away from Go while this file kept reporting pass.
    //
    // markTestSkipped is the honest primitive, and the one the older cross-repo
    // gates already use (CatalogParityTest, RendererPrimitivesParityTest): the
    // run reports SKIPPED instead of PASSED, so "nobody checked" stops looking
    // like "checked, fine".
    //
    // The self-maintaining version — every shared fixture, not just this one,
    // running in the directory per-PR CI actually executes, and HARD-FAILING on
    // CI where a missing submodule means the deploy key broke — lives in
    // tests/Feature/Architecture/SharedFixturesAgreeTest.php (#2089).
    $ours = base_path('tests/Fixtures/tax_allocation_golden.json');
    $theirs = base_path('../workstation/internal/service/testdata/tax_allocation_golden.json');

    if (! file_exists($theirs)) {
        test()->markTestSkipped('nguồn workstation vắng mặt trong cây (in-tree từ #2306) — cổng parity bị bỏ qua');
    }

    expect(hash_file('sha256', $theirs))->toBe(hash_file('sha256', $ours));
});
