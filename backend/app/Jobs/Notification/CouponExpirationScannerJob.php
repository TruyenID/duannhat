<?php

namespace App\Jobs\Notification;

use App\Events\Notification\CustomNotificationEvent;
use App\Models\Coupon;
use App\Models\NotificationRuleFiring;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * Plan-023 M8 T8.5 — two-pass coupon expiry scanner.
 *
 * Runs daily at config('notifications.coupon_scan_time', '08:00') Asia/Tokyo.
 *
 * Pass 1: coupons with valid_until within the next 72h → custom.coupon.expiring
 *         Cooldown: 24h (fires at most once per day per coupon).
 *
 * Pass 2: coupons that expired within the last 28h → custom.coupon.expired
 *         Cooldown: 168h (fires at most once per coupon — 7-day lookback
 *         handles cron drift but the cooldown prevents the same coupon
 *         from re-firing across weekly boundaries).
 */
class CouponExpirationScannerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->runPass1();
        $this->runPass2();
    }

    /**
     * Pass 1: coupons expiring within 72 hours from now.
     */
    private function runPass1(): void
    {
        $window = Carbon::now()->addHours(72);

        Coupon::query()
            ->where('valid_until', '<=', $window)
            ->where('valid_until', '>', Carbon::now())
            ->cursor()
            ->each(function (Coupon $coupon) {
                if ($this->cooldownExists($coupon, 'custom.coupon.expiring', 24 * 60)) {
                    return;
                }

                $hours = (int) Carbon::now()->diffInHours($coupon->valid_until);

                Event::dispatch('custom.coupon.expiring', [
                    new CustomNotificationEvent(
                        subject: $coupon,
                        context: ['hours_remaining' => $hours, 'coupon_id' => $coupon->id],
                    ),
                ]);
            });
    }

    /**
     * Pass 2: coupons that expired since last sweep (within 28h window).
     */
    private function runPass2(): void
    {
        $since = Carbon::now()->subHours(28);

        Coupon::query()
            ->where('valid_until', '<', Carbon::now())
            ->where('valid_until', '>=', $since)
            ->cursor()
            ->each(function (Coupon $coupon) {
                if ($this->cooldownExists($coupon, 'custom.coupon.expired', 168 * 60)) {
                    return;
                }

                Event::dispatch('custom.coupon.expired', [
                    new CustomNotificationEvent(
                        subject: $coupon,
                        context: ['coupon_id' => $coupon->id],
                    ),
                ]);
            });
    }

    private function cooldownExists(Coupon $coupon, string $triggerEvent, int $minutes): bool
    {
        return NotificationRuleFiring::query()
            ->whereHas('rule', fn ($q) => $q->where('trigger_event', $triggerEvent))
            ->where('model_type', $coupon::class)
            ->where('model_id', (string) $coupon->id)
            ->where('outcome', 'matched')
            ->where('fired_at', '>', Carbon::now()->subMinutes($minutes))
            ->exists();
    }
}
