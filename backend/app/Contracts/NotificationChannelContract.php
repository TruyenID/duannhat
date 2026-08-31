<?php

namespace App\Contracts;

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Services\Notification\Channels\DeliveryResult;

/**
 * Per-channel send contract (plan-012 T3.6). Each registered
 * implementation corresponds 1:1 to a `NotificationChannel` enum case and
 * a `notifications-<channel>` queue.
 *
 * Impls MUST:
 *   - Be pure-ish: they may touch the DB but only through the recipient
 *     row (for locale lookup etc.) — side effects beyond that (SMTP,
 *     Reverb, push provider) are the channel's responsibility.
 *   - Return a DeliveryResult rather than throwing. Transient failures
 *     should return status=failed with `error` set; the Job handles
 *     retry / backoff policy.
 *   - Be idempotent on double-dispatch (same (recipient, channel) pair
 *     should not duplicate the side effect — use provider_ref when the
 *     provider supports it).
 */
interface NotificationChannelContract
{
    public function send(Notification $notification, NotificationRecipient $recipient): DeliveryResult;

    public function name(): string;
}
