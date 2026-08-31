// plan-043 T5.4 — per-rate consumption-tax helpers for customer-web previews
// and history rendering (§6 engine, §2.2/§9 customer surfaces).
//
// Two data sources:
//   1. An order's server-computed `tax_breakdown` (authoritative — matches
//      order.tax_amount once-per-group, インボイス compliant). Render as-is.
//   2. A cart preview computed client-side from the menu's effective rates +
//      the branch default. This mirrors the excluded/included engine steps
//      closely enough for display; the server remains the source of truth at
//      checkout.

import type { Branch } from "@/data/brands";
import type { MenuItem } from "@/data/menu";

/** One per-rate row from the order payload's `tax_breakdown`. */
export interface TaxBreakdownRow {
  /** Tax-rate percent (e.g. 8, 10). */
  rate: number;
  /** Net taxable base for this rate group (tax-excluded amount). */
  taxable: number;
  /** Consumption tax for this rate group (rounded once per group). */
  tax: number;
}

/** The customer order type as it affects rate selection. */
export type CustomerOrderType = "spot" | "dine_in" | "takeaway";

/**
 * Half-up rounding to a currency minor-unit step. JPY/VND = 1, USD = 0.01.
 * Mirrors the engine's single RoundingMode::step() rule for parity.
 */
export function roundStep(value: number, step: number): number {
  if (step <= 0) return Math.round(value);
  // `Math.round(20.1 / 0.01) * 0.01` yields 20.100000000000002 — snap the
  // binary-float residue off so sub-unit currencies compare/serialise clean.
  // Integer steps (JPY/VND) are unaffected.
  return Number((Math.round(value / step) * step).toFixed(10));
}

/** JPY/VND are integer currencies; everything else uses cents. */
export function currencyStep(currencyCode?: string | null): number {
  const code = (currencyCode ?? "JPY").toUpperCase();
  return code === "JPY" || code === "VND" || code === "KRW" ? 1 : 0.01;
}

/**
 * Resolve the effective tax rate for a cart line (#1099 single-rate model):
 * menu-item effective rate → branch default type's rate. A tax type is ONE
 * number — consumption context never changes it (the takeaway menu carries
 * reduced overrides on its items instead). Returns null when nothing
 * resolves (fresh org) so callers can fall back to the legacy single rate.
 */
export function resolveLineRate(
  item: MenuItem,
  branch: Pick<Branch, "default_tax_type">,
): number | null {
  return item.tax_rate ?? branch.default_tax_type?.rate ?? null;
}

export interface CartTaxLine {
  /** Pre-tax line subtotal (unit price × qty, incl. toppings baked in). */
  subtotal: number;
  /** Effective tax rate for the line (null = legacy fallback). */
  rate: number | null;
}

/**
 * Compute the per-rate breakdown for a cart preview. Groups lines by their
 * effective rate, applies a pro-rata coupon discount per group (engine step
 * 2), then taxes once per group (step 4) in the excluded or included mode.
 * Returns the per-rate rows + aggregate tax so the UI can show both the
 * 8%対象 / 10%対象 lines and the grand total.
 *
 * #1425 — the SERVICE CHARGE is taxed here too, at its own rate
 * (`shop_order_settings.service_charge_tax_rate`), because the backend does it
 * in this same function: `OrderPricingCalculator::priceGroups()` folds the
 * service-charge tax into `tax_amount` AND merges its taxable share into the
 * breakdown group of the same rate (gap #7). Leaving it out of the preview made
 * `taxTotal` — and therefore the total the customer approved — short by exactly
 * that amount in 税抜 mode.
 *
 * It is deliberately NOT a separate display row: the invoice the shop issues
 * groups by rate, so a standalone "service charge tax" line would disagree with
 * both the receipt and the order-detail screens (which render the server's
 * `tax_breakdown` verbatim).
 */
export function computeCartTax(
  lines: CartTaxLine[],
  opts: {
    discount?: number;
    pricesIncludeTax?: boolean;
    /** Legacy single rate for lines that don't resolve a type. */
    legacyRate?: number;
    currencyCode?: string | null;
    /**
     * Service charge already computed on (subtotal − discount). In 総額表示 mode
     * this base is gross, with its tax inside (plan-043 Q12).
     */
    serviceCharge?: number;
    /** Its own tax rate (%), independent of any item rate. */
    serviceChargeTaxRate?: number;
  } = {},
): { rows: TaxBreakdownRow[]; taxTotal: number; serviceChargeTax: number } {
  const step = currencyStep(opts.currencyCode);
  const legacyRate = opts.legacyRate ?? 0;
  const includeTax = opts.pricesIncludeTax ?? false;

  // Group subtotals by effective rate (falling back to the legacy rate).
  const groups = new Map<number, number>();
  let grandSubtotal = 0;
  for (const line of lines) {
    const rate = line.rate ?? legacyRate;
    groups.set(rate, (groups.get(rate) ?? 0) + line.subtotal);
    grandSubtotal += line.subtotal;
  }

  const discount = Math.min(opts.discount ?? 0, grandSubtotal);
  const rowsByRate = new Map<number, TaxBreakdownRow>();

  for (const [rate, groupSubtotal] of groups) {
    // Pro-rata discount per group (engine step 2).
    const groupDiscount =
      grandSubtotal > 0 ? discount * (groupSubtotal / grandSubtotal) : 0;

    let taxable: number;
    let tax: number;
    if (includeTax) {
      // 総額表示: prices already include tax; extract the inner tax.
      const gross = Math.max(0, groupSubtotal - groupDiscount);
      const net = roundStep(gross / (1 + rate / 100), step);
      tax = gross - net;
      taxable = net;
    } else {
      taxable = Math.max(0, groupSubtotal - groupDiscount);
      tax = roundStep((taxable * rate) / 100, step);
    }

    rowsByRate.set(rate, { rate, taxable, tax });
  }

  // Service-charge tax — its own rate, rounded once, then merged into the
  // group of that same rate (or forming one). Mirrors priceGroups() lines
  // 104-115 so the preview breakdown equals the server's `tax_breakdown`.
  const serviceCharge = opts.serviceCharge ?? 0;
  const scRate = opts.serviceChargeTaxRate ?? 0;
  let serviceChargeTax = 0;
  if (serviceCharge > 0 && scRate > 0) {
    serviceChargeTax = includeTax
      ? serviceCharge - roundStep(serviceCharge / (1 + scRate / 100), step)
      : roundStep((serviceCharge * scRate) / 100, step);
    const scNet = includeTax ? serviceCharge - serviceChargeTax : serviceCharge;
    const existing = rowsByRate.get(scRate);
    rowsByRate.set(scRate, {
      rate: scRate,
      taxable: (existing?.taxable ?? 0) + scNet,
      tax: (existing?.tax ?? 0) + serviceChargeTax,
    });
  }

  const rows = [...rowsByRate.values()]
    .sort((a, b) => a.rate - b.rate)
    .filter((row) => row.rate > 0 || row.tax !== 0);
  const taxTotal = rows.reduce((sum, row) => sum + row.tax, 0);

  return { rows, taxTotal, serviceChargeTax };
}

/** One selected chunk of an order line, for the by-items split calculator. */
export interface SelectedOrderLine {
  /** Subtotal of ONE unit of the line (toppings already averaged in). */
  perUnitSubtotal: number;
  /** How many units of this line the customer is claiming. */
  units: number;
  /** Snapshot tax rate of the line; null on legacy orders (pre plan-043). */
  rate: number | null;
}

/** What a by-items selection costs, split into its money components. */
export interface SelectionTotal {
  /** Σ per-unit subtotal × units, in the branch's price basis. */
  subtotal: number;
  /** Consumption tax of the selection (0 when prices already include it). */
  tax: number;
}

/**
 * plan-043 / issue #32 — consumption tax owed by a by-items selection.
 *
 * Mirrors `SplitByItemsCalculator::compute()` on the backend (which is itself
 * the PHP port of pos-web's `split-by-items.ts`) for the single-bill case:
 * group the claimed units by their snapshot rate, round the tax ONCE per rate
 * group (インボイス), so a mixed 8% + 10% selection is taxed correctly.
 *
 * In 総額表示 (`isTaxIncluded`) the prices already carry the tax, so `tax` is
 * the *inner* tax — reported for display but NOT added on top of `subtotal`.
 *
 * Legacy lines with no snapshot rate (`rate === null`) can't be grouped; their
 * share of the order-level `orderTaxAmount` is allocated pro-rata by subtotal.
 */
export function computeSelectionTotal(
  lines: SelectedOrderLine[],
  opts: {
    isTaxIncluded?: boolean;
    currencyCode?: string | null;
    /** Order-level subtotal + tax, for the legacy pro-rata fallback. */
    orderSubtotal?: number;
    orderTaxAmount?: number;
  } = {},
): SelectionTotal {
  const step = currencyStep(opts.currencyCode);
  const includeTax = opts.isTaxIncluded ?? false;

  const groups = new Map<number, number>();
  let legacySubtotal = 0;
  let subtotal = 0;

  for (const line of lines) {
    if (line.units <= 0) continue;
    const amount = line.perUnitSubtotal * line.units;
    subtotal += amount;
    if (line.rate == null) {
      legacySubtotal += amount;
    } else {
      groups.set(line.rate, (groups.get(line.rate) ?? 0) + amount);
    }
  }

  let tax = 0;
  for (const [rate, groupSubtotal] of groups) {
    tax += includeTax
      ? groupSubtotal - roundStep(groupSubtotal / (1 + rate / 100), step)
      : roundStep((groupSubtotal * rate) / 100, step);
  }

  // Legacy fallback: no per-line snapshot → carve the order's own tax figure
  // by subtotal share. Yields the whole tax when the whole order is claimed.
  const orderSubtotal = opts.orderSubtotal ?? 0;
  const orderTaxAmount = opts.orderTaxAmount ?? 0;
  if (legacySubtotal > 0 && orderSubtotal > 0 && orderTaxAmount > 0) {
    tax += roundStep((orderTaxAmount * legacySubtotal) / orderSubtotal, step);
  }

  return { subtotal: roundStep(subtotal, step), tax };
}

/** Both tax representations of a single menu price, for 総額表示 card display. */
export interface TaxDisplayPrice {
  /** Primary amount to show large — matches the branch's current mode. */
  primary: number;
  /** The opposite representation, shown small as reference. */
  secondary: number;
  /** true = the branch shows tax-included prices (税込); false = 税抜. */
  includeTax: boolean;
}

/**
 * plan-043 — derive the 税込 (tax-included) + 税抜 (tax-excluded) amounts of a
 * single menu price for card display, honouring the branch's
 * `prices_include_tax` mode.
 *
 * The stored menu price's MEANING follows the mode (the engine snapshot):
 *   - included (税込 mode): the stored price IS the gross (tax-included) price;
 *     net = round(gross / (1 + r)).
 *   - excluded (税抜 mode): the stored price IS the net (tax-excluded) price;
 *     gross = round(net × (1 + r)).
 * `primary` is the amount matching the current mode (equals the stored price —
 * so it stays consistent with the cart, which charges that basis); `secondary`
 * is the derived opposite so the customer can see the tax impact. Rounding uses
 * the same currency step as the engine (half-up).
 */
export function taxDisplayPrice(
  price: number,
  ratePercent: number | null,
  pricesIncludeTax: boolean,
  currencyCode?: string | null,
): TaxDisplayPrice {
  const step = currencyStep(currencyCode);
  const rate = ratePercent ?? 0;

  let gross: number;
  let net: number;
  if (pricesIncludeTax) {
    gross = price;
    net = roundStep(price / (1 + rate / 100), step);
  } else {
    net = price;
    gross = roundStep(price * (1 + rate / 100), step);
  }

  return pricesIncludeTax
    ? { primary: gross, secondary: net, includeTax: true }
    : { primary: net, secondary: gross, includeTax: false };
}
