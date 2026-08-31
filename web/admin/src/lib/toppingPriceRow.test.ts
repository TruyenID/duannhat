import { describe, expect, it } from "vitest";
import { resolveNoVariantPriceRow } from "./toppingPriceRow";

describe("resolveNoVariantPriceRow (#2546)", () => {
  const SKU = "sku-1";

  it("recognises a SCOPED row — the case the bug was reported for", () => {
    // Before the fix only the wildcard was read, so this returned null, the
    // editor showed "no price", and saving created a second row the backend
    // refused.
    const scoped = { product_sku_id: SKU, price: 500 };

    expect(resolveNoVariantPriceRow([scoped], SKU)).toBe(scoped);
  });

  it("still recognises a legacy WILDCARD row", () => {
    // Rows written before scoped pricing existed. Dropping this fallback would
    // trade the reported bug for its mirror image on older data.
    const wildcard = { product_sku_id: null, price: 300 };

    expect(resolveNoVariantPriceRow([wildcard], SKU)).toBe(wildcard);
  });

  it("prefers the SCOPED row when both shapes exist", () => {
    // The precedence, which is the actual content of the fix. With the order
    // reversed both single-row tests above still pass — only this one moves.
    const wildcard = { product_sku_id: null, price: 300 };
    const scoped = { product_sku_id: SKU, price: 500 };

    expect(resolveNoVariantPriceRow([wildcard, scoped], SKU)).toBe(scoped);
  });

  it("returns null when the item has no price row at all", () => {
    // Drives the "create" path. Returning a stray row here would turn a new
    // price into an edit of somebody else's row.
    expect(resolveNoVariantPriceRow([], SKU)).toBeNull();
  });

  it("falls back to the wildcard when the product has NO active SKU", () => {
    // `activeProductSkus[0]?.id` is undefined then. Matching `product_sku_id
    // === undefined` would match nothing and silently hide an existing
    // wildcard price.
    const wildcard = { product_sku_id: null, price: 300 };

    expect(resolveNoVariantPriceRow([wildcard], undefined)).toBe(wildcard);
  });

  it("ignores a row scoped to a DIFFERENT sku", () => {
    // Multi-SKU leftovers on an item that later became no-variant. Picking one
    // up would show a price that does not apply to this SKU.
    const other = { product_sku_id: "sku-other", price: 999 };

    expect(resolveNoVariantPriceRow([other], SKU)).toBeNull();
  });
});
