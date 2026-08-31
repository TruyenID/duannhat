<?php

/**
 * Plan-023 M3 T3.2 — sanity check the Recurr lib parses our canonical
 * RRULE shapes and the ArrayTransformer returns occurrences in
 * timezone-aware order. If this test goes red, every M3 schedule that
 * lives in DB stops advancing — surface it as a vendor regression
 * before debugging the dispatcher.
 */

use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;

it('parses a weekly RRULE with BYDAY + BYHOUR and yields the next 3 occurrences', function () {
    $tz = new DateTimeZone('Asia/Tokyo');
    $start = new DateTime('2026-05-18 09:00:00', $tz);   // Monday
    $rule = new Rule('FREQ=WEEKLY;BYDAY=MO,WE,FR;BYHOUR=9;BYMINUTE=0', $start, null, $tz->getName());

    $occurrences = (new ArrayTransformer)->transform($rule);

    $first3 = array_slice($occurrences->toArray(), 0, 3);
    expect($first3[0]->getStart()->format('Y-m-d'))->toBe('2026-05-18'); // Mon
    expect($first3[1]->getStart()->format('Y-m-d'))->toBe('2026-05-20'); // Wed
    expect($first3[2]->getStart()->format('Y-m-d'))->toBe('2026-05-22'); // Fri
});

it('respects FREQ=DAILY;COUNT for bounded recurrences', function () {
    $tz = new DateTimeZone('UTC');
    $start = new DateTime('2026-05-15 08:00:00', $tz);
    $rule = new Rule('FREQ=DAILY;COUNT=5;BYHOUR=8;BYMINUTE=0', $start, null, $tz->getName());

    $occurrences = (new ArrayTransformer)->transform($rule);

    expect($occurrences)->toHaveCount(5);
});
