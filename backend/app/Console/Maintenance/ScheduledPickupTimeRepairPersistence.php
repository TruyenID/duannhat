<?php

declare(strict_types=1);

namespace App\Console\Maintenance;

use Illuminate\Support\Facades\DB;

/**
 * Command-local persistence boundary for the one-shot pickup-timezone repair.
 *
 * The repair rewrites a single already-computed wall-clock string per row and
 * must NOT fire order side effects (pricing, broadcasts, audit) — the order is
 * unchanged from the business point of view, only its stored timezone frame is
 * corrected. Routing it through the order facade would emit change events for a
 * correction that is invisible to the customer.
 *
 * This class is deliberately not container-bound. Runtime services must never
 * reuse maintenance-only write access (plan-047 T4.14).
 */
final class ScheduledPickupTimeRepairPersistence
{
    /**
     * Overwrite one order's scheduled_pickup_time with the corrected wall clock.
     *
     * @param  string  $wallClock  already formatted as Y-m-d H:i:s in the app timezone
     */
    public function repair(string $orderId, string $wallClock): void
    {
        DB::table('customer_orders')
            ->where('id', $orderId)
            ->update(['scheduled_pickup_time' => $wallClock]);
    }
}
