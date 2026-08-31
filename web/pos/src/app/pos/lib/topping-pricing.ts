/**
 * Frontend port of `App\Services\Topping\ToppingPricingService`.
 *
 * Pure pricing math for topping selections. Used as a live preview in
 * `ProductOptionsDialog` so the running total updates without a server
 * roundtrip on every checkbox toggle.
 *
 * **Source of truth is the PHP service** — the wire payload always reaches
 * `CustomerOrderService::addItems` and the persisted `topping_subtotal`
 * comes from the PHP implementation. This file MUST mirror the PHP
 * algorithm exactly. Parity tests live next to this file (Vitest) and
 * mirror the Pest test fixtures in `tests/Unit/Topping/`.
 *
 * Plan 015 / OQ-1: free_up_to_n waives the MOST EXPENSIVE N selections
 * (Toast/Square industry default, customer-friendly framing). Reverse
 * the sort comparator to flip to cheapest-first — and update the PHP
 * service in the same change.
 */

import type {
  ShopMenuToppingGroup,
  ToppingPriceStrategy,
} from "@/app/pos/types";

/**
 * One topping selection during dialog interaction. Mirrors PHP shape.
 *
 * `unitPrice` is resolved client-side by reading
 * `ShopMenuToppingItemSku.extra_price` for the chosen `productSkuId`,
 * with NULL row fallback for simple toppings.
 */
export interface PricedSelection {
  toppingGroupItemId: string;
  productSkuId: string;
  quantity: number;
  unitPrice: number;
}

export interface PricingBreakdownEntry {
  toppingGroupItemId: string;
  productSkuId: string;
  unitPrice: number;
  charged: boolean;
}

export interface PricingResult {
  /** Per-unit topping cost — multiply by line.quantity in the caller. */
  toppingSubtotal: number;
  /** One entry per individual unit (after expanding by qty). */
  breakdown: PricingBreakdownEntry[];
}

/**
 * Compute per-unit topping subtotal for selections within ONE group.
 * Caller must filter by group before calling — strategy/free_quantity
 * is per-group, mixing groups gives wrong math.
 */
export function priceLine(
  selections: PricedSelection[],
  group: Pick<
    ShopMenuToppingGroup,
    "price_strategy" | "free_quantity"
  >,
): PricingResult {
  if (selections.length === 0) {
    return { toppingSubtotal: 0, breakdown: [] };
  }

  // Expand by quantity into individual units. free_up_to_n waives N
  // units, not N selections — "Mayo ×3" contributes 3 units to the sort.
  const units = selections.flatMap((s) =>
    Array.from({ length: Math.max(1, s.quantity) }, () => ({
      toppingGroupItemId: s.toppingGroupItemId,
      productSkuId: s.productSkuId,
      unitPrice: s.unitPrice,
    })),
  );

  const strategy: ToppingPriceStrategy = group.price_strategy;
  if (strategy === "flat") {
    return priceFlat(units);
  }
  if (strategy === "free_up_to_n") {
    return priceFreeUpToN(units, group.free_quantity ?? 0);
  }
  // Unknown strategy — defensively treat as flat. Server-side will reject.
  return priceFlat(units);
}

/**
 * Sum across multiple groups for one line. Pass `groupedSelections`
 * keyed by `topping_group_id` with each bucket's group config + the
 * subset of selections targeting that group. Mirrors PHP
 * `priceLineAcrossGroups`.
 */
export function priceLineAcrossGroups(
  groupedSelections: Record<
    string,
    {
      group: Pick<ShopMenuToppingGroup, "price_strategy" | "free_quantity">;
      selections: PricedSelection[];
    }
  >,
): PricingResult {
  let total = 0;
  const breakdown: PricingBreakdownEntry[] = [];

  for (const bucket of Object.values(groupedSelections)) {
    const result = priceLine(bucket.selections, bucket.group);
    total += result.toppingSubtotal;
    for (const entry of result.breakdown) {
      breakdown.push(entry);
    }
  }

  return { toppingSubtotal: round2(total), breakdown };
}

interface Unit {
  toppingGroupItemId: string;
  productSkuId: string;
  unitPrice: number;
}

function priceFlat(units: Unit[]): PricingResult {
  let subtotal = 0;
  const breakdown: PricingBreakdownEntry[] = [];
  for (const unit of units) {
    subtotal += unit.unitPrice;
    breakdown.push({ ...unit, charged: true });
  }
  return { toppingSubtotal: round2(subtotal), breakdown };
}

function priceFreeUpToN(units: Unit[], freeQuantity: number): PricingResult {
  if (freeQuantity <= 0) {
    return priceFlat(units);
  }

  // Stable sort by unitPrice DESC, ties resolved by original insertion
  // index. Stable so parity tests against PHP get deterministic
  // breakdowns when prices repeat.
  const indexed = units.map((u, idx) => ({ ...u, _idx: idx }));
  indexed.sort((a, b) => {
    const cmp = b.unitPrice - a.unitPrice;
    return cmp !== 0 ? cmp : a._idx - b._idx;
  });

  let subtotal = 0;
  let waived = 0;
  const chargedByIdx = new Map<number, boolean>();
  for (const u of indexed) {
    const charged = waived >= freeQuantity;
    if (charged) {
      subtotal += u.unitPrice;
    } else {
      waived++;
    }
    chargedByIdx.set(u._idx, charged);
  }

  // Map back to original order so receipts/log entries follow the
  // customer's pick order, not the sort order.
  const breakdown: PricingBreakdownEntry[] = units.map((u, idx) => ({
    toppingGroupItemId: u.toppingGroupItemId,
    productSkuId: u.productSkuId,
    unitPrice: u.unitPrice,
    charged: chargedByIdx.get(idx) ?? true,
  }));

  return { toppingSubtotal: round2(subtotal), breakdown };
}

/**
 * Currency rounding to 2 decimals. Decimal(15,2) on backend; PHP uses
 * `round(..., 2)` (half-away-from-zero). JS `Math.round` differs for
 * 0.5 ties — to keep parity with PHP we apply the standard
 * (Math.round((x + Number.EPSILON) * 100) / 100) idiom.
 */
function round2(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100;
}
