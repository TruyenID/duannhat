import { describe, expect, it } from "vitest";

import { describeToppingPriceRow, hasScopedToppingRow } from "./topping-price-rows";
import type { MenuToppingGroupItemSku } from "@/types/shop";

function row(overrides: Partial<MenuToppingGroupItemSku> = {}): MenuToppingGroupItemSku {
  return {
    id: "019f6efc-c8c1-7194-b66e-409801b0ab09",
    topping_group_item_id: "019f6efc-c8c0-731b-a4c4-e39baabadb6b",
    product_sku_id: null,
    extra_price: "150.00",
    ...overrides,
  };
}

const ctx = {
  hasScopedRow: false,
  itemName: "nhiều bánh",
  wildcardLabel: "Giá mặc định (biến thể chưa đặt riêng)",
};

describe("describeToppingPriceRow", () => {
  it("keeps the topping's own name when the wildcard row stands alone", () => {
    // The ordinary shape: a simple topping priced once, with no per-SKU rows.
    // Renaming this would make 51 legitimate rows in dev read worse.
    expect(describeToppingPriceRow(row(), ctx).label).toBe("nhiều bánh");
  });

  it("renames the wildcard row when it sits beside a scoped row", () => {
    // The reported bug: TOP001 ¥120 above, "nhiều bánh" ¥150 below, both under
    // a column headed "variants" — so the operator counted two variants.
    expect(describeToppingPriceRow(row(), { ...ctx, hasScopedRow: true }).label).toBe(
      ctx.wildcardLabel
    );
  });

  it("prefers a real variant label, then the SKU code, for a scoped row", () => {
    expect(
      describeToppingPriceRow(
        row({ product_sku_id: "sku-1", sku_label: "大", sku_code: "TOP001" }),
        ctx
      ).label
    ).toBe("大");

    expect(
      describeToppingPriceRow(row({ product_sku_id: "sku-1", sku_code: "TOP001" }), ctx).label
    ).toBe("TOP001");
  });

  it("falls back to a truncated id when a scoped row has neither label nor code", () => {
    expect(describeToppingPriceRow(row({ product_sku_id: "sku-1" }), ctx).label).toBe("SKU 019f6e");
  });

  it("marks a wildcard row that the resolver can never reach", () => {
    expect(describeToppingPriceRow(row({ applies: false }), ctx).appliesToNothing).toBe(true);
  });

  it("treats a missing applies flag as applied", () => {
    // The backend omits it when it could not compute the answer. Accusing a
    // working price row of doing nothing is the worse error of the two.
    expect(describeToppingPriceRow(row(), ctx).appliesToNothing).toBe(false);
    expect(describeToppingPriceRow(row({ applies: true }), ctx).appliesToNothing).toBe(false);
  });

  it("never marks a scoped row, even if the backend said applies=false", () => {
    // Only a wildcard row can be unreachable; a scoped row always prices its own
    // SKU. Guarding here keeps a backend change from mislabelling real variants.
    expect(
      describeToppingPriceRow(row({ product_sku_id: "sku-1", applies: false }), ctx)
        .appliesToNothing
    ).toBe(false);
  });
});

describe("hasScopedToppingRow", () => {
  it("detects a scoped row among wildcards", () => {
    expect(hasScopedToppingRow([row(), row({ product_sku_id: "sku-1" })])).toBe(true);
  });

  it("is false for a wildcard-only item and for an empty list", () => {
    expect(hasScopedToppingRow([row()])).toBe(false);
    expect(hasScopedToppingRow([])).toBe(false);
  });
});
