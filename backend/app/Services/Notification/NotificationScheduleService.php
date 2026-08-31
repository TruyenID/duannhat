<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Exceptions\NotificationException;
use App\Models\NotificationSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule as RecurrRule;
use Recurr\Transformer\ArrayTransformer;

/**
 * Lifecycle of a recurring notification schedule (plan-023 M3, extracted #1666).
 *
 * Sibling of {@see NotificationScheduleCanceller}, which already owned the one
 * transition with a hard rule attached (the 60-second freeze window). The rest
 * of the state machine — first-occurrence maths, edit, pause, resume — lived in
 * `NotificationScheduleAdminController`, where the HTTP surface decided when a
 * schedule next fires.
 *
 * ## The ends_at defect this extraction fixes
 *
 * `ends_at` is enforced by an EXPLICIT comparison, never by Recurr. That is not
 * a style preference — Recurr's fourth constructor argument is NOT a limit on
 * the recurrence set. `ArrayTransformer::transform()` reads it as the event's
 * end datetime and uses it for `$start->diff($end)`, the per-occurrence
 * DURATION. The only in-rule limits are `UNTIL` and `COUNT`. Passing `ends_at`
 * there looks like a window and filters nothing.
 *
 * `RecurringNotificationDispatcher::advanceNextOccurrence()` gets this right —
 * it compares in PHP:
 *
 *     if ($schedule->ends_at !== null && $next->greaterThan($schedule->ends_at))
 *
 * `NotificationScheduleAdminController` had no such comparison on the create or
 * edit path, so a schedule whose window closes before its first occurrence
 * (`starts_at` Monday 09:00, `ends_at` Monday 23:00, `FREQ=WEEKLY;BYDAY=FR`)
 * was stamped with a Friday `next_occurrence_at`. `dueSchedules()` filters on
 * `status` and `next_occurrence_at` only, so the tick picked it up on Friday and
 * materialised it — and only THEN did `advanceNextOccurrence` notice the window
 * had closed. Exactly one broadcast escaped past the date the operator set, and
 * the schedule then completed looking as if it had behaved.
 */
final class NotificationScheduleService
{
    public function __construct(
        private readonly RecurringNotificationDispatcher $dispatcher,
    ) {}

    /**
     * First occurrence of $rrule at or after $startsAt, or null when the rule
     * yields none inside the window.
     *
     * Returns null (rather than throwing) on a malformed RRULE, preserving the
     * behaviour the controller had: the schedule is still created, just with no
     * pending occurrence, and the operator sees an empty next-run in the UI.
     */
    public function firstOccurrence(
        string $rrule,
        CarbonInterface $startsAt,
        string $timezone,
        ?CarbonInterface $endsAt = null,
    ): ?Carbon {
        try {
            // endDate stays null on purpose — see the class docblock. Recurr
            // reads it as a duration, not a window.
            $rule = new RecurrRule(
                $rrule,
                $startsAt->toDateTime(),
                null,
                (new \DateTimeZone($timezone))->getName(),
            );
        } catch (InvalidRRule) {
            return null;
        }

        $first = (new ArrayTransformer)->transform($rule)->first();

        if ($first === null) {
            return null;
        }

        $occurrence = Carbon::instance($first->getStart())->utc();

        // The window check, matching `advanceNextOccurrence` exactly.
        return $endsAt !== null && $occurrence->greaterThan($endsAt) ? null : $occurrence;
    }

    /**
     * The next $count occurrences of a PROPOSED rule, for the admin preview.
     *
     * Deliberately not `firstOccurrence()` with a loop: this one is asked about
     * a rule the operator is still typing, so a malformed RRULE is a message to
     * show them — `InvalidRRule` propagates and the caller renders 422 with the
     * parser's own text — where `firstOccurrence()` returns null and lets the
     * schedule save with no pending run.
     *
     * @return array<int, string> ATOM timestamps
     *
     * @throws InvalidRRule
     */
    public function previewOccurrences(
        string $rrule,
        \DateTimeInterface $startsAt,
        string $timezone,
        int $count = 5,
    ): array {
        $rule = new RecurrRule($rrule, $startsAt, null, (new \DateTimeZone($timezone))->getName());

        return collect((new ArrayTransformer)->transform($rule))
            ->take($count)
            ->map(fn ($o) => $o->getStart()->format(\DateTimeInterface::ATOM))
            ->values()
            ->all();
    }

    /**
     * Apply an edit. Only future occurrences are affected — a materialised
     * notification is already in recipient inboxes and cannot be recalled.
     *
     * `ends_at` joins the recompute trigger set: shrinking the window can strand
     * a pending `next_occurrence_at` beyond the new end, which is exactly the
     * defect described in the class docblock.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyEdit(NotificationSchedule $schedule, array $data): NotificationSchedule
    {
        if (in_array($schedule->status, ['completed', 'cancelled'], true)) {
            throw new NotificationException('schedule_terminal', 'schedule is terminal');
        }

        $recompute = array_key_exists('rrule', $data)
            || array_key_exists('timezone', $data)
            || array_key_exists('starts_at', $data)
            || array_key_exists('ends_at', $data);

        $schedule->fill($data);

        if ($recompute) {
            $schedule->next_occurrence_at = $this->firstOccurrence(
                $schedule->rrule,
                Carbon::parse($schedule->starts_at),
                $schedule->timezone,
                $schedule->ends_at === null ? null : Carbon::parse($schedule->ends_at),
            );
        }

        $schedule->save();

        return $schedule;
    }

    /**
     * Stop the tick worker from materialising further occurrences.
     *
     * `next_occurrence_at` is deliberately left in place: pausing is reversible
     * and the operator expects to see when it WOULD have fired.
     */
    public function pause(NotificationSchedule $schedule): NotificationSchedule
    {
        if ($schedule->status !== 'active') {
            throw new NotificationException('schedule_not_active', 'only active schedules can be paused');
        }

        $schedule->update(['status' => 'paused']);

        return $schedule;
    }

    /**
     * Resume, recomputing the next occurrence from NOW rather than from the
     * stale pending one — occurrences missed while paused stay missed, they are
     * not replayed in a burst.
     */
    public function resume(NotificationSchedule $schedule): NotificationSchedule
    {
        if ($schedule->status !== 'paused') {
            throw new NotificationException('schedule_not_paused', 'only paused schedules can be resumed');
        }

        $schedule->update([
            'status' => 'active',
            'next_occurrence_at' => $this->dispatcher->computeNextOccurrence($schedule, now()),
        ]);

        return $schedule;
    }
}
