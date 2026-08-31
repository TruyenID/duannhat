import type { CartItem } from "@/context/cart-context";

/**
 * Selection shape the customer order endpoints accept on
 * `items.*.toppings.*` (CustomerOrderStoreRequest). Per-topping `note` is
 * carried only when present so JSON.stringify drops the key otherwise.
 */
export interface OrderToppingPayload {
  topping_group_item_id: string;
  product_sku_id: string;
  quantity: number;
  note?: string;
}

/**
 * Flatten a CartItem's topping state into the BE wire shape. Variant SKU
 * wins when the customer picked one; otherwise the topping item's default
 * SKU. An unresolvable selection (no variant + no default sku_id) is logged
 * and skipped — the alternative is a silent `toppings_below_min` 422 on the
 * server for any mandatory group that contained the unresolved item.
 *
 * Centralised here so every checkout surface (review page, dine-in confirm,
 * order-confirm draft commit, legacy desktop + mobile checkout) speaks one
 * dialect — the previous duplication is what let the bug ship across 4 of 5
 * submit sites.
 */
export function mapCartItemToppings(item: CartItem): OrderToppingPayload[] {
  const toppings: OrderToppingPayload[] = [];
  const unresolved: string[] = [];

  if (!item.toppingQuantities || !item.product.toppingGroups) {
    return toppings;
  }

  for (const group of item.product.toppingGroups) {
    const groupQty = item.toppingQuantities[group.id];
    if (!groupQty) continue;

    for (const toppingItem of group.items) {
      const qty = groupQty[toppingItem.id];
      if (!qty || qty <= 0) continue;

      const variantId = item.toppingItemVariants?.[toppingItem.id];
      const variant = variantId
        ? toppingItem.variants?.find((v) => v.id === variantId)
        : undefined;
      const toppingSkuId = variant?.sku_id ?? toppingItem.sku_id;

      if (toppingSkuId) {
        toppings.push({
          topping_group_item_id: toppingItem.id,
          product_sku_id: toppingSkuId,
          quantity: qty,
        });
      } else {
        unresolved.push(`${group.name} > ${toppingItem.name}`);
      }
    }
  }

  if (unresolved.length > 0) {
    console.error(
      "[checkout] Cannot resolve product_sku_id for selected toppings:",
      unresolved,
      { item },
    );
  }

  return toppings;
}
