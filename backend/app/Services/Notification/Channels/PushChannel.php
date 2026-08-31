<?php

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelContract;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use Illuminate\Support\Facades\Log;

/**
 * Push channel — stub in Phase C (plan-012 T3.6). Returns
 * `status=skipped` and logs a breadcrumb so integration tests can assert
 * the channel was routed through without actually calling a provider.
 *
 * Real FCM/APNs wire-up is a follow-up beyond plan-012 (no provider
 * selected yet).
 */
class PushChannel implements NotificationChannelContract
{
    public function name(): string
    {
        return 'push';
    }

    public function send(Notification $notification, NotificationRecipient $recipient): DeliveryResult
    {
        Log::info('notification.push.stub', [
            'notification_id' => $notification->id,
            'recipient_type' => $recipient->recipient_type,
            'recipient_id' => $recipient->recipient_id,
        ]);

        return DeliveryResult::skipped('push channel not implemented yet');
    }
}
