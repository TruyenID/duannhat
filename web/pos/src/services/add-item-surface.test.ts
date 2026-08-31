import { describe, expect, it } from "vitest";
import { addItemSurfaceFields } from "./order-service";

/**
 * #1320 — the one branch in the add-item path that is a MONEY decision.
 *
 * `menu_product_sku_id` names a row in `menu_product_skus`;
 * `floating_section_product_id` names one in `pos_floating_section_products`.
 * Sending a spotlight id in the menu field makes the backend look up a menu SKU
 * that does not exist and price the line off its fallback — a wrong price on a
 * line that looks perfectly ordinary afterwards. So: exactly one field, and
 * never the wrong one.
 */
describe("#1320 addItemSurfaceFields", () => {
  it("a menu SKU sends menu_product_sku_id and nothing else", () => {
    const out = addItemSurfaceFields({ id: "mps-1" });

    expect(out).toEqual({ menu_product_sku_id: "mps-1" });
    expect("floating_section_product_id" in out).toBe(false);
  });

  it("a spotlight SKU sends floating_section_product_id and NOT menu_product_sku_id", () => {
    const out = addItemSurfaceFields({
      id: "sku-1",
      floating_section_product_id: "fsp-1",
    });

    expect(out).toEqual({ floating_section_product_id: "fsp-1" });
    // The assertion that matters: the spotlight id must never leak into the
    // menu field, and the menu field must be absent entirely.
    expect("menu_product_sku_id" in out).toBe(false);
  });

  it("an empty spotlight id is treated as a menu SKU, not as a spotlight with no id", () => {
    const out = addItemSurfaceFields({ id: "mps-2", floating_section_product_id: "" });

    expect(out).toEqual({ menu_product_sku_id: "mps-2" });
  });
});
