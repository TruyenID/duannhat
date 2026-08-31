import type {
  ShopMenuProduct,
  ShopMenuProductSku,
  ToppingSelection,
} from "../types";
import type { OrderItemUpdateInput } from "@/services/order-service";

/**
 * Products that need any staff choice use the full options dialog. This keeps
 * variant-only products on the same surface as topping-enabled products, so
 * the kitchen note field is never lost just because topping_groups is empty.
 */
export function shouldUseProductOptionsDialog(
  variantCount: number,
  toppingGroupCount: number,
): boolean {
  return variantCount > 1 || toppingGroupCount > 0;
}

/**
 * Resolve a cart line back to the menu-product surface that owns its SKU.
 *
 * The catalog and page-level edit query can have different pagination/search
 * keys. Edit mode must search every already-cached product page instead of
 * silently rendering nothing when the page-level request is still loading.
 */
export function findMenuProductBySku(
  productSkuId: string,
  ...productGroups: ReadonlyArray<ReadonlyArray<ShopMenuProduct>>
): ShopMenuProduct | undefined {
  for (const products of productGroups) {
    const match = products.find(
      (product) =>
        product.skus?.some((sku) => sku.product_sku_id === productSkuId) ??
        false,
    );
    if (match) return match;
  }
  return undefined;
}

/**
 * Build the atomic cart-line selection replacement payload used by edit mode.
 * Keeping this outside the page component makes it impossible to accidentally
 * accept a newly selected SKU in the dialog and then omit it from PATCH.
 */
/**
 * #1148 — a line's SKU is IMMUTABLE server-side (Cloud + workstation both
 * 409). In-place edits carry toppings/note only; a different variant goes
 * through buildItemReplacementPayload (add new line, then void the old one).
 */
export function buildOrderItemSelectionUpdate(
  toppings: ToppingSelection[],
  note: string | undefined,
): OrderItemUpdateInput {
  return {
    toppings,
    note: note ?? null,
  };
}

/** addItems payload replacing a line whose variant changed (#1148 void+re-add). */
export function buildItemReplacementPayload(
  sku: ShopMenuProductSku,
  toppings: ToppingSelection[],
  note: string | undefined,
  quantity: number,
) {
  return [
    {
      product_sku_id: sku.product_sku_id,
      // MenuProductSku id — pins the backend price/tax lookup to THIS
      // menu's override, same as useCartItemActions' addItem.
      menu_product_sku_id: sku.id,
      quantity,
      toppings: toppings.length > 0 ? toppings : undefined,
      note: note,
    },
  ];
}
