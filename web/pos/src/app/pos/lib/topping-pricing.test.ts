/**
 * Parity tests for the TS port of `App\Services\Topping\ToppingPricingService`
 * (#284 slice E — money-critical coverage). Every case here mirrors a Pest
 * case in `backend/tests/Unit/Topping/ToppingPricingServiceTest.php` with the
 * same inputs and expected figures — if one side changes its algorithm, the
 * twin suite is the tripwire.
 */
import { describe, expect, it } from "vitest";

import {
  priceLine,
  priceLineAcrossGroups,
  type PricedSelection,
} from "./topping-pricing";

function sel(
  toppingGroupItemId: string,
  productSkuId: string,
  quantity: number,
  unitPrice: number,
): PricedSelection {
  return { toppingGroupItemId, productSkuId, quantity, unitPrice };
}

const flat = { price_strategy: "flat" as const, free_quantity: null };
const freeUpTo = (n: number) => ({
  price_strategy: "free_up_to_n" as const,
  free_quantity: n,
});

describe("priceLine — flat strategy (PHP parity)", () => {
  it("returns zero subtotal for empty selections", () => {
    expect(priceLine([], flat)).toEqual({ toppingSubtotal: 0, breakdown: [] });
  });

  it("three selections at 30/50/100 qty=1 → subtotal 180", () => {
    const r = priceLine(
      [sel("i1", "s1", 1, 30), sel("i2", "s2", 1, 50), sel("i3", "s3", 1, 100)],
      flat,
    );
    expect(r.toppingSubtotal).toBe(180);
  });

  it("respects per-selection quantity (50×2 + 80×1 = 180, breakdown expanded)", () => {
    const r = priceLine([sel("i1", "s1", 2, 50), sel("i2", "s2", 1, 80)], flat);
    expect(r.toppingSubtotal).toBe(180);
    expect(r.breakdown).toHaveLength(3);
  });

  it("rounds subtotal to 2 decimal places (33.333×2 + 33.334 = 100)", () => {
    const r = priceLine(
      [sel("i1", "s1", 1, 33.333), sel("i2", "s2", 1, 33.333), sel("i3", "s3", 1, 33.334)],
      flat,
    );
    expect(r.toppingSubtotal).toBe(100);
  });
});

describe("priceLine — free_up_to_n strategy (PHP parity)", () => {
  it("treats free_quantity=0 as flat", () => {
    const r = priceLine([sel("i1", "s1", 1, 50), sel("i2", "s2", 1, 80)], freeUpTo(0));
    expect(r.toppingSubtotal).toBe(130);
    expect(r.breakdown.every((e) => e.charged)).toBe(true);
  });

  it("waives the most expensive N (free=2, 50/80/100/120 → charge 130)", () => {
    const r = priceLine(
      [
        sel("i1", "s1", 1, 50),
        sel("i2", "s2", 1, 80),
        sel("i3", "s3", 1, 100),
        sel("i4", "s4", 1, 120),
      ],
      freeUpTo(2),
    );
    expect(r.toppingSubtotal).toBe(130);
    const waived = r.breakdown.filter((e) => !e.charged).map((e) => e.unitPrice);
    expect(waived.sort((a, b) => a - b)).toEqual([100, 120]);
  });

  it("expands qty into units: one selection ×3 at 50, free=1 → charge 100", () => {
    const r = priceLine([sel("i1", "s1", 3, 50)], freeUpTo(1));
    expect(r.toppingSubtotal).toBe(100);
    expect(r.breakdown).toHaveLength(3);
    expect(r.breakdown.filter((e) => !e.charged)).toHaveLength(1);
  });

  it("waives across mixed selections: [50×3, 120×1] free=1 → waive the 120, charge 150", () => {
    const r = priceLine([sel("i1", "s1", 3, 50), sel("i2", "s2", 1, 120)], freeUpTo(1));
    expect(r.toppingSubtotal).toBe(150);
  });

  it("free_quantity ≥ unit count waives everything", () => {
    const r = priceLine([sel("i1", "s1", 1, 50), sel("i2", "s2", 1, 80)], freeUpTo(5));
    expect(r.toppingSubtotal).toBe(0);
  });

  it("breakdown preserves input order after the price sort (receipt rendering)", () => {
    const r = priceLine(
      [sel("i1", "s1", 1, 50), sel("i2", "s2", 1, 120), sel("i3", "s3", 1, 80)],
      freeUpTo(1),
    );
    expect(r.breakdown.map((e) => e.toppingGroupItemId)).toEqual(["i1", "i2", "i3"]);
    expect(r.breakdown.map((e) => e.charged)).toEqual([true, false, true]);
  });

  it("stable tie-break: equal prices waive the EARLIEST picks first", () => {
    // Units [50, 50, 50], free=1 → the first unit (insertion order) is waived.
    const r = priceLine(
      [sel("i1", "s1", 1, 50), sel("i2", "s2", 1, 50), sel("i3", "s3", 1, 50)],
      freeUpTo(1),
    );
    expect(r.toppingSubtotal).toBe(100);
    expect(r.breakdown.map((e) => e.charged)).toEqual([false, true, true]);
  });
});

describe("priceLineAcrossGroups (PHP parity)", () => {
  it("sums across groups with mixed strategies (flat 80 + free 80 = 160)", () => {
    const r = priceLineAcrossGroups({
      "g-flat": {
        group: flat,
        selections: [sel("i1", "s1", 1, 30), sel("i2", "s2", 1, 50)],
      },
      "g-free": {
        group: freeUpTo(1),
        selections: [sel("i3", "s3", 1, 80), sel("i4", "s4", 1, 120)],
      },
    });
    expect(r.toppingSubtotal).toBe(160);
    expect(r.breakdown).toHaveLength(4);
  });

  it("free_quantity is PER GROUP — two groups each waive their own most expensive", () => {
    const r = priceLineAcrossGroups({
      a: { group: freeUpTo(1), selections: [sel("i1", "s1", 1, 100), sel("i2", "s2", 1, 40)] },
      b: { group: freeUpTo(1), selections: [sel("i3", "s3", 1, 90), sel("i4", "s4", 1, 30)] },
    });
    // a: waive 100, charge 40 · b: waive 90, charge 30 → 70
    expect(r.toppingSubtotal).toBe(70);
  });
});
