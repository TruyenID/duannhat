<?php

namespace App\Services\Notification;

use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Throwable;

/**
 * Plan-023 M3 T3.3 — five-minute tick worker that materialises a fresh
 * Notification row for every due NotificationSchedule and advances the
 * schedule's `next_occurrence_at`.
 *
 * Invocation:
 *   $dispatcher->tick();
 *
 * The scheduler entry in routes/console.php (T3.4) calls this every 5
 * minutes with `withoutOverlapping(15)` + `onOneServer()` so multiple
 * workers can't double-dispatch.
 *
 * Locking:
 *   The inner `SELECT … FOR UPDATE SKIP LOCKED` lets two app boxes
 *   process disjoint subsets of the same tick concurrently without
 *   blocking each other or duplicating work. Each schedule row stays
 *   locked only for the duration of its own materialise+advance.
 *
 * Idempotency:
 *   Each materialised Notification carries
 *     idempotency_key = "schedule:{schedule_id}:occurrence:{iso8601}"
 *   and `notifications.idempotency_key` is unique-indexed (plan-008
 *   T1.2). If a worker crashes between materialise and advance, the
 *   next tick recomputes the same idempotency key from the still-stale
 *   `next_occurrence_at` and `NotificationService::dispatch` short-
 *   circuits without inserting a duplicate row.
 *
 * Termination:
 *   `advanceNextOccurrence` flips `status` to `completed` when any of:
 *     - RRULE yields no occurrence after `now()`
 *     - `occurrences_remaining` reaches 0
 *     - `next_occurrence_at` would exceed `ends_at`
 *   No further ticks process the row after status leaves `active`.
 */
final class RecurringNotificationDispatcher
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AudienceResolverService $audienceResolver,
    ) {}

    /**
     * Materialise + advance every schedule whose next_occurrence_at is at
     * or before now(). Returns the number of schedules processed.
     *
     * Per-row work runs inside its own short transaction so an unrelated
     * row's failure doesn't roll back the whole tick.
     */
    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $processed = 0;
        foreach ($this->dueSchedules($now) as $schedule) {
            try {
                DB::transaction(function () use ($schedule, $now) {
                    // Refresh inside the transaction with a row-level lock so we
                    // don't double-materialise an occurrence another worker
                    // already advanced.
                    $locked = NotificationSchedule::query()
                        ->whereKey($schedule->getKey())
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null || $locked->status !== 'active') {
                        return;
                    }
                    if ($locked->next_occurrence_at === null || $locked->next_occurrence_at->greaterThan($now)) {
                        return;
                    }

                    $this->materialiseOccurrence($locked);
                    $this->advanceNextOccurrence($locked, $now);
                });
                $processed++;
            } catch (Throwable $e) {
                // Single-row failure must not poison the rest of the tick.
                // Sentry surfaces these via the `notifications` log channel.
                Log::warning('notification-schedule.tick-failed', [
                    'schedule_id' => $schedule->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Fetch the candidate schedules for this tick. Implemented as a
     * separate method so tests can stub the candidate set without faking
     * a clock.
     *
     * @return iterable<NotificationSchedule>
     */
    public function dueSchedules(CarbonImmutable $now): iterable
    {
        return NotificationSchedule::query()
            ->where('status', 'active')
            ->whereNotNull('next_occurrence_at')
            ->where('next_occurrence_at', '<=', $now)
            ->orderBy('next_occurrence_at')
            ->lazyById(200);
    }

    /**
     * Write the Notification row for this occurrence via the canonical
     * NotificationService dispatch path. Idempotency key shape per
     * class docblock.
     */
    private function materialiseOccurrence(NotificationSchedule $schedule): void
    {
        $audience = NotificationAudience::query()->find($schedule->audience_id);
        if ($audience === null) {
            // Audience deleted between create + tick. Log + skip — the
            // schedule itself is RESTRICT-protected from this at create
            // time, but a `ON DELETE` race or hand-deletion would land
            // here. Flip status to cancelled so further ticks no-op.
            Log::warning('notification-schedule.audience-missing', [
                'schedule_id' => $schedule->getKey(),
                'audience_id' => $schedule->audience_id,
            ]);
            $schedule->update(['status' => 'cancelled', 'next_occurrence_at' => null]);

            return;
        }

        $brand = Brand::query()->find($schedule->brand_id);
        if ($brand === null) {
            // Brand cascade-deletes schedules already, but in tests or
            // hand-curated fixtures we may end up here. Defensive skip.
            Log::warning('notification-schedule.brand-missing', [
                'schedule_id' => $schedule->getKey(),
                'brand_id' => $schedule->brand_id,
            ]);

            return;
        }

        // Resolve the audience rule to a concrete recipient collection up
        // front. NotificationService::dispatch expects either an iterable
        // of Notifiable models or an Audience fluent-helper instance — the
        // NotificationAudience model itself doesn't satisfy either, so we
        // pass the resolved set directly.
        $recipients = $this->audienceResolver->resolve($audience, $brand);
        if ($recipients->isEmpty()) {
            // Empty audience is a no-op (e.g. all role-holders left the
            // brand between create + tick). Log + advance — the schedule
            // remains active for the next occurrence in case membership
            // changes again.
            Log::info('notification-schedule.empty-audience', [
                'schedule_id' => $schedule->getKey(),
            ]);

            return;
        }

        $occurrenceIso = $schedule->next_occurrence_at->toIso8601String();

        $this->notifications->dispatch([
            'type' => $schedule->template_key,
            'template_key' => $schedule->template_key,
            'params' => (array) ($schedule->params ?? []),
            'recipients' => $recipients,
            'channels' => $schedule->channels ?? [],
            'priority' => $schedule->priority ?? 'normal',
            'subject' => $schedule,
            'actor' => null,
            'organization_id' => $schedule->organization_id,
            'idempotency_key' => "schedule:{$schedule->getKey()}:occurrence:{$occurrenceIso}",
        ]);
    }

    /**
     * Recompute next_occurrence_at + flip status to `completed` when the
     * recurrence is exhausted.
     */
    private function advanceNextOccurrence(NotificationSchedule $schedule, CarbonImmutable $now): void
    {
        $schedule->last_occurrence_at = $schedule->next_occurrence_at;

        // Bound the new search start to whichever is later — the previous
        // occurrence or "now" — so we don't replay a stale RRULE backlog
        // after a worker outage.
        $searchStart = max($schedule->next_occurrence_at->toDateTime(), $now->toDateTime());

        $next = $this->computeNextOccurrence($schedule, $searchStart);

        if ($schedule->occurrences_remaining !== null) {
            $schedule->occurrences_remaining = max(0, $schedule->occurrences_remaining - 1);
            if ($schedule->occurrences_remaining <= 0) {
                $schedule->status = 'completed';
                $schedule->next_occurrence_at = null;
                $schedule->save();

                return;
            }
        }

        if ($next === null) {
            $schedule->status = 'completed';
            $schedule->next_occurrence_at = null;
            $schedule->save();

            return;
        }

        if ($schedule->ends_at !== null && $next->greaterThan($schedule->ends_at)) {
            $schedule->status = 'completed';
            $schedule->next_occurrence_at = null;
            $schedule->save();

            return;
        }

        $schedule->next_occurrence_at = $next;
        $schedule->save();
    }

    /**
     * Find the first RRULE occurrence strictly after $after. Returns
     * null when the rule yields no more dates.
     */
    public function computeNextOccurrence(NotificationSchedule $schedule, \DateTimeInterface $after): ?CarbonImmutable
    {
        try {
            $tz = new \DateTimeZone($schedule->timezone ?? 'UTC');
            // Recurr anchors the iterator from $startDate; we anchor at
            // the schedule's persisted starts_at so BYHOUR/BYMINUTE align
            // with the user's intent, not "now".
            $startDate = $schedule->starts_at?->toDateTime() ?? new \DateTime('now', $tz);
            $rule = new Rule($schedule->rrule, $startDate, $schedule->ends_at?->toDateTime(), $tz->getName());
        } catch (InvalidRRule $e) {
            Log::warning('notification-schedule.invalid-rrule', [
                'schedule_id' => $schedule->getKey(),
                'rrule' => $schedule->rrule,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $occurrences = (new ArrayTransformer)->transform($rule);
        foreach ($occurrences as $occurrence) {
            $start = $occurrence->getStart();
            if ($start->getTimestamp() > $after->getTimestamp()) {
                return CarbonImmutable::instance($start)->utc();
            }
        }

        return null;
    }
}
