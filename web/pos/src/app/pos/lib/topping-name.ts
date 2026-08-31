/**
 * Topping display-name normalization.
 *
 * The workstation resolves a topping's snapshot name as
 * `"<product> · <variant>"` (mirroring the parent item's `menu_item_name`
 * convention). A topping product whose single default SKU is named the same
 * as the product therefore serialized a doubled name — "Fish sauce · Fish
 * sauce", "100% sugar · 100% sugar" — which we render verbatim in the cart.
 *
 * The upstream fix lives in workstation-app (resolveToppingSnapshot skips the
 * suffix when variant == product), but:
 *   - orders created before that fix keep the doubled name frozen in storage,
 *     and the LAN read path returns the stored value (Cloud recomputes, LAN
 *     does not), so the doubling survives on already-open tickets; and
 *   - the display should be resilient regardless of which backend/build serves
 *     the order.
 *
 * So we collapse a name that is nothing but the same label repeated across the
 * " · " separator down to a single label. A genuine variant name
 * ("Phô mai · Large") has distinct parts and is left untouched.
 *
 * Tracks godx-tempo-workstation-app#101 / godx-tempo-pos-web#26.
 */

const MIRROR_SEPARATOR = " · ";

/**
 * Collapse a topping name made of identical " · "-separated parts to a single
 * part. Returns the input unchanged when the parts differ (a real variant) or
 * when there is no separator. Case-insensitive, whitespace-trimmed compare.
 */
export function collapseMirroredToppingName(
  name: string | null | undefined,
): string {
  if (!name) return "";
  const parts = name.split(MIRROR_SEPARATOR).map((p) => p.trim());
  if (parts.length < 2) return name;

  const first = parts[0];
  const allIdentical = parts.every(
    (p) => p.localeCompare(first, undefined, { sensitivity: "accent" }) === 0,
  );

  // Preserve the original string when parts genuinely differ so real
  // "Product · Variant" toppings are never mangled.
  return allIdentical ? first : name;
}
