import { create } from 'zustand';

import type { CartItem, ToppingSelection } from '@/types/pos';

interface CartState {
  orderId: string;
  items: CartItem[];
  setOrderId: (orderId: string) => void;
  addItem: (item: CartItem) => void;
  removeItem: (cartKey: string) => void;
  updateQty: (cartKey: string, quantity: number) => void;
  clear: () => void;
}

/**
 * Stable fingerprint that distinguishes same-SKU items with different toppings/notes.
 * Two items with identical SKU + toppings + note share a cart line; differing
 * toppings or notes produce separate lines so each person's variation is tracked.
 */
function cartKey(item: Pick<CartItem, 'menu_product_sku_id' | 'toppings' | 'note'>): string {
  const toppingPart = item.toppings
    ? item.toppings
        .slice()
        .sort((a, b) => a.topping_group_item_id.localeCompare(b.topping_group_item_id))
        .map((t: ToppingSelection) => `${t.topping_group_item_id}:${t.product_sku_id}:${t.quantity}`)
        .join('|')
    : '';
  const notePart = item.note ?? '';
  return `${item.menu_product_sku_id}__${toppingPart}__${notePart}`;
}

export { cartKey };

export const useCartStore = create<CartState>((set, get) => ({
  orderId: '',
  items: [],

  setOrderId(orderId) {
    if (get().orderId !== orderId) {
      set({ orderId, items: [] });
    }
  },

  addItem(item) {
    const key = cartKey(item);
    set((state) => {
      const existing = state.items.find((i) => cartKey(i) === key);
      if (existing) {
        return {
          items: state.items.map((i) =>
            cartKey(i) === key ? { ...i, quantity: i.quantity + item.quantity } : i,
          ),
        };
      }
      return { items: [...state.items, item] };
    });
  },

  removeItem(key) {
    set((state) => ({
      items: state.items.filter((i) => cartKey(i) !== key),
    }));
  },

  updateQty(key, quantity) {
    if (quantity <= 0) {
      get().removeItem(key);
      return;
    }
    set((state) => ({
      items: state.items.map((i) =>
        cartKey(i) === key ? { ...i, quantity } : i,
      ),
    }));
  },

  clear() {
    set({ items: [] });
  },
}));
