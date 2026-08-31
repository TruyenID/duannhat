<?php

/**
 * Device Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Contracts\Notifiable as InboxRecipient;
use App\Models\Concerns\ReceivesNotifications;
use App\Omnify\Modules\Device\Models\DeviceBaseModel;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Device — add project-specific model logic here.
 */
class Device extends DeviceBaseModel implements InboxRecipient
{
    use HasFactory, ReceivesNotifications;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DeviceFactory
    {
        return DeviceFactory::new();
    }

    /**
     * Inbox rows addressed to this device (#1561 — declared here, not in
     * `ReceivesNotifications`, so SharedKernel never names a Notifications
     * model).
     */
    public function notificationInbox(): MorphMany
    {
        return $this->morphMany(NotificationRecipient::class, 'recipient');
    }
}
