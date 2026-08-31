<?php

/**
 * plan-040 L11 (TF.8) — the three inventory schedules must carry
 * withoutOverlapping() so a slow sweep can't be re-entered.
 */

use Illuminate\Console\Scheduling\Schedule;

dataset('inventory_schedules', [
    'scan-expiring' => ['material-lots:scan-expiring'],
    'auto-expire' => ['material-lots:auto-expire'],
    'reservations:expire' => ['material-lot-reservations:expire'],
]);

it('registers the inventory schedule with withoutOverlapping', function (string $command) {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($e) => str_contains((string) $e->command, $command));

    expect($event)->not->toBeNull("schedule for {$command} not found")
        ->and($event->withoutOverlapping)->toBeTrue();
})->with('inventory_schedules');
