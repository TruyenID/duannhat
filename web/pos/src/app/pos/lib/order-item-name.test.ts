import { describe, expect, it } from "vitest";
import { orderItemDisplayName } from "./order-item-name";

describe("orderItemDisplayName", () => {
  it("composes product — variant when the SKU resolves", () => {
    expect(
      orderItemDisplayName(
        { product_sku: { name: "Tô đặc biệt", product: { name: "Phở Bò" } } },
        "fallback",
      ),
    ).toBe("Phở Bò — Tô đặc biệt");
  });

  it("uses the product name alone when there is no variant", () => {
    expect(
      orderItemDisplayName(
        { product_sku: { name: null, product: { name: "Phở Bò" } } },
        "fallback",
      ),
    ).toBe("Phở Bò");
  });

  it("falls back to the menu_item_name snapshot when the SKU is orphaned", () => {
    // The reproduced bug: menu re-synced → product_sku absent, but the
    // snapshot survives. It must NOT render the fallback.
    expect(
      orderItemDisplayName(
        { menu_item_name: "Matcha Latte (M)", product_sku_id: "sku-x" },
        "Món không xác định",
      ),
    ).toBe("Matcha Latte (M)");
  });

  it("collapses a mirrored snapshot (Product · Product → Product)", () => {
    expect(
      orderItemDisplayName(
        { menu_item_name: "Iced Coffee (M) · Iced Coffee (M)" },
        "fallback",
      ),
    ).toBe("Iced Coffee (M)");
  });

  it("keeps a genuine Product · Variant snapshot intact", () => {
    expect(
      orderItemDisplayName({ menu_item_name: "Trà · Lớn" }, "fallback"),
    ).toBe("Trà · Lớn");
  });

  it("prefers the live catalog over the snapshot", () => {
    expect(
      orderItemDisplayName(
        {
          product_sku: { name: null, product: { name: "Phở Bò" } },
          menu_item_name: "stale name",
        },
        "fallback",
      ),
    ).toBe("Phở Bò");
  });

  it("uses the `name` alias when menu_item_name is absent", () => {
    expect(orderItemDisplayName({ name: "Egg Coffee" }, "fallback")).toBe(
      "Egg Coffee",
    );
  });

  it("falls back to sku_variant_name, then the caller fallback", () => {
    expect(
      orderItemDisplayName({ sku_variant_name: "Large" }, "fallback"),
    ).toBe("Large");
    expect(orderItemDisplayName({}, "Món không xác định")).toBe(
      "Món không xác định",
    );
  });

  it("ignores blank/whitespace names and empty product_sku", () => {
    expect(
      orderItemDisplayName(
        { product_sku: { name: "  ", product: { name: "" } }, menu_item_name: "  " },
        "fallback",
      ),
    ).toBe("fallback");
  });
});
