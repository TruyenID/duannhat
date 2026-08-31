<?php

namespace App\Http\Resources\Kds;

use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use App\Omnify\Enums\OrderItemStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * KdsItemResource — KDS-scoped representation of a single order item.
 *
 * Hides DB-internal FK columns (customer_order_id, product_sku_id), financial
 * fields (price/tax/subtotal_cents), void audit fields (voided_at, voided_by,
 * void_reason), internal keys (idempotency_key), and Eloquent timestamps
 * (created_at, updated_at) that are irrelevant to the kitchen display.
 *
 * Exposes semantic derived fields computed server-side:
 * - `aging_minutes`               — total time since item was created
 * - `time_in_current_status_seconds` — time in the current kitchen state
 * - `is_blocked_by_toppings`      — parent item cannot advance until toppings ready
 * - `allowed_transitions`         — valid operation names for the current status
 *
 * See plan-028 DESIGN.md §1.3 for the full spec.
 */
class KdsItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerOrderItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'menu_item_name' => $item->menu_item_name,
            'quantity' => (int) $item->quantity,
            'status' => $this->resolveStatusValue($item->status),
            'note' => $item->note,

            // Derived fields
            'aging_minutes' => (int) ($item->created_at?->diffInMinutes(now()) ?? 0),
            'time_in_current_status_seconds' => $this->computeTimeInCurrentStatusSeconds($item),
            'is_blocked_by_toppings' => $this->computeIsBlockedByToppings($item),
            'allowed_transitions' => $this->computeAllowedTransitions($item),

            // Kitchen transition timestamps (ISO8601, nullable)
            'started_preparing_at' => $item->started_preparing_at?->toIso8601String(),
            'ready_at' => $item->ready_at?->toIso8601String(),
            'served_at' => $item->served_at?->toIso8601String(),

            // Toppings — embedded only when relation was eager-loaded
            'toppings' => $this->whenLoaded(
                'orderItemToppings',
                fn () => $item->orderItemToppings->map(fn (OrderItemTopping $topping) => [
                    'name' => $topping->relationLoaded('toppingGroupItem')
                        && $topping->toppingGroupItem?->relationLoaded('product')
                        ? ($topping->toppingGroupItem->product?->name ?? null)
                        : null,
                    'quantity' => (int) $topping->quantity,
                    'status' => $this->resolveStatusValue($topping->status),
                ])->values()->all(),
                default: [],
            ),
        ];
    }

    /**
     * Seconds since the item entered its current kitchen status.
     *
     * Status → anchor timestamp mapping:
     * - pending    → created_at
     * - preparing  → started_preparing_at (falls back to created_at)
     * - ready      → ready_at (falls back to started_preparing_at → created_at)
     * - served     → served_at (falls back to ready_at → created_at)
     * - voided     → created_at (not meaningful, returns total age)
     */
    private function computeTimeInCurrentStatusSeconds(CustomerOrderItem $item): int
    {
        $statusValue = $this->resolveStatusValue($item->status);

        $anchor = match ($statusValue) {
            OrderItemStatusEnum::Preparing->value => $item->started_preparing_at ?? $item->created_at,
            OrderItemStatusEnum::Ready->value => $item->ready_at ?? $item->started_preparing_at ?? $item->created_at,
            OrderItemStatusEnum::Served->value => $item->served_at ?? $item->ready_at ?? $item->created_at,
            default => $item->created_at ?? now(),
        };

        return (int) $anchor->diffInSeconds(now());
    }

    /**
     * True when the item is currently in 'preparing' status, has toppings
     * loaded, and at least one topping has not yet reached 'ready' status.
     * Returns false for all other statuses or when no toppings are loaded.
     */
    private function computeIsBlockedByToppings(CustomerOrderItem $item): bool
    {
        $statusValue = $this->resolveStatusValue($item->status);

        if ($statusValue !== OrderItemStatusEnum::Preparing->value) {
            return false;
        }

        if (! $item->relationLoaded('orderItemToppings')) {
            return false;
        }

        if ($item->orderItemToppings->isEmpty()) {
            return false;
        }

        return $item->orderItemToppings->contains(function (OrderItemTopping $topping) {
            return $this->resolveStatusValue($topping->status) !== OrderItemStatusEnum::Ready->value;
        });
    }

    /**
     * Valid operation names the KDS client may invoke for the current status.
     *
     * pending   → ["mark-preparing"]
     * preparing → ["mark-ready", "revert"]
     * ready     → ["mark-served", "revert"]
     * served    → ["revert"]
     * voided    → []
     *
     * @return array<int, string>
     */
    private function computeAllowedTransitions(CustomerOrderItem $item): array
    {
        $statusValue = $this->resolveStatusValue($item->status);

        return match ($statusValue) {
            OrderItemStatusEnum::Pending->value => ['mark-preparing'],
            OrderItemStatusEnum::Preparing->value => ['mark-ready', 'revert'],
            OrderItemStatusEnum::Ready->value => ['mark-served', 'revert'],
            OrderItemStatusEnum::Served->value => ['revert'],
            default => [],
        };
    }

    private function resolveStatusValue(mixed $status): string
    {
        return $status instanceof OrderItemStatusEnum ? $status->value : (string) $status;
    }
}
