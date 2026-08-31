<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Adds the "unread inbox" reading to a model that can receive `Notification`s.
 *
 * Applied to `User`, `Customer` and `Device`, all of which also implement the
 * `App\Contracts\Notifiable` marker interface.
 *
 * #1561 (epic #962) — this trait used to declare the `notificationInbox()`
 * morphMany itself, naming `App\Models\NotificationRecipient`. That put a
 * Notifications-module model inside SharedKernel, and `SharedKernel: ~` in
 * `deptrac.yaml` says shared infrastructure depends on NOTHING. Such an edge
 * closes a two-step cycle with the allowed reverse edge, so the bottom of the
 * dependency graph was not clean.
 *
 * The relation DECLARATION now lives on each model — that is where the module
 * boundary already was: `User` (TenancyKernel), `Customer`
 * (CustomerEngagement) and `Device` (PlatformIntegration) each own their edge
 * to Notifications, and each is recorded separately in
 * `deptrac-baseline.yaml`. What stays here is the only genuinely shared thing:
 * the DEFINITION of "unread".
 *
 * Method names (`notificationInbox`, `unreadNotifications`) deliberately
 * avoid `notifications()` to not shadow Laravel's built-in
 * `Illuminate\Notifications\Notifiable` trait already used on `User` for
 * Sanctum / mail channel routing.
 */
trait ReceivesNotifications
{
    /**
     * All notification-recipient rows addressed to this model — the
     * authoritative join target for inbox queries.
     *
     * Declared by the model, not here: the morphMany target is a Notifications
     * model, and naming it from SharedKernel is the edge #1561 removed.
     */
    abstract public function notificationInbox(): MorphMany;

    /**
     * Unread notifications = recipient row has no `read_at` AND no
     * `dismissed_at`. Drives the bell-badge count + `/me/notifications`
     * default list.
     */
    public function unreadNotifications(): MorphMany
    {
        return $this->notificationInbox()
            ->whereNull('read_at')
            ->whereNull('dismissed_at');
    }
}
