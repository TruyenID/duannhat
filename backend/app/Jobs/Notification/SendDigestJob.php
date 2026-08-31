<?php

namespace App\Jobs\Notification;

use App\Mail\NotificationDigestMail;
use App\Models\NotificationDigestPreference;
use App\Models\User;
use App\Services\Notification\DigestBuilderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Plan-023 M5 T5.6 — hourly digest dispatcher.
 *
 * Scheduler calls `dispatchSync()` (or `dispatch()`) hourly. The job
 * loops every active preference and ships a digest mail to users
 * whose `delivery_time` matches the current hour in their timezone
 * AND whose `last_sent_at` is older than start-of-day (daily) or
 * start-of-week (weekly).
 *
 * Empty-window short-circuit: when DigestBuilderService returns null
 * the mail is skipped but last_sent_at IS updated, so an empty day
 * doesn't retry every hour for the same user.
 *
 * Idempotency: last_sent_at is written before Mail::queue so a job
 * crash + retry cannot send two digests for the same window.
 */
class SendDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('notifications-digest');
    }

    public function handle(DigestBuilderService $builder): int
    {
        $now = Carbon::now();
        $sent = 0;

        NotificationDigestPreference::query()
            ->where('cadence', '!=', 'off')
            ->lazyById(200)
            ->each(function (NotificationDigestPreference $pref) use ($builder, $now, &$sent) {
                if (! $this->isEligible($pref, $now)) {
                    return;
                }

                $user = User::query()->find($pref->user_id);
                if ($user === null) {
                    return;
                }

                [$windowStart, $windowEnd] = $this->windowFor($pref, $now);

                $payload = $builder->buildFor($user, $windowStart, $windowEnd);

                // Idempotency: write last_sent_at first so a crash before
                // Mail::queue completes doesn't replay the same window.
                $pref->update(['last_sent_at' => $now]);

                if ($payload === null) {
                    return;
                }

                if (empty($user->email)) {
                    Log::info('notification-digest.no-email', ['user_id' => $user->id]);

                    return;
                }

                Mail::to($user->email)->queue(new NotificationDigestMail($payload));
                $sent++;
            });

        return $sent;
    }

    private function isEligible(NotificationDigestPreference $pref, Carbon $now): bool
    {
        $tz = $pref->timezone ?: 'UTC';
        try {
            $userNow = $now->copy()->setTimezone($tz);
        } catch (\Throwable) {
            return false;
        }

        // delivery_time is "HH:MM" — match the hour, ignore the minute
        // so a 09:30-targeted preference still fires during the 09:00–09:59
        // hour window the scheduler is currently in.
        $targetHour = (int) substr((string) $pref->delivery_time, 0, 2);
        if ($userNow->hour !== $targetHour) {
            return false;
        }

        if ($pref->cadence === 'weekly') {
            if ((int) $pref->weekday !== $userNow->dayOfWeek) {
                return false;
            }
            $weekStart = $userNow->copy()->startOfWeek();
            if ($pref->last_sent_at !== null && $pref->last_sent_at->greaterThanOrEqualTo($weekStart)) {
                return false;
            }
        } else {
            // daily
            // #1091 DECIDED: a digest is addressed to a PERSON, so its window is
            // that person's day — unlike a business date, which belongs to the
            // shop. Someone in Hanoi reading a Tokyo shop's digest wants "my
            // yesterday", while the numbers inside it are still the shop's
            // business figures. This is the one place where a user clock is
            // correct on purpose.
            $dayStart = $userNow->copy()->startOfDay(); // #1091-ok: recipient's day, by decision
            if ($pref->last_sent_at !== null && $pref->last_sent_at->greaterThanOrEqualTo($dayStart)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function windowFor(NotificationDigestPreference $pref, Carbon $now): array
    {
        $tz = $pref->timezone ?: 'UTC';
        $userNow = $now->copy()->setTimezone($tz);
        if ($pref->cadence === 'weekly') {
            return [$userNow->copy()->subWeek(), $userNow->copy()];
        }

        return [$userNow->copy()->subDay(), $userNow->copy()];
    }
}
