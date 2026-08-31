<?php

namespace App\Services;

use App\Models\Branch;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Carbon;

/**
 * Service for calculating pickup time estimations.
 *
 * #1160 — the estimate is a single configurable product:
 *
 *     preparation_minutes = prep_minutes_per_item x SUM(quantity)
 *
 * `prep_minutes_per_item` resolves shop override → brand default →
 * EffectiveOrderPolicyService::DEFAULT_PREP_MINUTES_PER_ITEM, so an operator
 * tunes the promise from admin instead of us guessing in code. It replaces
 * the old hardcoded `15' base + 2'/extra LINE + 5' when >10 active orders`,
 * which (a) nobody could change, (b) counted lines rather than portions —
 * two coffees read the same as one — and (c) existed in a second, subtly
 * different copy inside customer-web, so the number the customer saw did not
 * match the number stored on the order.
 *
 * customer-web now renders this same product from the same setting
 * (`effective_order_policy.prep_minutes_per_item`), so the two can no longer
 * drift. This class stays the authority: it is what lands on the order row.
 */
class CustomerPickupService
{
    public function __construct(
        private readonly EffectiveOrderPolicyService $policyService,
    ) {}

    /**
     * Calculate estimated ready time for a new order.
     *
     * @param  Branch  $branch  The branch where the order will be prepared
     * @param  array<int, array{product_sku_id?: string, quantity?: int|string}>  $items  Order items
     * @return array{estimated_ready_time: Carbon, preparation_minutes: int}
     */
    public function calculateEstimatedReadyTime(Branch $branch, array $items): array
    {
        $preparationMinutes = $this->preparationMinutes($branch, $items);

        return [
            // A wall-clock instant, not a business date — `now()` is correct
            // here (#1091): the value is stored UTC and rendered in the
            // reader's zone. Only day-grouping needs BusinessClock.
            'estimated_ready_time' => Carbon::now()->addMinutes($preparationMinutes),
            'preparation_minutes' => $preparationMinutes,
        ];
    }

    /**
     * Prep minutes for a basket: per-item setting x total quantity.
     *
     * Quantity, not line count — 2 portions of one product take as long as
     * 1 portion of two products. An empty basket yields 0: promising a
     * 15-minute wait for nothing was the old code's artefact, and the
     * takeaway service only calls this when items exist.
     *
     * @param  array<int, array{product_sku_id?: string, quantity?: int|string}>  $items
     */
    public function preparationMinutes(Branch $branch, array $items): int
    {
        $perItem = $this->prepMinutesPerItem($branch);

        $totalQuantity = 0;
        foreach ($items as $item) {
            // Absent quantity means one portion — the same reading the
            // pricing path uses for a bare line.
            $totalQuantity += max(0, (int) ($item['quantity'] ?? 1));
        }

        return $perItem * $totalQuantity;
    }

    /**
     * The branch's resolved prep minutes per item (shop ?? brand ?? default).
     */
    public function prepMinutesPerItem(Branch $branch): int
    {
        return (int) $this->policyService->resolve($branch)['prep_minutes_per_item'];
    }
}
