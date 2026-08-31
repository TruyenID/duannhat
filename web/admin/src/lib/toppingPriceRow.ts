/**
 * Which stored price row a no-variant topping item is currently using (#2546).
 *
 * A no-variant item can carry its price EITHER scoped to the product's sole SKU
 * (`product_sku_id` set) OR as the wildcard row (`product_sku_id === null`).
 * Both are legal and the backend treats a scoped row for that SKU as
 * authoritative, so reading only the wildcard makes an existing scoped price
 * look like "no price yet" — the editor then submits a NEW wildcard row, which
 * the backend rejects as dead data and the operator sees a save that fails for
 * no visible reason.
 *
 * Extracted from the JSX so it can be asserted: the precedence between the two
 * shapes is the whole fix, and inline in a `<DefaultPriceEditor override={…}>`
 * prop it was reachable only by rendering the page.
 */
export interface ToppingPriceOverride {
  product_sku_id: string | null;
}

export function resolveNoVariantPriceRow<T extends ToppingPriceOverride>(
  overrides: readonly T[],
  soleSkuId: string | undefined,
): T | null {
  // Scoped first. When BOTH shapes exist for the same item — legacy wildcard
  // plus a newer scoped row — the scoped one is what the backend reads, so
  // showing the wildcard would let the operator edit a value that is not the
  // one in force.
  const scoped = soleSkuId === undefined ? undefined : overrides.find((o) => o.product_sku_id === soleSkuId);
  if (scoped !== undefined) return scoped;

  return overrides.find((o) => o.product_sku_id === null) ?? null;
}
