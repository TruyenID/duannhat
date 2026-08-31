<?php

/**
 * #1868 — an option built with no arguments must be CURRENTLY IN FORCE.
 *
 * `PaymentGatewayOptionFactory` used `fake()->dateTime()` for both effectivity
 * columns. Faker draws that from 1970→now, so `effective_to` was ALWAYS in the
 * past — measured 200/200 past, 0/200 future.
 *
 * That was harmless until #1866 taught
 * `PosEffectivePaymentOptionEnricher::internalTenderMethodIds()` to filter
 * `effective_from <= now < effective_to`. From then on every default-built
 * option was invisible to it, DETERMINISTICALLY (probed 3/3), and any test that
 * seeded one and asserted on the enricher would pass because the row was never
 * considered at all.
 *
 * This pins the default. Revert the factory to `fake()->dateTime()` for
 * `effective_to` and the first case goes red.
 *
 * Deliberately NOT asserting on the enricher itself: that would pass for the
 * wrong reason the day the enricher changes shape. The invariant being pinned
 * is about the FACTORY — "no arguments means live" — so it is measured against
 * the same predicate the production filter uses, stated once here.
 */

use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;

/** The production predicate, restated. Keep in step with `internalTenderMethodIds()`. */
function inForceNow(PaymentGatewayOption $option): bool
{
    $now = now();

    return PaymentGatewayOption::query()
        ->whereKey($option->id)
        ->where('effective_from', '<=', $now)
        ->where(function ($query) use ($now): void {
            $query->whereNull('effective_to')->orWhere('effective_to', '>', $now);
        })
        ->exists();
}

it('#1868 builds an option that is in force, with no arguments', function () {
    $provider = PaymentGatewayProvider::factory()->create(['code' => 'sbps', 'is_active' => true]);

    // Ten draws, not one: the old default was random, so a single draw could
    // have passed by luck. Ten identical results is the difference between
    // "the default is right" and "this run got lucky".
    foreach (range(1, 10) as $i) {
        $option = PaymentGatewayOption::factory()->create(['provider_id' => $provider->id]);

        expect(inForceNow($option))->toBeTrue(
            "draw {$i}: from={$option->effective_from} to=".($option->effective_to ?? 'NULL')
        );
    }
});

it('#1868 still lets a test ask for an expired window explicitly', function () {
    // The escape hatch has to keep working, or the fix just moves the problem:
    // whoever needs an out-of-force row must be able to say so.
    $provider = PaymentGatewayProvider::factory()->create(['code' => 'stripe', 'is_active' => true]);

    $expired = PaymentGatewayOption::factory()->create([
        'provider_id' => $provider->id,
        'effective_from' => now()->subYear(),
        'effective_to' => now()->subDay(),
    ]);

    expect(inForceNow($expired))->toBeFalse();
});
