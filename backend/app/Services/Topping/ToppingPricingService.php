<?php

declare(strict_types=1);

namespace App\Services\Topping;

use App\Models\FloatingSectionProductToppingItemOverride;
use App\Models\MenuProductToppingItemOverride;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ToppingGroupItemSku;
use App\Omnify\Enums\ToppingGroupPriceStrategyEnum;
use App\Services\Topping\Contracts\ToppingLinePricing;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Canonical pricing math for topping selections.
 *
 * Two strategies covered:
 *   - flat:         line price = Σ (selection.unit_price × selection.quantity)
 *   - free_up_to_n: expand selections by quantity (one row per individual unit),
 *                   sort DESC by unit_price, waive the first `free_quantity`,
 *                   charge the rest at full price.
 *
 * Plan 015 / OQ-1: free_up_to_n waives the MOST EXPENSIVE N selections
 * (Toast / Square industry default — gives the customer the perceived best
 * deal). Reverse to cheapest-first by flipping the sort direction in
 * priceLine() — and update the TS port at pos-web/src/app/pos/lib/topping-pricing.ts
 * in the same change to keep parity.
 *
 * Each `OrderItemTopping` row stores its OWN `unit_price` snapshot at full
 * value. The free_up_to_n discount is applied at line level only, so reports
 * / refunds keep individual snapshots intact.
 *
 * Inputs are deliberately plain arrays (not Eloquent models) so this service
 * stays pure and trivially unit-testable. Resolving the SKU → extra_price
 * lookup is the caller's job.
 */
final class ToppingPricingService implements ToppingLinePricing
{
    /**
     * Compute the per-unit topping subtotal for a single order line.
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, unit_price: float|string}>  $selections
     *                                                                                                                                         All selections that target the same topping group. Caller must
     *                                                                                                                                         filter by group before calling — mixing groups gives wrong math
     *                                                                                                                                         because the strategy/free_quantity is per-group.
     * @return array{topping_subtotal: float, breakdown: array<int, array{topping_group_item_id: string, product_sku_id: string, unit_price: float, charged: bool}>, waived_by_selection: array<int, int>}
     *                                                                                                                                                                                                     topping_subtotal: total chargeable price for one ordered unit
     *                                                                                                                                                                                                     of the parent line. Multiply by line.quantity in the caller.
     *                                                                                                                                                                                                     breakdown: per-unit explanation (one entry per individual
     *                                                                                                                                                                                                     topping unit after expanding by quantity), useful for receipt
     *                                                                                                                                                                                                     display and parity tests.
     *                                                                                                                                                                                                     waived_by_selection (#2619): per INPUT selection (same index as
     *                                                                                                                                                                                                     $selections), how many of its units free_up_to_n waived —
     *                                                                                                                                                                                                     the value order_item_toppings.waived_quantity persists. All 0
     *                                                                                                                                                                                                     under flat.
     */
    public function priceLine(
        array $selections,
        ToppingGroupPriceStrategyEnum|string|null $priceStrategy,
        int $freeQuantity,
    ): array {
        if ($selections === []) {
            return ['topping_subtotal' => 0.0, 'breakdown' => [], 'waived_by_selection' => []];
        }

        // Expand selections by per-selection quantity into individual units.
        // free_up_to_n waives N units, not N selections, so the "Mayo ×3"
        // selection contributes three units to the comparison.
        $units = [];
        foreach ($selections as $selection) {
            $qty = (int) ($selection['quantity'] ?? 1);
            $unitPrice = (float) ($selection['unit_price'] ?? 0);

            for ($i = 0; $i < $qty; $i++) {
                $units[] = [
                    'topping_group_item_id' => (string) $selection['topping_group_item_id'],
                    'product_sku_id' => (string) $selection['product_sku_id'],
                    'unit_price' => $unitPrice,
                ];
            }
        }

        $strategy = $priceStrategy instanceof ToppingGroupPriceStrategyEnum
            ? $priceStrategy->value
            : (string) $priceStrategy;

        $result = match ($strategy) {
            ToppingGroupPriceStrategyEnum::Flat->value => $this->priceFlat($units),
            ToppingGroupPriceStrategyEnum::FreeUpToN->value => $this->priceFreeUpToN($units, $freeQuantity),
            default => throw new InvalidArgumentException("Unknown price_strategy: {$strategy}"),
        };

        // #2619 — fold the per-unit `charged` flags back onto the INPUT
        // selections. The expansion above walks $selections in order and
        // appends `quantity` consecutive units each, and both strategies
        // return the breakdown in that same unit order, so selection i owns
        // the breakdown slice [offset, offset + quantity).
        $waivedBySelection = [];
        $offset = 0;
        foreach ($selections as $i => $selection) {
            $qty = (int) ($selection['quantity'] ?? 1);
            $waived = 0;
            for ($u = 0; $u < $qty; $u++) {
                if (($result['breakdown'][$offset + $u]['charged'] ?? true) === false) {
                    $waived++;
                }
            }
            $waivedBySelection[$i] = $waived;
            $offset += $qty;
        }
        $result['waived_by_selection'] = $waivedBySelection;

        return $result;
    }

    /**
     * Sum across multiple groups for a single order line.
     *
     * Caller groups selections by `topping_group_id`, calls this once with
     * the per-group buckets, and gets a final per-unit topping_subtotal +
     * a flat breakdown across all groups (useful for OrderItemTopping
     * inserts, since each row carries the full unit_price snapshot).
     *
     * @param  array<string, array{price_strategy: ToppingGroupPriceStrategyEnum|string|null, free_quantity: int, selections: array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, unit_price: float|string}>}>  $groupedSelections
     *                                                                                                                                                                                                                                                                 Keyed by topping_group_id.
     * @return array{topping_subtotal: float, breakdown: array<int, array{topping_group_id: string, topping_group_item_id: string, product_sku_id: string, unit_price: float, charged: bool}>, waived_by_selection: array<string, array<int, int>>}
     *                                                                                                                                                                                                                                              waived_by_selection (#2619): group id → (selection index within
     *                                                                                                                                                                                                                                              that group's bucket → units waived), same contract as priceLine.
     */
    public function priceLineAcrossGroups(array $groupedSelections): array
    {
        $totalSubtotal = 0.0;
        $totalBreakdown = [];
        $waivedByGroup = [];

        foreach ($groupedSelections as $groupId => $bucket) {
            $result = $this->priceLine(
                $bucket['selections'],
                $bucket['price_strategy'] ?? null,
                (int) ($bucket['free_quantity'] ?? 0),
            );
            $totalSubtotal += $result['topping_subtotal'];
            $waivedByGroup[(string) $groupId] = $result['waived_by_selection'];

            foreach ($result['breakdown'] as $entry) {
                $entry['topping_group_id'] = (string) $groupId;
                $totalBreakdown[] = $entry;
            }
        }

        return [
            'topping_subtotal' => $totalSubtotal,
            'breakdown' => $totalBreakdown,
            'waived_by_selection' => $waivedByGroup,
        ];
    }

    /**
     * Resolve the snapshot unit_price (extra_price) for a chosen
     * (ToppingGroupItem, ProductSku) pair.
     *
     * Precedence (plan-013 DESIGN.md — Phase 2 Extensions / D7-D11, and the
     * ProductToppingGroupItemOverride model docblock):
     *   1. SHOP override — menu_product_topping_item_overrides. Applied only
     *      when the caller supplies the menu line ($menuProductId). A non-null
     *      override_price on a NOT-hidden row wins over BOTH tiers below. This
     *      matches the workstation/POS resolver (tier-1 wins tier-2) so the two
     *      channels price the same topping identically (#shop-topping-override).
     *   2. HQ per-product override — product_topping_group_item_overrides.
     *      Applied only when the caller supplies the parent product + group
     *      context ($productId + $toppingGroupId). A non-null override_price
     *      on a NOT-hidden row wins over the HQ base extra_price. A NULL
     *      override_price ("use group default") or an is_hidden row does not
     *      short-circuit — resolution falls through to the base tier.
     *   3. HQ base — topping_group_item_skus.extra_price, with the simple
     *      NULL row as the fallback when no per-SKU row exists.
     *
     * Within each tier an exact per-SKU row wins over the wildcard
     * (product_sku_id IS NULL) row.
     *
     * The OFFLINE path (CatalogRevisionService snapshot) carries this tier too
     * since #1192: snapshot v3 records a second, menu_product-keyed map
     * (`topping_price_overrides`) alongside the product-keyed one, and the
     * verifier reads it first. Before that the shop tier was skipped there, so
     * an offline sale at a shop with a topping override was re-priced at the HQ
     * price and refused as tampered.
     *
     * Throws when no base price row exists — callers surface that as a
     * `topping_item_no_price` 422 (DESIGN.md error table).
     */
    public function resolveSnapshotPrice(
        string $toppingGroupItemId,
        string $productSkuId,
        ?string $productId = null,
        ?string $toppingGroupId = null,
        ?string $menuProductId = null,
        ?string $floatingSectionProductId = null,
    ): float {
        // Tier 1 — SHOP override. The line was ordered from EITHER a menu
        // product OR a floating section product; whichever owner is supplied
        // provides the tier-1 override. Both share the same shape + precedence
        // (a NOT-hidden row with a non-null override_price wins the HQ tiers).
        if ($menuProductId !== null && $toppingGroupId !== null) {
            $shopOverride = MenuProductToppingItemOverride::query()
                ->where('menu_product_id', $menuProductId)
                ->where('topping_group_id', $toppingGroupId)
                ->where('topping_group_item_id', $toppingGroupItemId)
                ->where('is_hidden', false)
                ->whereNotNull('override_price')
                ->where(function ($q) use ($productSkuId) {
                    $q->where('product_sku_id', $productSkuId)
                        ->orWhereNull('product_sku_id');
                })
                ->orderByRaw('product_sku_id IS NULL')
                ->first(['override_price', 'product_sku_id']);

            if ($shopOverride !== null) {
                return (float) $shopOverride->override_price;
            }
        }

        if ($floatingSectionProductId !== null && $toppingGroupId !== null) {
            $shopOverride = FloatingSectionProductToppingItemOverride::query()
                ->where('floating_section_product_id', $floatingSectionProductId)
                ->where('topping_group_id', $toppingGroupId)
                ->where('topping_group_item_id', $toppingGroupItemId)
                ->where('is_hidden', false)
                ->whereNotNull('override_price')
                ->where(function ($q) use ($productSkuId) {
                    $q->where('product_sku_id', $productSkuId)
                        ->orWhereNull('product_sku_id');
                })
                ->orderByRaw('product_sku_id IS NULL')
                ->first(['override_price', 'product_sku_id']);

            if ($shopOverride !== null) {
                return (float) $shopOverride->override_price;
            }
        }

        // Tier 2 — HQ per-product override. Only when product context is known.
        if ($productId !== null && $toppingGroupId !== null) {
            $override = ProductToppingGroupItemOverride::query()
                ->where('product_id', $productId)
                ->where('topping_group_id', $toppingGroupId)
                ->where('topping_group_item_id', $toppingGroupItemId)
                ->where('is_hidden', false)
                ->whereNotNull('override_price')
                ->where(function ($q) use ($productSkuId) {
                    $q->where('product_sku_id', $productSkuId)
                        ->orWhereNull('product_sku_id');
                })
                // Exact SKU (product_sku_id IS NULL → 0) sorts before the
                // wildcard NULL row (→ 1), so the scoped override wins.
                ->orderByRaw('product_sku_id IS NULL')
                ->first(['override_price', 'product_sku_id']);

            if ($override !== null) {
                // A negative override_price is a discount topping (allowed by
                // business rule). The line-level floor in CustomerOrderService
                // prevents a discount from driving the whole line below zero.
                return (float) $override->override_price;
            }
        }

        // Tier 3 — HQ base extra_price (NULL row is the simple-topping fallback).
        $row = ToppingGroupItemSku::query()
            ->where('topping_group_item_id', $toppingGroupItemId)
            ->where(function ($q) use ($productSkuId) {
                $q->where('product_sku_id', $productSkuId)
                    ->orWhereNull('product_sku_id');
            })
            ->orderByRaw('product_sku_id IS NULL')
            ->first(['extra_price', 'product_sku_id']);

        if ($row === null) {
            // DESIGN.md error contract: caller surface as 422 topping_item_no_price.
            throw ValidationException::withMessages([
                'items.toppings' => "topping_item_no_price: no extra_price row for topping_group_item_id={$toppingGroupItemId} (sku={$productSkuId} or NULL fallback)",
            ]);
        }

        // A negative extra_price is a discount topping (allowed by business
        // rule). The line-level floor in CustomerOrderService prevents a
        // discount from driving the whole line below zero.
        return (float) $row->extra_price;
    }

    /**
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, unit_price: float}>  $units
     * @return array{topping_subtotal: float, breakdown: array<int, array{topping_group_item_id: string, product_sku_id: string, unit_price: float, charged: bool}>}
     */
    private function priceFlat(array $units): array
    {
        $subtotal = 0.0;
        $breakdown = [];
        foreach ($units as $unit) {
            $subtotal += $unit['unit_price'];
            $breakdown[] = [
                'topping_group_item_id' => $unit['topping_group_item_id'],
                'product_sku_id' => $unit['product_sku_id'],
                'unit_price' => $unit['unit_price'],
                'charged' => true,
            ];
        }

        return [
            'topping_subtotal' => $this->round($subtotal),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, unit_price: float}>  $units
     * @return array{topping_subtotal: float, breakdown: array<int, array{topping_group_item_id: string, product_sku_id: string, unit_price: float, charged: bool}>}
     */
    private function priceFreeUpToN(array $units, int $freeQuantity): array
    {
        if ($freeQuantity <= 0) {
            // Defensive: free_up_to_n with 0 free_quantity is functionally
            // identical to flat. Don't reject — just behave as flat.
            return $this->priceFlat($units);
        }

        // Use a stable sort: by unit_price DESC, then keep insertion order
        // for ties. Stable so parity tests against TS get deterministic
        // breakdowns when prices repeat.
        $indexed = array_map(
            static fn (array $unit, int $i): array => [...$unit, '_idx' => $i],
            $units,
            array_keys($units),
        );
        usort($indexed, static function (array $a, array $b): int {
            return $b['unit_price'] <=> $a['unit_price']
                ?: $a['_idx'] <=> $b['_idx'];
        });

        $subtotal = 0.0;
        $waived = 0;
        // Map back to original order for the breakdown so receipts/log
        // entries follow the order the customer picked, not the sort order.
        $chargedByIdx = [];
        foreach ($indexed as $unit) {
            $charged = $waived >= $freeQuantity;
            if ($charged) {
                $subtotal += $unit['unit_price'];
            } else {
                $waived++;
            }
            $chargedByIdx[$unit['_idx']] = $charged;
        }

        $breakdown = [];
        foreach ($units as $i => $unit) {
            $breakdown[] = [
                'topping_group_item_id' => $unit['topping_group_item_id'],
                'product_sku_id' => $unit['product_sku_id'],
                'unit_price' => $unit['unit_price'],
                'charged' => $chargedByIdx[$i] ?? true,
            ];
        }

        return [
            'topping_subtotal' => $this->round($subtotal),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Currency rounding to 2 decimals. Matches Decimal(15,2) on both
     * `topping_group_item_skus.extra_price` and the new
     * `customer_order_items.topping_subtotal`. Use bankers' / half-up
     * consistently with the rest of the order math.
     */
    private function round(float $value): float
    {
        return round($value, 2);
    }
}
