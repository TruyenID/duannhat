<?php

declare(strict_types=1);

/**
 * #1276 — BR-COUP07 says release the coupon BEFORE flipping status, so
 * releaseIfApplied() can still mutate while the order is modifiable. Two of the
 * six order-void paths did that. Four did not, and each of those left the guest's
 * coupon consumed for an order that no longer exists: `coupons.times_used` never
 * decremented, their CouponRedemption row still standing.
 *
 * The quietest one was the reaper: a guest applies a coupon, walks off without
 * confirming, and expireAwaitingConfirmationOrders() voids the order. Nobody did
 * anything wrong and the use is gone.
 *
 * Asserted at the source. A behavioural test per path would need four different
 * fixtures (a workstation transport, a last-item void, a guest cancel, a bulk
 * reaper sweep) and would still only prove the four paths that exist today; this
 * fails for a fifth one written tomorrow.
 */
it('releases the coupon on every path that voids an order, before the status flip', function () {
    $source = file_get_contents(
        base_path('app/Services/Order/Internal/Concerns/WritesCustomerOrders.php'),
    );
    $lines = explode("\n", $source);

    // Every method that writes the ORDER-level voided status.
    $voidSites = [];
    foreach ($lines as $number => $line) {
        if (! str_contains($line, "'status' => CustomerOrderStatusEnum::Voided->value")) {
            continue;
        }
        $voidSites[] = $number;
    }

    // If this stops finding sites the test has gone blind, not the code clean.
    expect(count($voidSites))->toBeGreaterThanOrEqual(6, 'found too few void sites — the scan is broken');

    $enclosing = [];
    foreach ($lines as $number => $line) {
        if (preg_match('/^\s*(?:public|protected|private) function (\w+)\(/', $line, $m) === 1) {
            $enclosing[$number] = $m[1];
        }
    }

    $missing = [];
    foreach ($voidSites as $site) {
        $start = null;
        $name = null;
        foreach ($enclosing as $number => $fn) {
            if ($number < $site) {
                $start = $number;
                $name = $fn;
            }
        }
        if ($start === null) {
            continue;
        }

        $before = implode("\n", array_slice($lines, $start, $site - $start));
        if (! str_contains($before, 'releaseIfApplied')) {
            $missing[] = "{$name}() voids an order without releasing its coupon first";
        }
    }

    expect(array_values(array_unique($missing)))->toBe([], implode("\n  ", [
        'BR-COUP07: release the coupon BEFORE flipping status. Without it the guest keeps',
        'a consumed use — times_used stays incremented and their redemption row stands —',
        'on an order that no longer exists:',
        ...array_unique($missing),
    ]));
});
