// src/lib/item-name.ts
import type { OrderItem } from '../types/kiosk';

const UNKNOWN = '(unknown)';

/** A usable name = present and not the server's "(unknown)" placeholder. */
function real(s?: string | null): s is string {
  return !!s && s !== UNKNOWN;
}

/**
 * Resolve the display title + optional variant sub-line for an order item.
 *
 * Title falls through `product_name → name → sku_code`, skipping the server
 * "(unknown)" placeholder (shown when the product has no localized name). The
 * variant (sku-level `name`, e.g. "Large") is the sub-line, shown only when it
 * is a real value distinct from the title.
 */
export function itemDisplay(
  item: Pick<OrderItem, 'name' | 'product_name' | 'sku_code'>,
): { title: string; variant?: string } {
  const title = real(item.product_name)
    ? item.product_name
    : real(item.name)
      ? item.name
      : item.sku_code || item.name; // last resort (may still be "(unknown)")
  const variant = real(item.name) && item.name !== title ? item.name : undefined;
  return { title, variant };
}
