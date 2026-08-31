/**
 * Resolve an order line's display name with the correct fallback chain.
 *
 * The bug this fixes: when a product is deleted or the branch's menu is
 * re-synced, old orders keep referencing the OLD `product_sku_id`, which no
 * longer resolves in the catalog. The API then returns the line WITHOUT a
 * `product_sku` object. The UI used to read only `product_sku.product.name` /
 * `product_sku.name`, so those lines rendered as "Món không xác định" (and lost
 * their thumbnail). Meanwhile the backend already ships a denormalized name
 * snapshot (`menu_item_name` / `name`), stamped at add-time precisely so the
 * line survives its SKU being removed — the UI simply never read it.
 *
 * Fallback order:
 *   1. LIVE catalog — `product.name` (+ variant), follows the operator's locale
 *      and the current menu. Present whenever the SKU still resolves.
 *   2. SNAPSHOT — `menu_item_name` / `name`, frozen at add-time. Survives a
 *      product deletion / menu re-sync. Mirror-collapsed
 *      ("Iced Coffee · Iced Coffee" → "Iced Coffee").
 *   3. the variant snapshot, then the caller's `fallback`.
 *
 * Kept framework-agnostic (accepts a structural subset) so every order-item
 * surface — cart, table history, split-bill, toasts — resolves names the same
 * way.
 */
import { collapseMirroredToppingName } from "./topping-name";

export interface NamedOrderItem {
  product_sku?: {
    name?: string | null;
    product?: { name?: string | null } | null;
  } | null;
  /** Denormalized display-name snapshot (workstation emits `menu_item_name` + `name`). */
  menu_item_name?: string | null;
  name?: string | null;
  /** Variant-name snapshot. */
  sku_variant_name?: string | null;
}

export function orderItemDisplayName(
  item: NamedOrderItem,
  fallback: string,
): string {
  const productName = item.product_sku?.product?.name?.trim();
  const variantName = item.product_sku?.name?.trim();
  if (productName && variantName) return `${productName} — ${variantName}`;
  if (productName) return productName;
  if (variantName) return variantName;

  // SKU orphaned (menu re-sync / product deleted) → use the frozen snapshot.
  const snapshot = collapseMirroredToppingName(
    (item.menu_item_name ?? item.name ?? "").trim(),
  ).trim();
  if (snapshot) return snapshot;

  const variantSnapshot = item.sku_variant_name?.trim();
  if (variantSnapshot) return variantSnapshot;

  return fallback;
}
