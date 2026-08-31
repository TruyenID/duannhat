<?php

namespace App\Jobs\Notification;

use App\Events\Notification\CustomNotificationEvent;
use App\Models\Device;
use App\Omnify\Enums\DeviceStatusEnum;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * Plan-023 M8 T8.4 — scheduled detector for offline devices.
 *
 * Runs every 5 minutes via Schedule in routes/console.php.
 * Cursors over active devices whose last_seen_at is older than
 * `device_offline_threshold_minutes` (default 15). Per device, an
 * atomic per-device cooldown gate decides whether to dispatch the
 * `custom.device.offline.detected` event.
 *
 * Cooldown deduplication is owned by THIS job, not by the downstream
 * rule-firing pipeline. `Cache::add` only succeeds for the first caller
 * within the cooldown window, so a device flagged at minute 0 is skipped
 * at minute 5 and concurrent multi-worker runs cannot double-fire. The
 * gate is deliberately decoupled from `notification_rule_firings`: a
 * `matched` firing is only written asynchronously by EvaluateRuleJob and
 * only in the full happy path (use_rules=true, condition matches,
 * recipients resolve, dispatch succeeds). Keying the cooldown off that
 * outcome meant every other path — shadow mode (the default), no active
 * rule, empty audience, or simply the async lag before the firing lands —
 * left the detector re-dispatching on every 5-minute tick (notification /
 * job storm). The cache gate closes that regardless of downstream state.
 */
class DeviceOfflineDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $threshold = (int) config('notifications.device_offline_threshold_minutes', 15);
        $cooldown = (int) config('notifications.device_offline_cooldown_minutes', 60);
        $cutoff = Carbon::now()->subMinutes($threshold);

        Device::query()
            ->where('status', DeviceStatusEnum::Active)
            ->where('last_seen_at', '<', $cutoff)
            ->cursor()
            ->each(function (Device $device) use ($cooldown) {
                if (! $this->claimCooldownSlot($device, $cooldown)) {
                    return;
                }

                $minutesOffline = (int) Carbon::now()->diffInMinutes($device->last_seen_at, absolute: true);

                Event::dispatch('custom.device.offline.detected', [
                    new CustomNotificationEvent(
                        subject: $device,
                        context: [
                            'minutes_offline' => $minutesOffline,
                            'device_id' => $device->id,
                        ],
                    ),
                ]);
            });
    }

    /**
     * Atomically claim the per-device cooldown slot. Returns true for the
     * first caller within the window (which should dispatch), false when a
     * slot is already held (repeat tick or a concurrent worker).
     */
    private function claimCooldownSlot(Device $device, int $cooldownMinutes): bool
    {
        if ($cooldownMinutes <= 0) {
            return true;
        }

        return Cache::add(
            $this->cooldownKey($device),
            Carbon::now()->toIso8601String(),
            Carbon::now()->addMinutes($cooldownMinutes),
        );
    }

    private function cooldownKey(Device $device): string
    {
        return "notifications:device-offline:cooldown:{$device->id}";
    }
}
