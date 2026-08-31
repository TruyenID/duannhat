<?php

namespace App\Console\Commands\Notifications;

use App\Services\Notification\RecurringNotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan-023 M3 T3.4 — artisan entry point for the recurring tick worker.
 *
 * The scheduler calls this every 5 minutes (see routes/console.php).
 * It exists as a Command (not a callback) so the scheduler can use the
 * `withoutOverlapping(15)` + `onOneServer()` modifiers, which are only
 * available on command events.
 *
 * Manual invocation:
 *   php artisan notifications:tick-recurring-schedules
 *
 * Returns the count of schedules processed in the run via stdout so
 * crons / cloud schedulers (Vapor, Forge, AWS EventBridge) can monitor
 * throughput without parsing logs.
 */
#[Signature('notifications:tick-recurring-schedules')]
#[Description('Plan-023 M3 — materialise + advance every due NotificationSchedule.')]
final class TickRecurringSchedulesCommand extends Command
{
    public function handle(RecurringNotificationDispatcher $dispatcher): int
    {
        $processed = $dispatcher->tick();

        $this->info(sprintf('Recurring tick processed %d schedule(s).', $processed));

        return self::SUCCESS;
    }
}
