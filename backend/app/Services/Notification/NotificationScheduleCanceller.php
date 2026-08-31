<?php

namespace App\Services\Notification;

use App\Exceptions\NotificationException;
use App\Models\NotificationSchedule;
use Carbon\CarbonImmutable;

/**
 * Plan-023 M3 T3.9 — cancel-with-freeze-window guard for NotificationSchedule.
 *
 * Pure side-effect: flip a schedule's `status` to `cancelled` + null
 * out `next_occurrence_at` if the request is more than 60 seconds away
 * from `next_occurrence_at`. Otherwise reject — once we're within the
 * freeze window, the tick worker may already be materialising the
 * occurrence and the dispatched Notification cannot be pulled back from
 * recipient inboxes.
 *
 * Lives as a tiny service rather than inside the schedule model so the
 * `Carbon\CarbonImmutable` clock argument is injectable from tests.
 *
 * Throws `NotificationException::withinFreezeWindow($remainingSeconds)`
 * (422 `within_freeze_window`) when the guard rejects.
 */
final class NotificationScheduleCanceller
{
    public const FREEZE_WINDOW_SECONDS = 60;

    public function cancel(NotificationSchedule $schedule, ?CarbonImmutable $now = null): NotificationSchedule
    {
        $now ??= CarbonImmutable::now();

        if (in_array($schedule->status, ['completed', 'cancelled'], true)) {
            return $schedule;
        }

        if ($schedule->next_occurrence_at !== null) {
            $remaining = $schedule->next_occurrence_at->getTimestamp() - $now->getTimestamp();
            if ($remaining >= 0 && $remaining < self::FREEZE_WINDOW_SECONDS) {
                throw NotificationException::withinFreezeWindow($remaining);
            }
        }

        $schedule->status = 'cancelled';
        $schedule->next_occurrence_at = null;
        $schedule->save();

        return $schedule;
    }
}
