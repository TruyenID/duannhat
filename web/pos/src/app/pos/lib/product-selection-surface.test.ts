import { describe, expect, it } from "vitest";
import {
  buildItemReplacementPayload,
  buildOrderItemSelectionUpdate,
  findMenuProductBySku,
  shouldUseProductOptionsDialog,
} from "./product-selection-surface";

describe("shouldUseProductOptionsDialog", () => {
  it("uses the full dialog for a variant-only product so kitchen notes stay available", () => {
    expect(shouldUseProductOptionsDialog(2, 0)).toBe(true);
  });

  it("uses the full dialog for a topping-enabled single-SKU product", () => {
    expect(shouldUseProductOptionsDialog(1, 3)).toBe(true);
  });

  it("keeps quick-add for a simple single-SKU product", () => {
    expect(shouldUseProductOptionsDialog(1, 0)).toBe(false);
  });
});

describe("findMenuProductBySku", () => {
  it("falls back to another cached menu page when the edit query has no match", () => {
    const product = {
      id: "menu-product-pho",
      skus: [
        {
          id: "menu-sku-large",
          product_sku_id: "product-sku-large",
        },
      ],
    } as never;

    expect(
      findMenuProductBySku(
        "product-sku-large",
        [],
        [{ id: "unrelated", skus: [] } as never],
        [product],
      ),
    ).toBe(product);
  });

  it("returns undefined only when no cached product owns the order SKU", () => {
    expect(
      findMenuProductBySku("missing-sku", [
        {
          id: "menu-product-pho",
          skus: [{ product_sku_id: "other-sku" }],
        } as never,
      ]),
    ).toBeUndefined();
  });
});

describe("buildOrderItemSelectionUpdate (#1148 — no SKU keys, ever)", () => {
  it("forwards toppings and note only — the SKU keys are banned server-side", () => {
    expect(
      buildOrderItemSelectionUpdate(
        [
          {
            topping_group_item_id: "topping-cheese",
            product_sku_id: "topping-sku-cheddar",
            quantity: 2,
          },
        ],
        "no onion",
      ),
    ).toEqual({
      toppings: [
        {
          topping_group_item_id: "topping-cheese",
          product_sku_id: "topping-sku-cheddar",
          quantity: 2,
        },
      ],
      note: "no onion",
    });
  });

  it("sends explicit empty selections and null note so edit can clear old values", () => {
    expect(buildOrderItemSelectionUpdate([], undefined)).toEqual({
      toppings: [],
      note: null,
    });
  });
});

describe("buildItemReplacementPayload (#1148 — variant change = add + void)", () => {
  it("builds an addItems payload pinned to the picked menu SKU with the old line's quantity", () => {
    expect(
      buildItemReplacementPayload(
        {
          id: "menu-sku-large",
          product_sku_id: "product-sku-large",
        } as never,
        [
          {
            topping_group_item_id: "topping-cheese",
            product_sku_id: "topping-sku-cheddar",
            quantity: 1,
          },
        ],
        "no onion",
        3,
      ),
    ).toEqual([
      {
        product_sku_id: "product-sku-large",
        menu_product_sku_id: "menu-sku-large",
        quantity: 3,
        toppings: [
          {
            topping_group_item_id: "topping-cheese",
            product_sku_id: "topping-sku-cheddar",
            quantity: 1,
          },
        ],
        note: "no onion",
      },
    ]);
  });

  it("omits an empty toppings array (legacy non-topping add shape)", () => {
    expect(
      buildItemReplacementPayload(
        { id: "m1", product_sku_id: "p1" } as never,
        [],
        undefined,
        1,
      )[0].toppings,
    ).toBeUndefined();
  });
});
