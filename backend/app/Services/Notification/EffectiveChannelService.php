<?php

namespace App\Services\Notification;

use App\Contracts\Notifiable;
use App\Models\Notification;
use App\Models\NotificationChannelRoute;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Omnify\Enums\NotificationChannelEnum;
use App\Omnify\Enums\NotificationPriorityEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes the effective-channel set for a given (notification × recipient)
 * per plan-008 DESIGN.md §Effective-channel selection.
 *
 * Pipeline:
 *   routes = notification_channel_routes[org, type]?.channels
 *          ?? template.default_channels
 *          ?? ['in_app']
 *   overrides = routes.priority_overrides[priority] ?? routes.channels
 *   per-user  = overrides
 *             ∩ not-opted-out(user, type, channel)
 *             ∩ not-in-quiet-hours(user) for [realtime, email] unless urgent
 *             ∩ not-master-muted(user)   unless urgent (type opt-out still wins)
 *
 * Device recipients bypass the user preference filter — devices don't have
 * per-channel prefs. Only the routing layer applies.
 *
 * `dispatch()` calls this once per recipient in the fan-out loop; the service
 * batches + caches per-call to keep that loop to a single route lookup and a
 * single preferences query across all recipients (see ::effectiveChannelsForBatch).
 */
class EffectiveChannelService
{
    /**
     * Channels stripped while a user is in quiet hours. In-app stays out of
     * this list because it doesn't interrupt — it just sits in the inbox.
     */
    private const QUIET_HOURS_CHANNELS = [
        NotificationChannelEnum::Realtime,
        NotificationChannelEnum::Email,
        NotificationChannelEnum::Push,
    ];

    /**
     * Compute effective channels for a single recipient. Convenience wrapper
     * around the batch API — uses it internally so one-off callers get the
     * same caching semantics for free.
     *
     * @return Collection<int, NotificationChannelEnum>
     */
    public function effectiveChannelsFor(Notification $notification, Notifiable $recipient): Collection
    {
        $map = $this->effectiveChannelsForBatch($notification, [$recipient]);
        $key = $recipient->getMorphClass().':'.$recipient->getKey();

        return $map[$key] ?? collect();
    }

    /**
     * Compute effective channels for every recipient against a single
     * notification in one pass. Does one route lookup and one preferences
     * lookup total (instead of N + N in the naive per-recipient loop).
     *
     * @param  iterable<Notifiable>  $recipients
     * @return array<string, Collection<int, NotificationChannelEnum>> keyed by "{morphClass}:{id}"
     */
    public function effectiveChannelsForBatch(Notification $notification, iterable $recipients): array
    {
        $route = NotificationChannelRoute::query()
            ->where('organization_id', $notification->organization_id)
            ->where('type', $notification->type)
            ->first();

        $priority = $this->priorityEnum($notification);
        $baseChannels = $this->resolveBaseChannels($notification, $route);
        $channels = $this->applyPriorityOverrides($baseChannels, $route, $priority);

        $userIds = [];
        foreach ($recipients as $recipient) {
            if ($recipient instanceof User) {
                $userIds[] = $recipient->getKey();
            }
        }

        $prefsByUser = [];
        if ($userIds !== []) {
            $prefsByUser = NotificationPreference::query()
                ->whereIn('user_id', $userIds)
                ->get()
                ->groupBy('user_id')
                ->all();
        }

        $out = [];
        foreach ($recipients as $recipient) {
            $key = $recipient->getMorphClass().':'.$recipient->getKey();

            if (! $recipient instanceof User) {
                $out[$key] = $channels->values();

                continue;
            }

            $prefs = collect($prefsByUser[$recipient->getKey()] ?? []);
            $out[$key] = $this->applyUserPreferences($channels, $prefs, $notification, $priority)->values();
        }

        return $out;
    }

    /**
     * @return Collection<int, NotificationChannelEnum>
     */
    private function resolveBaseChannels(Notification $notification, ?NotificationChannelRoute $route): Collection
    {
        if ($route !== null && ! empty($route->channels)) {
            return $this->toEnumCollection($route->channels);
        }

        $template = $notification->template;
        if ($template !== null && ! empty($template->default_channels)) {
            return $this->toEnumCollection($template->default_channels);
        }

        return collect([NotificationChannelEnum::InApp]);
    }

    /**
     * @param  Collection<int, NotificationChannelEnum>  $baseChannels
     * @return Collection<int, NotificationChannelEnum>
     */
    private function applyPriorityOverrides(
        Collection $baseChannels,
        ?NotificationChannelRoute $route,
        NotificationPriorityEnum $priority,
    ): Collection {
        $overrides = $route?->priority_overrides ?? [];
        $priorityKey = $priority->value;
        if (! empty($overrides[$priorityKey]) && is_array($overrides[$priorityKey])) {
            return $this->toEnumCollection($overrides[$priorityKey]);
        }

        return $baseChannels;
    }

    /**
     * @param  Collection<int, NotificationChannelEnum>  $channels
     * @param  Collection<int, NotificationPreference>  $prefs  already scoped to the user
     * @return Collection<int, NotificationChannelEnum>
     */
    private function applyUserPreferences(
        Collection $channels,
        Collection $prefs,
        Notification $notification,
        NotificationPriorityEnum $priority,
    ): Collection {
        $master = $prefs->firstWhere(fn (NotificationPreference $p) => $p->type === '*' && $p->channel === '*');
        $isUrgent = $priority === NotificationPriorityEnum::Urgent;
        $inQuietHours = $master !== null && $this->isInQuietHours($master);

        return $channels->filter(function (NotificationChannelEnum $channel) use ($prefs, $master, $isUrgent, $inQuietHours, $notification) {
            // Type-specific opt-out ALWAYS wins — even over urgent bypass.
            $typePref = $prefs->first(fn (NotificationPreference $p) => $p->type === $notification->type && $p->channel === $channel->value);
            if ($typePref !== null && ! $typePref->enabled) {
                return false;
            }

            // Urgent dispatches bypass master mute + quiet hours, but not the
            // type-specific opt-out above.
            if ($isUrgent) {
                return true;
            }

            // Master mute: strip everything except in_app.
            if ($master !== null && $master->master_mute && $channel !== NotificationChannelEnum::InApp) {
                return false;
            }

            // Quiet hours: strip every channel that can interrupt the user
            // (realtime, email, push). In-app stays — silent inbox accumulation
            // is the correct UX during quiet hours, since it doesn't ring /
            // vibrate / pop a desktop toast (issue #172).
            if ($inQuietHours && in_array($channel, self::QUIET_HOURS_CHANNELS, true)) {
                return false;
            }

            return true;
        });
    }

    private function isInQuietHours(NotificationPreference $master): bool
    {
        $from = $master->quiet_from;
        $to = $master->quiet_to;
        if ($from === null || $to === null) {
            return false;
        }

        $tz = $master->quiet_timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);
        $currentMinutes = $now->hour * 60 + $now->minute;

        $fromMinutes = $this->timeStringToMinutes((string) $from);
        $toMinutes = $this->timeStringToMinutes((string) $to);

        if ($fromMinutes === $toMinutes) {
            return false;
        }

        if ($fromMinutes < $toMinutes) {
            return $currentMinutes >= $fromMinutes && $currentMinutes < $toMinutes;
        }

        // Wraps midnight (e.g. 22:00 → 07:00).
        return $currentMinutes >= $fromMinutes || $currentMinutes < $toMinutes;
    }

    private function timeStringToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return $hours * 60 + $minutes;
    }

    private function priorityEnum(Notification $notification): NotificationPriorityEnum
    {
        $priority = $notification->priority;
        if ($priority instanceof NotificationPriorityEnum) {
            return $priority;
        }

        return NotificationPriorityEnum::from((string) ($priority ?? 'normal'));
    }

    /**
     * @param  iterable<int|string, string|NotificationChannelEnum>  $values
     * @return Collection<int, NotificationChannelEnum>
     */
    private function toEnumCollection(iterable $values): Collection
    {
        $out = collect();
        foreach ($values as $value) {
            if ($value instanceof NotificationChannelEnum) {
                $out->push($value);

                continue;
            }
            $case = NotificationChannelEnum::tryFrom((string) $value);
            if ($case !== null) {
                $out->push($case);
            }
        }

        return $out->unique()->values();
    }
}
