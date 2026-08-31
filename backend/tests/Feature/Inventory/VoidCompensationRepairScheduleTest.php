<?php

declare(strict_types=1);

/**
 * #1257 — the drift was detected and alerted on, and then never repaired.
 *
 * Voiding a line that had already deducted stock calls compensateVoid inside a
 * try/catch, so an inventory failure cannot swallow the void itself. When it
 * fails, WritesCustomerOrders raises `[inventory.stock_drift]` at ERROR and
 * alerting hears it. But the repair — `stock:repair-void-compensation --repair`
 * — ran only when somebody remembered to type it, so the alert announced a
 * drift that then sat there until a physical count found it: the material stayed
 * deducted, indefinitely.
 */

use Illuminate\Console\Scheduling\Schedule;

it('repairs lost void compensation on a schedule, not when someone remembers', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'stock:repair-void-compensation'));

    expect($event)->not->toBeNull('stock:repair-void-compensation is not scheduled')
        // Without --repair the command is a dry run that writes nothing. A
        // schedule that only reports would leave the drift exactly where it was,
        // which is the state this fixes.
        ->and((string) $event->command)->toContain('--repair')
        ->and($event->withoutOverlapping)->toBeTrue();
});
