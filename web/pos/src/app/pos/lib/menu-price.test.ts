import { describe, expect, it } from "vitest";
import { effectiveSkuPrice } from "./menu-price";

describe("effectiveSkuPrice", () => {
  it("uses the active Floating Section price when supplied", () => {
    expect(effectiveSkuPrice({ selling_price: 1200, effective_selling_price: 650 })).toBe(650);
  });

  it("falls back to the editable menu price", () => {
    expect(effectiveSkuPrice({ selling_price: 1200 })).toBe(1200);
  });
});
