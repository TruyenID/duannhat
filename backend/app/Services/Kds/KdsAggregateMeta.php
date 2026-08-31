<?php

namespace App\Services\Kds;

use App\Models\CustomerOrder;
use App\Omnify\Enums\OrderItemStatusEnum;
use Illuminate\Support\Collection;

/**
 * KdsAggregateMeta — Computes the aggregate meta block for GET /kds/orders.
 *
 * Semantics:
 * - late_count    = orders where aging_minutes > 10  (matches is_late in KdsOrderResource)
 * - critical_count = orders where aging_minutes >= 10 (matches priority='critical')
 * - critical_count >= late_count is NOT guaranteed at the boundary (exactly 10 min
 *   counts as critical but not late). In practice critical ⊇ late at 11+ minutes.
 */
class KdsAggregateMeta
{
    /**
     * Compute aggregate meta from a collection of CustomerOrder models.
     * Items relation must be loaded on each order for accurate item counts.
     *
     * @param  Collection<int, CustomerOrder>  $orders
     * @return array{
     *     active_count: int,
     *     late_count: int,
     *     critical_count: int,
     *     avg_age_minutes: float|int,
     *     oldest_pending_item_age_minutes: int,
     *     items_by_status: array<string, int>,
     *     fetched_at: string
     * }
     */
    public static function compute(Collection $orders): array
    {
        $active = $orders->count();
        $late = 0;
        $critical = 0;
        $totalAging = 0;
        $oldestPendingItem = 0;
        $itemsByStatus = [
            OrderItemStatusEnum::Pending->value => 0,
            OrderItemStatusEnum::Preparing->value => 0,
            OrderItemStatusEnum::Ready->value => 0,
            OrderItemStatusEnum::Served->value => 0,
        ];

        foreach ($orders as $order) {
            $aging = (int) ($order->opened_at?->diffInMinutes(now()) ?? 0);
            $totalAging += $aging;

            if ($aging > 10) {
                $late++;
            }

            if ($aging >= 10) {
                $critical++;
            }

            if ($order->relationLoaded('items')) {
                foreach ($order->items as $item) {
                    $statusValue = self::resolveStatusValue($item->status);

                    if ($statusValue === OrderItemStatusEnum::Voided->value) {
                        continue;
                    }

                    if (isset($itemsByStatus[$statusValue])) {
                        $itemsByStatus[$statusValue]++;
                    }

                    if ($statusValue === OrderItemStatusEnum::Pending->value) {
                        $itemAge = (int) ($item->created_at?->diffInMinutes(now()) ?? 0);
                        $oldestPendingItem = max($oldestPendingItem, $itemAge);
                    }
                }
            }
        }

        return [
            'active_count' => $active,
            'late_count' => $late,
            'critical_count' => $critical,
            'avg_age_minutes' => $active > 0 ? round($totalAging / $active, 1) : 0,
            'oldest_pending_item_age_minutes' => $oldestPendingItem,
            'items_by_status' => $itemsByStatus,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Resolve a BackedEnum or raw string status to its string value.
     */
    private static function resolveStatusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? $status->value : (string) $status;
    }
}
