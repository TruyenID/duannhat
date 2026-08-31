<?php

namespace App\Http\Resources\Kds;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Table;
use App\Models\Zone;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * KdsOrderResource — KDS-scoped representation of a customer order.
 *
 * Hides DB-internal FK columns (organization_id, brand_id, console_*_id,
 * created_by_id, customer_id, branch_id), financial fields (subtotal_cents,
 * tax_cents, total_cents, discount_cents, payment_status), audit timestamps
 * (created_at, updated_at, deleted_at, cancelled_at), and cancellation audit
 * fields (cancelled_by, cancellation_reason) that are irrelevant to the KDS.
 *
 * Exposes semantic derived fields computed server-side:
 * - `aging_minutes`              — time since order was opened
 * - `is_late`                    — true if aging > 10 minutes
 * - `priority`                   — normal | warning | critical
 * - `pending_items_count`        — items with status=pending (voided excluded)
 * - `preparing_items_count`      — items with status=preparing (voided excluded)
 * - `ready_items_count`          — items with status=ready (voided excluded)
 * - `oldest_pending_age_minutes` — max age among pending items; 0 if none
 * - `can_bump_all`               — false if order finalized or no actionable items
 *
 * See plan-028 DESIGN.md §1.2 for the full spec.
 */
class KdsOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerOrder $order */
        $order = $this->resource;

        $items = $order->relationLoaded('items') ? $order->items : null;

        $agingMinutes = (int) ($order->opened_at?->diffInMinutes(now()) ?? 0);

        return [
            'id' => $order->id,
            'order_code' => $order->order_code,
            'order_type' => $order->order_type,
            'guest_count' => $order->guest_count,
            'note' => $order->note,

            // Timestamps
            'opened_at' => $order->opened_at?->toIso8601String(),

            // Derived timing fields
            'aging_minutes' => $agingMinutes,
            'is_late' => $agingMinutes > 10,
            'priority' => $this->computePriority($agingMinutes),

            // Aggregate item counts (voided excluded)
            'pending_items_count' => $this->countItemsByStatus($items, OrderItemStatusEnum::Pending),
            'preparing_items_count' => $this->countItemsByStatus($items, OrderItemStatusEnum::Preparing),
            'ready_items_count' => $this->countItemsByStatus($items, OrderItemStatusEnum::Ready),
            'oldest_pending_age_minutes' => $this->computeOldestPendingAgeMinutes($items),
            'can_bump_all' => $this->computeCanBumpAll($order, $items),

            // Table embed — single table object (first of many for merged-table scenario)
            'table' => $this->whenLoaded('tables', function () use ($order) {
                /** @var Table|null $table */
                $table = $order->tables->first();

                if (! $table) {
                    return null;
                }

                return [
                    'id' => $table->id,
                    'code' => $table->code,
                    'zone' => $table->relationLoaded('zone') && $table->zone instanceof Zone
                        ? $table->zone->name
                        : null,
                ];
            }),

            // Items embed — voided items filtered out before passing to KdsItemResource
            'items' => $this->whenLoaded('items', function () use ($items, $request) {
                $active = $items->reject(
                    fn (CustomerOrderItem $item) => $this->resolveStatusValue($item->status) === OrderItemStatusEnum::Voided->value
                );

                return KdsItemResource::collection($active)->toArray($request);
            }),
        ];
    }

    /**
     * Derive priority bucket from aging minutes.
     */
    private function computePriority(int $agingMinutes): string
    {
        return match (true) {
            $agingMinutes < 5 => 'normal',
            $agingMinutes < 10 => 'warning',
            default => 'critical',
        };
    }

    /**
     * Count items matching the given status, excluding voided.
     *
     * @param  Collection<int, CustomerOrderItem>|null  $items
     */
    private function countItemsByStatus(?Collection $items, OrderItemStatusEnum $status): int
    {
        if (! $items) {
            return 0;
        }

        return $items->filter(
            fn (CustomerOrderItem $item) => $this->resolveStatusValue($item->status) === $status->value
        )->count();
    }

    /**
     * Return the aging minutes of the oldest pending item, or 0 if none.
     *
     * @param  Collection<int, CustomerOrderItem>|null  $items
     */
    private function computeOldestPendingAgeMinutes(?Collection $items): int
    {
        if (! $items) {
            return 0;
        }

        $pendingItems = $items->filter(
            fn (CustomerOrderItem $item) => $this->resolveStatusValue($item->status) === OrderItemStatusEnum::Pending->value
        );

        if ($pendingItems->isEmpty()) {
            return 0;
        }

        return (int) $pendingItems->max(
            fn (CustomerOrderItem $item) => $item->created_at?->diffInMinutes(now()) ?? 0
        );
    }

    /**
     * Determine whether the KDS client may bump all items on this order.
     *
     * Returns false when:
     * - The order status is voided or closed (finalized)
     * - There are no items in pending or preparing state
     *
     * @param  Collection<int, CustomerOrderItem>|null  $items
     */
    private function computeCanBumpAll(CustomerOrder $order, ?Collection $items): bool
    {
        $orderStatus = $this->resolveStatusValue($order->status);

        if (in_array($orderStatus, [
            CustomerOrderStatusEnum::Voided->value,
            CustomerOrderStatusEnum::Closed->value,
        ], true)) {
            return false;
        }

        if (! $items) {
            return false;
        }

        return $items->contains(
            fn (CustomerOrderItem $item) => in_array(
                $this->resolveStatusValue($item->status),
                [OrderItemStatusEnum::Pending->value, OrderItemStatusEnum::Preparing->value],
                true
            )
        );
    }

    /**
     * Resolve enum or raw string status to its string value.
     */
    private function resolveStatusValue(mixed $status): string
    {
        return $status instanceof BackedEnum ? $status->value : (string) $status;
    }
}
