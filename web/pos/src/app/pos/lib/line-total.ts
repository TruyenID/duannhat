/**
 * The one line-money rule, in one place.
 *
 *	perUnitPrice = unit_price + topping_subtotal
 *	lineSubtotal = quantity × perUnitPrice
 *
 * ## Why this file exists
 *
 * `topping_subtotal` is stored PER UNIT — the DB column says so
 * (`customer_order_items.topping_subtotal`, "Topping Subtotal (per unit)"), the
 * pricer says so, and every writer on both transports stores
 * `subtotal = quantity × (unit_price + topping_subtotal)`.
 *
 * That formula was then re-typed at roughly ten read sites across three
 * codebases, and in 2026-08 a shop found that several of them had drifted, in
 * two opposite directions:
 *
 *   · `quantity × unit_price + topping_subtotal` — the extras charged once per
 *     LINE. The printed slip and the admin order detail did this, so a bowl
 *     ordered ×3 showed the dish tripled and the extra not.
 *   · `unit_price + topping_subtotal / quantity` — the extras spread ACROSS the
 *     units. All three split-by-items calculators did this, so every guest but
 *     the last under-paid and the last absorbed the gap.
 *
 * The money charged was right the whole time; only the displays were wrong,
 * which is why it ran for months — there was nothing to reconcile against.
 *
 * Callers that already have the server's `subtotal` should keep reading that.
 * This is for the places that must rebuild the figure themselves: a
 * counterfactual (what the line WOULD have cost at the pre-promotion price) and
 * a per-unit price for splitting.
 */

/** `unit_price + topping_subtotal`. Both arrive as string or number. */
export function perUnitPrice(
  unitPrice: number | string | null | undefined,
  toppingSubtotal: number | string | null | undefined,
): number {
  return Number(unitPrice ?? 0) + Number(toppingSubtotal ?? 0);
}

/**
 * `quantity × (unit_price + topping_subtotal)`.
 *
 * The multiply wraps BOTH terms. That is the whole content of this function and
 * the entire bug it exists to prevent.
 */
export function lineSubtotal(
  unitPrice: number | string | null | undefined,
  toppingSubtotal: number | string | null | undefined,
  quantity: number | string | null | undefined,
): number {
  return Number(quantity ?? 0) * perUnitPrice(unitPrice, toppingSubtotal);
}
