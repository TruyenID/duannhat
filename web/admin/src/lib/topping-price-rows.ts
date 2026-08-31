import type { MenuToppingGroupItemSku } from "@/types/shop";

/**
 * How one `topping_group_item_skus` row should read in the admin table.
 *
 * The shop-menu and shop-floating-section topping panels render the same table
 * from the same payload, so this decision lives in one place — it was already
 * duplicated verbatim in both, and #1275 was fixed in one of them first.
 */
export interface ToppingPriceRowDescriptor {
  /** Text for the "variant" column. */
  label: string;
  /** True when the row can never be read, so it must not pose as a variant. */
  appliesToNothing: boolean;
}

export interface ToppingPriceRowContext {
  /** Does the item have at least one row scoped to a real SKU? */
  hasScopedRow: boolean;
  /** The topping item's own display name. */
  itemName: string | null | undefined;
  /** Translated label for a wildcard row that sits beside scoped rows. */
  wildcardLabel: string;
}

/**
 * A wildcard row (`product_sku_id: null`) carries a PRICE, not a variant
 * identity — there is no SKU behind it for a guest to pick.
 *
 * Two corrections live here, both from the same report (#1275, then #1316 for
 * the table after PR #94 fixed only the header badge):
 *
 *  1. **The label.** Alone, a wildcard row reads fine under the topping's own
 *     name — that is the ordinary shape for a topping nobody priced per-SKU (51
 *     such rows in dev). NEXT TO a scoped row it borrowed that name and sat
 *     under the "variants" column, so an operator read a second variant that
 *     does not exist. Rename it only where it can be mistaken.
 *
 *  2. **Whether it does anything.** `ToppingPricingService` sorts
 *     `product_sku_id IS NULL` last, so once every active SKU has a scoped row
 *     the wildcard prices nothing. Only the backend can answer that (it needs
 *     the topping product's whole SKU set, which the payload does not carry) —
 *     hence the `applies` flag. Do NOT try to infer it from `options` being
 *     empty: that relation is filtered to `is_active`, so a deactivated option
 *     axis makes a two-SKU product look option-less. That wrong signal is
 *     exactly what #1277 removed from the customer path.
 *
 * `applies === undefined` means the backend could not compute it, and is
 * treated as applied. Under-reporting is the safe direction: never accuse a
 * working price row of doing nothing.
 */
export function describeToppingPriceRow(
  sku: MenuToppingGroupItemSku,
  { hasScopedRow, itemName, wildcardLabel }: ToppingPriceRowContext
): ToppingPriceRowDescriptor {
  const isWildcard = sku.product_sku_id === null;

  const label =
    sku.sku_label ??
    sku.sku_code ??
    (isWildcard
      ? hasScopedRow
        ? wildcardLabel
        : (itemName ?? "—")
      : `SKU ${sku.id.slice(0, 6)}`);

  return {
    label,
    appliesToNothing: isWildcard && sku.applies === false,
  };
}

/** True when any row of this item is scoped to a real SKU. */
export function hasScopedToppingRow(skus: MenuToppingGroupItemSku[]): boolean {
  return skus.some((sku) => sku.product_sku_id !== null);
}
