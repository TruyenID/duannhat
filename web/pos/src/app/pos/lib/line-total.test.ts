// @vitest-environment node
import { describe, expect, it } from "vitest";
import { lineSubtotal, perUnitPrice } from "./line-total";

/**
 * A shop reported the underlying defect on 2026-08-13: a dish with a paid extra,
 * quantity raised to 3, priced the dish ×3 and the extra ×1 on the printed slip
 * and on the admin order detail. These pin the rule those surfaces re-typed.
 */
describe("perUnitPrice", () => {
  it("adds the per-unit extras to the unit price", () => {
    expect(perUnitPrice(1000, 100)).toBe(1100);
  });

  it("does NOT divide the extras by anything", () => {
    // The split-bill family's version of the same bug: `topping_subtotal` is
    // already per unit, so there is no line quantity to spread it over.
    expect(perUnitPrice(1000, 300)).toBe(1300);
  });

  it("reads the decimal strings the API sends", () => {
    // Money crosses the wire as a string from Laravel's decimal cast.
    expect(perUnitPrice("1000.00", "100.00")).toBe(1100);
  });

  it("treats a missing topping subtotal as zero, not NaN", () => {
    expect(perUnitPrice(1000, null)).toBe(1000);
    expect(perUnitPrice(1000, undefined)).toBe(1000);
  });

  it("keeps a discount topping negative", () => {
    // A "remove" modifier can carry a negative price. The engine clamps the
    // per-unit result at zero, never below; this helper does the arithmetic
    // and leaves the clamping to the engine that owns it.
    expect(perUnitPrice(1000, -100)).toBe(900);
  });
});

describe("lineSubtotal", () => {
  it("multiplies BOTH terms by the quantity", () => {
    // 3 × (1000 + 100) = 3300. The reported bug produced 3100.
    expect(lineSubtotal(1000, 100, 3)).toBe(3300);
    expect(lineSubtotal(1000, 100, 3)).not.toBe(3 * 1000 + 100);
  });

  it("matches the single-unit case that always looked right", () => {
    expect(lineSubtotal(1000, 100, 1)).toBe(1100);
  });

  it("is zero for a zero-quantity line rather than the bare extras", () => {
    // `qty × unit + topping` would have returned the extras on a zero line —
    // money out of nowhere.
    expect(lineSubtotal(1000, 100, 0)).toBe(0);
  });

  it("reads the decimal strings the API sends", () => {
    expect(lineSubtotal("1000.00", "100.00", "3")).toBe(3300);
  });
});
