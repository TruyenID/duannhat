import type { ShopMenuProductSku } from "@/types/shop";

export function formatPrice(value: number | null | undefined): string {
  if (value == null) return "—";
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value);
}

export interface SkuPrices {
  /** Shop's current selling price (may equal hq when not overridden). */
  shop: number;
  /** HQ canonical selling price — always resolvable from the 3 backend sources. */
  hq: number;
  isOverridden: boolean;
}

/**
 * Backend returns three price-related fields; this helper reduces them to
 * two meaningful values (shop vs hq) so the UI never branches on
 * `is_price_overridden` just to find the right source:
 *
 *   - sku.selling_price              — shop price
 *   - sku.default_price              — HQ price, only when is_price_overridden
 *   - sku.product_sku.selling_price  — HQ price, always when productSku loaded
 */
export function getSkuPrices(sku: ShopMenuProductSku): SkuPrices {
  const shop = sku.selling_price;
  const hq = sku.product_sku?.selling_price ?? sku.default_price ?? shop;
  return { shop, hq, isOverridden: sku.is_price_overridden };
}
