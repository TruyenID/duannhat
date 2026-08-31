<?php

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelContract;
use App\Models\Notification;
use App\Models\NotificationRecipient;

/**
 * In-app channel — inbox + bell (plan-012 T3.6). No external side
 * effect; the `notification_recipients` row is enough for `/me/*` to
 * surface the notification. Marks the delivery `sent` synchronously.
 */
class InAppChannel implements NotificationChannelContract
{
    public function name(): string
    {
        return 'in_app';
    }

    public function send(Notification $notification, NotificationRecipient $recipient): DeliveryResult
    {
        return DeliveryResult::sent();
    }
}
