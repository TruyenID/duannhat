<?php

namespace App\Services\Notification;

use App\Contracts\Notifiable;
use App\Models\NotificationDigestPreference;
use App\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Plan-023 M5 T5.5 — assemble a `DigestPayload` summarising the
 * notifications a user should see in their next periodic email.
 *
 * Returns `null` when the window is empty so callers can skip
 * Mail::queue + still record last_sent_at (T5.6 SendDigestJob).
 *
 * Sample cap (50) prevents the digest body from blowing past SMTP
 * size limits on noisy days; counts in the payload remain accurate
 * regardless of the cap (so the email says "+N more" instead of
 * truncating silently).
 */
final class DigestBuilderService
{
    public const SAMPLE_CAP = 50;

    public function buildFor(Notifiable $recipient, Carbon $windowStart, Carbon $windowEnd): ?DigestPayload
    {
        $pref = $recipient instanceof User
            ? NotificationDigestPreference::query()->where('user_id', $recipient->id)->first()
            : null;
        $includedPriorities = $this->priorityFilter($pref);

        $rows = NotificationRecipient::query()
            ->forRecipient($recipient)
            ->whereNull('dismissed_at')
            ->join('notifications', 'notifications.id', '=', 'notification_recipients.notification_id')
            ->select('notification_recipients.*')
            ->with(['notification.template'])
            ->whereBetween('notifications.created_at', [$windowStart, $windowEnd])
            ->when($includedPriorities !== null, fn ($q) => $q->whereIn('notifications.priority', $includedPriorities))
            ->orderByDesc('notifications.created_at')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return new DigestPayload(
            recipient: $recipient,
            windowStart: $windowStart->copy(),
            windowEnd: $windowEnd->copy(),
            totalCount: $rows->count(),
            countsByType: $this->bucketCounts($rows, fn ($r) => $r->notification?->type ?? 'unknown'),
            countsByPriority: $this->bucketCounts($rows, fn ($r) => $r->notification?->priority ?? 'normal'),
            sample: $rows->take(self::SAMPLE_CAP)->values(),
        );
    }

    /**
     * @return array<int, string>|null null = no filter
     */
    private function priorityFilter(?NotificationDigestPreference $pref): ?array
    {
        if ($pref === null) {
            return null;
        }
        $list = is_array($pref->include_priorities) ? $pref->include_priorities : [];
        // Empty array means "include nothing" — but that contradicts opting
        // in to the digest at all, so treat empty as "include all".
        if ($list === []) {
            return null;
        }

        return array_values(array_filter($list, 'is_string'));
    }

    /**
     * @param  Collection<int, NotificationRecipient>  $rows
     * @param  callable(NotificationRecipient): string  $keyFn
     * @return array<string, int>
     */
    private function bucketCounts(Collection $rows, callable $keyFn): array
    {
        return $rows
            ->groupBy(fn (NotificationRecipient $r) => $keyFn($r))
            ->map->count()
            ->all();
    }
}
