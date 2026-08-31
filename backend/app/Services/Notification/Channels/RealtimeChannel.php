<?php

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelContract;
use App\Events\NotificationReceived;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Services\Notification\Contracts\BrandEventBroadcaster;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcast channel (plan-012 T4.9) — fires the `NotificationReceived` event
 * on the brand's Reverb app. Unprovisioned brands (null `reverb_app_id`)
 * skip without failing so the rest of the dispatch fan-out continues.
 */
class RealtimeChannel implements NotificationChannelContract
{
    public function __construct(private readonly BrandEventBroadcaster $broadcaster) {}

    public function name(): string
    {
        return 'realtime';
    }

    public function send(Notification $notification, NotificationRecipient $recipient): DeliveryResult
    {
        $brand = $this->resolveBrand($notification);
        if ($brand === null || empty($brand->reverb_app_id)) {
            Log::warning('notification.realtime.skipped', [
                'notification_id' => $notification->id,
                'reason' => $brand === null
                    ? 'no brand for organization'
                    : 'brand has no reverb_app_id — seeder missed this row',
            ]);

            return DeliveryResult::skipped('brand has no reverb_app_id');
        }

        try {
            $this->broadcaster->broadcastForBrand($brand, new NotificationReceived($notification, $recipient));
        } catch (Throwable $e) {
            return DeliveryResult::failed($e->getMessage());
        }

        return DeliveryResult::sent();
    }

    /**
     * Resolve the broadcasting brand for a notification. Links via
     * `organizations.console_organization_id ↔ brands.console_organization_id`
     * since the local `organizations` row mirrors the console's org.
     */
    private function resolveBrand(Notification $notification): ?Brand
    {
        $notification->loadMissing('organization');
        $console = $notification->organization?->console_organization_id;
        if ($console === null) {
            return null;
        }

        return Brand::query()
            ->where('console_organization_id', $console)
            ->whereNotNull('reverb_app_id')
            ->first();
    }
}
