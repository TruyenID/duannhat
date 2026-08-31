import type { ShopMenuProductSku } from "../types";

/** Price the cashier should display; the backend remains authoritative. */
export function effectiveSkuPrice(
  sku: Pick<ShopMenuProductSku, "selling_price" | "effective_selling_price">,
): number {
  return Number(sku.effective_selling_price ?? sku.selling_price) || 0;
}
