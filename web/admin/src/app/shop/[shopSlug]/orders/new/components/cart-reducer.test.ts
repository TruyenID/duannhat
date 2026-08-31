import { describe, it, expect } from "vitest";
import {
  cartReducer,
  INITIAL_CART,
  subtotal,
  toCreatePayload,
  type CartState,
} from "./cart-reducer";

describe("cart-reducer", () => {
  it("adds a SKU to an empty cart with correct unit_price", () => {
    const state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha Latte",
      unitPrice: 50000,
    });
    expect(state.items).toHaveLength(1);
    expect(state.items[0].product_sku_id).toBe("sku-a");
    expect(state.items[0].unit_price).toBe(50000);
    expect(state.items[0].quantity).toBe(1);
  });

  it("re-adding the same SKU at a different price bumps qty but preserves original price (BR-OI01)", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha Latte",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha Latte",
      unitPrice: 60000,
    });
    expect(state.items).toHaveLength(1);
    expect(state.items[0].quantity).toBe(2);
    expect(state.items[0].unit_price).toBe(50000);
  });

  it("changes quantity while preserving unit_price", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });

    state = cartReducer(state, {
      type: "CHANGE_QUANTITY",
      skuId: "sku-a",
      quantity: 5,
    });
    expect(state.items[0].quantity).toBe(5);
    expect(state.items[0].unit_price).toBe(50000);
  });

  it("removes item when quantity is set to 0", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });

    state = cartReducer(state, {
      type: "CHANGE_QUANTITY",
      skuId: "sku-a",
      quantity: 0,
    });
    expect(state.items).toHaveLength(0);
  });

  it("removes a SKU entirely", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });

    state = cartReducer(state, { type: "REMOVE_SKU", skuId: "sku-a" });
    expect(state.items).toHaveLength(0);
  });

  it("sets note on one SKU without affecting others", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "A",
      unitPrice: 100,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-b",
      name: "B",
      unitPrice: 200,
    });
    state = cartReducer(state, {
      type: "SET_NOTE",
      skuId: "sku-a",
      note: "Extra ice",
    });

    expect(state.items.find((i) => i.product_sku_id === "sku-a")?.note).toBe("Extra ice");
    expect(state.items.find((i) => i.product_sku_id === "sku-b")?.note).toBe("");
  });

  it("calculates subtotal correctly", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "A",
      unitPrice: 50000,
    });
    state = cartReducer(state, { type: "ADD_SKU", skuId: "sku-a", name: "A", unitPrice: 50000 });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-b",
      name: "B",
      unitPrice: 30000,
    });

    expect(subtotal(state)).toBe(2 * 50000 + 1 * 30000);
  });

  it("clears the cart back to initial state", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "A",
      unitPrice: 100,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-b",
      name: "B",
      unitPrice: 200,
    });
    state = cartReducer(state, { type: "CLEAR" });
    expect(state).toEqual(INITIAL_CART);
    expect(state.items).toHaveLength(0);
  });

  it("serializes to CreateOrderInput items with exact fields (BR-OI01)", () => {
    let state = cartReducer(INITIAL_CART, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });
    state = cartReducer(state, {
      type: "ADD_SKU",
      skuId: "sku-a",
      name: "Matcha",
      unitPrice: 50000,
    });

    const payload = toCreatePayload(state);
    expect(payload).toEqual([{ product_sku_id: "sku-a", quantity: 2, unit_price: 50000 }]);
    expect(Object.keys(payload[0])).toEqual(["product_sku_id", "quantity", "unit_price"]);
  });
});
