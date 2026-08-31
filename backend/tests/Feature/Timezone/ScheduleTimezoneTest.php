<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * #1161 — the scheduler's wall clock.
 *
 * A cron has ONE wall clock, so daily sweeps follow the head-office rhythm
 * (`app.operations_timezone`). What made that fragile was the DEFAULT: Laravel
 * reads `app.schedule_timezone` and falls back to `app.timezone`, which here is
 * the storage clock (UTC). So `->timezone(...)` was opt-in, and forgetting it
 * failed silently — nothing errors, the job simply runs nine hours off.
 *
 * Three did. `payments:check-ledger-drift` (03:20), `catalog:rebuild-revisions`
 * (03:40) and `stock:repair-void-compensation` (04:10) were all scheduled
 * overnight because they walk large tables — and all three were firing at
 * 12:20 / 12:40 / 13:10 JST, inside the lunch rush they were placed to avoid.
 * The ledger-drift comment even claimed "03:20 in the operations timezone".
 *
 * These assertions read the REGISTERED schedule, not the config, so they still
 * hold if someone pins a timezone at the call site.
 */

/** @return array<string, Event> description => event */
function scheduledEventsByDescription(): array
{
    $byDescription = [];

    foreach (app(Schedule::class)->events() as $event) {
        $byDescription[(string) $event->description] = $event;
    }

    return $byDescription;
}

it('runs every wall-clock schedule on the operations timezone', function () {
    $operations = (string) config('app.operations_timezone');

    // Interval schedules (everyMinute / hourly / everyFiveMinutes) are
    // offset-independent, so only wall-clock ones are asserted here.
    $wallClock = [];
    foreach (scheduledEventsByDescription() as $description => $event) {
        // "m H * * *" — a daily run at a named hour and minute.
        if (preg_match('/^\d+ \d+ \* \* \*$/', (string) $event->expression) === 1) {
            $wallClock[$description] = (string) ($event->timezone ?? config('app.timezone'));
        }
    }

    expect($wallClock)->not->toBeEmpty('No daily schedules found — the scan is broken, not the schedule.');

    $offenders = array_filter($wallClock, fn (string $tz) => $tz !== $operations);

    expect($offenders)->toBe([], implode("\n", [
        "These daily schedules do not run on the operations timezone ({$operations}).",
        'A wall-clock time in routes/console.php means an operating hour; UTC is the',
        'storage clock and is nobody\'s morning:',
        ...array_map(fn ($d, $tz) => "  {$d} → {$tz}", array_keys($offenders), $offenders),
    ]));
});

it('keeps the overnight sweeps overnight at head office', function (string $description, int $expectedHour) {
    $event = scheduledEventsByDescription()[$description] ?? null;

    expect($event)->not->toBeNull("Schedule [{$description}] is gone — update this test with it.");

    // The hour as the operations timezone reads it, which is the whole point:
    // the same "03:20" meant 12:20 JST before app.schedule_timezone was set.
    $hour = (int) explode(' ', (string) $event->expression)[1];

    expect($hour)->toBe($expectedHour)
        ->and((string) ($event->timezone ?? config('app.timezone')))
        ->toBe((string) config('app.operations_timezone'));

    // And the substantive claim: this is off-peak, not lunchtime.
    expect($hour)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(5);
})->with([
    'ledger drift audit' => ['payments.check-ledger-drift', 3],
    'catalog revision rebuild' => ['catalog.rebuild-revisions', 3],
    'void-compensation repair' => ['stock.repair-void-compensation', 4],
]);
