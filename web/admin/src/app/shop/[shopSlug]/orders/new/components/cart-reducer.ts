/**
 * Cart reducer for the POS new-order flow.
 *
 * Pure TS — no React dependency. Exported so vitest can import and call
 * the reducer directly without rendering a component.
 *
 * BR-OI01: `unit_price` is snapshotted from the menu SKU at the moment
 * `ADD_SKU` fires. Re-adding the same SKU at a different price does NOT
 * update the price — it only bumps the quantity. The price captured on
 * first add is the contract the cashier sees. See DESIGN.md §"Key
 * decisions" #3.
 */

import type { CreateOrderItemInput } from "@/services/order-service";

// =========================================================================
//  State
// =========================================================================

export interface CartItem {
  product_sku_id: string;
  name: string;
  quantity: number;
  /** Snapshot at cart-add time. Never mutated after first add. */
  unit_price: number;
  note: string;
}

export interface CartState {
  items: CartItem[];
}

export const INITIAL_CART: CartState = { items: [] };

// =========================================================================
//  Actions
// =========================================================================

export type CartAction =
  | { type: "ADD_SKU"; skuId: string; name: string; unitPrice: number }
  | { type: "REMOVE_SKU"; skuId: string }
  | { type: "CHANGE_QUANTITY"; skuId: string; quantity: number }
  | { type: "SET_NOTE"; skuId: string; note: string }
  | { type: "CLEAR" };

// =========================================================================
//  Reducer
// =========================================================================

export function cartReducer(state: CartState, action: CartAction): CartState {
  switch (action.type) {
    case "ADD_SKU": {
      const existing = state.items.find((i) => i.product_sku_id === action.skuId);
      if (existing) {
        return {
          items: state.items.map((i) =>
            i.product_sku_id === action.skuId ? { ...i, quantity: i.quantity + 1 } : i
          ),
        };
      }
      return {
        items: [
          ...state.items,
          {
            product_sku_id: action.skuId,
            name: action.name,
            quantity: 1,
            unit_price: action.unitPrice,
            note: "",
          },
        ],
      };
    }

    case "REMOVE_SKU":
      return {
        items: state.items.filter((i) => i.product_sku_id !== action.skuId),
      };

    case "CHANGE_QUANTITY": {
      if (action.quantity <= 0) {
        return {
          items: state.items.filter((i) => i.product_sku_id !== action.skuId),
        };
      }
      return {
        items: state.items.map((i) =>
          i.product_sku_id === action.skuId ? { ...i, quantity: action.quantity } : i
        ),
      };
    }

    case "SET_NOTE":
      return {
        items: state.items.map((i) =>
          i.product_sku_id === action.skuId ? { ...i, note: action.note } : i
        ),
      };

    case "CLEAR":
      return INITIAL_CART;
  }
}

// =========================================================================
//  Selectors
// =========================================================================

export function subtotal(state: CartState): number {
  return state.items.reduce((sum, i) => sum + i.quantity * i.unit_price, 0);
}

export function toCreatePayload(state: CartState): CreateOrderItemInput[] {
  return state.items.map((i) => ({
    product_sku_id: i.product_sku_id,
    quantity: i.quantity,
    unit_price: i.unit_price,
    ...(i.note ? { note: i.note } : {}),
  }));
}
