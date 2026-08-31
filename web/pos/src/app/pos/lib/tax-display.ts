// plan-043 — 税込 / 税抜 menu-card price display helpers. Mirrors customer-web's
// lib/tax.ts taxDisplayPrice so the two surfaces show identical numbers.
//
// The branch `prices_include_tax` flag decides what a stored menu price MEANS:
//   - included (総額表示): the stored price IS the gross (税込) price → net = gross/(1+r).
//   - excluded:            the stored price IS the net (税抜) price  → gross = net×(1+r).
// The primary shown equals the stored price (the mode's basis, consistent with
// the cart, which charges that basis); the secondary is the derived opposite so
// staff/customers see the tax impact.

import type { ShopMenuProductSummary } from "../types";

/** JPY/VND/KRW are integer currencies; everything else rounds to cents. */
export function currencyStep(currencyCode?: string | null): number {
  const code = (currencyCode ?? "JPY").toUpperCase();
  return code === "JPY" || code === "VND" || code === "KRW" ? 1 : 0.01;
}

/** Half-up rounding to a currency minor-unit step (engine parity). */
export function roundStep(value: number, step: number): number {
  if (step <= 0) return Math.round(value);
  return Math.round(value / step) * step;
}

/**
 * A product's effective tax rate (#1099 single-rate model). ONE number — the
 * order type never changes it; the takeaway menu carries reduced overrides on
 * its items instead. Returns null when the product carries no resolved rate
 * (fresh org / stale client).
 */
export function productRate(
  product: Pick<ShopMenuProductSummary, "tax_rate"> | null | undefined,
): number | null {
  return product?.tax_rate ?? null;
}

export interface TaxDisplayPrice {
  /** Primary amount (matches the branch mode; equals the stored price). */
  primary: number;
  /** The derived opposite representation, shown small as reference. */
  secondary: number;
  /** true = branch shows tax-included prices (税込); false = 税抜. */
  includeTax: boolean;
}

/** Derive the 税込 + 税抜 amounts of a single menu price for card display. */
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

/**
 * issue #1042 — the price a menu card shows.
 *
 * The stored price is authoritative: whatever the shop entered IS what shows.
 * The shop's `prices_include_tax` toggle does NOT change this number — it only
 * drives the 税込 / 税抜 LABEL, so the card matches what checkout charges.
 *
 * Previously ON recomputed `base × (1 + rate)` on the assumption that stored
 * prices were always net; that made the card disagree with the cart whenever a
 * shop had entered tax-included prices (issue #1042).
 *
 * Signature kept so callers can co-locate the toggle/rate with their label
 * logic; the extra args are intentionally unused (prefixed `_`).
 */
export function menuDisplayPrice(
  base: number,
  _ratePercent: number | null,
  _pricesIncludeTax: boolean,
  _currencyCode?: string | null,
): number {
  return base;
}

/** A single per-rate row of an order's server-computed `tax_breakdown`. */
interface BreakdownRow {
  tax?: number | string | null;
}

/**
 * plan-043 — total consumption tax that sits on the ITEM lines, summed from the
 * order's per-rate `tax_breakdown` (8%対象 + 10%対象 …). This is the group-once
 * authoritative figure — NOT a per-line re-round — so the cart's 総額表示 gross
 * subtotal (`order.subtotal + itemTaxTotal`) reconciles exactly with the server
 * total. Returns 0 for a bare/legacy order without a breakdown.
 */
export function itemTaxTotal(
  breakdown: readonly BreakdownRow[] | null | undefined,
): number {
  if (!breakdown) return 0;
  return breakdown.reduce((sum, row) => sum + Number(row.tax ?? 0), 0);
}

/**
 * plan-043 — the SERVICE-charge slice of the order's total tax. The order's
 * `tax_amount` covers both the item lines and the service charge; subtracting
 * the item tax leaves the service portion. Clamped at 0 so a stale/mismatched
 * payload can never render a negative 内税. Used to present the service line as
 * 税込 (gross) in 総額表示 mode without double-counting.
 */
export function serviceTaxTotal(
  taxAmount: number | string | null | undefined,
  breakdown: readonly BreakdownRow[] | null | undefined,
): number {
  return Math.max(0, Number(taxAmount ?? 0) - itemTaxTotal(breakdown));
}

/**
 * #2138 — which per-rate tax groups belong on the document.
 *
 * A group is dropped ONLY when both its base and its tax are zero: that is a
 * genuinely empty row. A 非課税 / zero-rated group carries base > 0 with tax = 0
 * and is a MANDATORY block on the invoice (Peppol BR-Z-08 / BR-E-08) — filtering
 * on `tax > 0`, as the cart did until #2138, silently removed exactly those.
 *
 * Cloud fixed the mirror-image defect at #2074; the same both-zero condition
 * lives in `WritesCustomerOrders.php:2923` (the `order_conditions` write path) —
 * NOT in `CustomerOrderResource`, which groups by rate and filters nothing.
 * Checking parity against the resource finds no rule and reads like a gap.
 * Keeping the rule here, named and
 * tested, is what stops the two sides drifting apart again — the previous
 * version lived as an inline `.filter()` inside a 1200-line component, where
 * nothing could reach it.
 */
export function visibleTaxGroups<T extends { taxable: number; tax: number }>(
  groups: readonly T[] | null | undefined,
): T[] {
  return (groups ?? []).filter(
    (g) => !(Number(g.taxable) === 0 && Number(g.tax) === 0),
  );
}

/**
 * Thuế đã NẰM TRONG con số "Tạm tính" mà giỏ đang hiện hay chưa.
 *
 * Hai đường dẫn tới cùng một câu trả lời "rồi", và giỏ chỉ từng nhận ra một:
 *
 *   1. `showGrossSummary` — engine lưu NET, giao diện tự cộng `itemTax` vào để
 *      trình bày 税込.
 *   2. `is_tax_included` — engine lưu thẳng GROSS; không phải quy đổi gì cả,
 *      nhưng thuế vẫn nằm sẵn trong đó.
 *
 * `showGrossSummary` cố tình LOẠI (2) — `includeTax && hasBreakdown &&
 * !is_tax_included` — vì nó trả lời câu hỏi *"có cần quy đổi không"*. Dùng nó để
 * trả lời câu *"thuế có phải một số hạng không"* là sai ở đúng ca (2): giỏ vẽ
 * dòng thuế như một khoản CỘNG THÊM lên một con số đã gồm thuế, thổi phồng cột
 * số, rồi dòng 端数調整 bù trừ phần dôi ra.
 *
 * Đo trên đơn thật ORD-2026-3152 (¥1.150 内税 10% + phí phục vụ 5%):
 * `1.150 + 105 + 63 = 1.318` trong khi tổng thật là `1.208` — chênh đúng ¥110,
 * và đó chính là con số dòng "Làm tròn" đang hiện.
 */
export function taxSitsInsideSubtotal(
  isTaxIncludedSnapshot: boolean | null | undefined,
  showGrossSummary: boolean,
): boolean {
  return Boolean(isTaxIncludedSnapshot) || showGrossSummary;
}

/** Hai con số của dòng phí phục vụ: đã gồm thuế, và phần trước thuế. */
export interface ServiceChargeDisplay {
  /** Số hiện ở cột phải — LUÔN là 税込. */
  gross: number;
  /** Dòng con "Trước thuế". `gross − net` là phần thuế nằm trong. */
  net: number;
}

/**
 * Dòng phí phục vụ luôn hiện 税込 — nhưng `order.service_charge` đã là 税込 hay
 * chưa thì TUỲ CHẾ ĐỘ, và đó là chỗ nó sai.
 *
 * `OrderPricingCalculator::priceGroups` tính một con số phí duy nhất
 * `round(taxableBase × rate)` rồi diễn giải nó hai kiểu:
 *
 *   総額表示: `serviceCharge` LÀ 税込; `serviceChargeTax` là phần thuế BÊN TRONG
 *            nó, và tổng đơn cộng `serviceCharge` chứ không cộng thuế lần nữa.
 *   税抜:     `serviceCharge` là 税抜; `serviceChargeTax` cộng THÊM lên tổng.
 *
 * Giỏ cộng `service_charge + serviceTax` trong CẢ HAI. Ở 内税 điều đó đếm phần
 * thuế hai lần: phí ¥58 (đã gồm ¥5 thuế) hiện thành ¥63, trong khi tổng đơn chỉ
 * mang ¥58. Dòng con "Trước thuế ¥58 / Thuế +¥5" khi ấy cũng nói dối — ¥58 chính
 * là con số ĐÃ gồm thuế.
 */
export function serviceChargeDisplay(
  serviceCharge: number | string | null | undefined,
  serviceTax: number,
  isTaxIncludedSnapshot: boolean | null | undefined,
): ServiceChargeDisplay {
  const charge = Number(serviceCharge ?? 0) || 0;

  return isTaxIncludedSnapshot
    ? { gross: charge, net: charge - serviceTax }
    : { gross: charge + serviceTax, net: charge };
}

/** The money fields of an order the 端数調整 row is derived from. */
export interface RoundingAdjustmentSource {
  subtotal: number | string | null | undefined;
  discount_amount?: number | string | null;
  service_charge?: number | string | null;
  tax_amount?: number | string | null;
  total_amount: number | string | null | undefined;
  /** plan-043 — true when `subtotal` is ALREADY the 税込 (gross) figure. */
  is_tax_included?: boolean | null;
}

/**
 * plan-045 option-B — the 端数調整 (rounding) row: payable total minus the exact
 * pre-round sum of the rows above it.
 *
 * ## The row is a SUBTRACTION, so the minuend has to match the mode
 *
 * `OrderPricingCalculator::priceGroups` computes the total two different ways,
 * and which one applies is the order's `is_tax_included` snapshot:
 *
 *   included (総額表示) total = round(subtotal − discount + service_charge)
 *   excluded (税抜)     total = round(subtotal − discount + service_charge + tax)
 *
 * In included mode the tax is 内税 — already inside `subtotal` — and the engine
 * deliberately does NOT add it again (`PricingResult`: *"the group taxes are 内税
 * … so they are NOT added again into totalAmount"*). `types.ts` states the same
 * contract from the client side: the per-rate tax is *"a display-only extraction
 * not added on top of the total"*.
 *
 * The first version of this row subtracted `tax_amount` unconditionally. On every
 * 総額表示 order that made the row show **−tax_amount** instead of the rounding
 * delta: a JPY cart of ¥360 @10% 内税 rendered `Thuế 10% ¥32.8 · Làm tròn −¥32.8`,
 * with the two lines cancelling out to keep the total honest. The tell was that
 * the figure never reacted to the shop's rounding direction — 切り上げ and 切り捨て
 * printed the same number, because that number was never a rounding.
 *
 * ## Why it is right for this row to vanish in 総額表示
 *
 * There is nothing to book. The tax is not a separate addend, and every remaining
 * term (subtotal, discount, service charge) is already at the currency step, so
 * the delta is 0 and {@link showsRoundingAdjustment} hides the row. It reappears
 * exactly where a remainder can exist: 税抜 orders whose `tax_rounding_decimals`
 * lets `tax_amount` carry sub-unit precision (¥93.50) while the payable total is
 * whole yen.
 *
 * Sign convention: positive = the total was rounded UP away from the rows above.
 */
export function roundingAdjustment(order: RoundingAdjustmentSource): number {
  const n = (v: number | string | null | undefined): number => Number(v ?? 0) || 0;

  const preRound =
    n(order.subtotal) -
    n(order.discount_amount) +
    n(order.service_charge) +
    (order.is_tax_included ? 0 : n(order.tax_amount));

  return n(order.total_amount) - preRound;
}

/**
 * Whether the 端数調整 row is worth drawing.
 *
 * Half a minor unit of the SMALLEST step any order can carry — not of the
 * currency's — because plan-045 option-B tax figures are sub-unit by design.
 * Below this the row would read as a rounded-to-nothing `+¥0.0`.
 */
export function showsRoundingAdjustment(adjustment: number): boolean {
  return Math.abs(adjustment) >= 0.005;
}

/**
 * #2138 — whether a money row (discount / service charge / tax) belongs on screen.
 *
 * A row is drawn whenever the amount is non-zero, INCLUDING when it is negative.
 * The gate this replaces was `> 0`, which reads perfectly reasonable ("only show
 * a discount when there is one") and is wrong in the one case that matters: a
 * negative amount is corruption, not absence. #2130 produces `tax = -1` on a
 * 0%-tax split bill, and under `> 0` the POS drew nothing at all — the operator
 * cannot report a number the screen never renders.
 *
 * It lives here, named and tested, for the same reason `visibleTaxGroups` does:
 * the rule previously existed as six inline comparisons across two 1200-line
 * components, where no test could reach it. Flipping any of them back to `> 0`
 * left all 986 pos-web tests green (measured in review of PR #2139) — that is
 * exactly the silent regression #2138 exists to stop.
 *
 * `Number(v ?? 0)` also settles the second half: a MISSING field must hide the
 * row. Bare `Number(undefined)` is `NaN`, and `NaN !== 0` is true, so the naked
 * comparison used by `order-cart` rendered a row reading `formatCurrency(undefined)`
 * whenever the API omitted the field.
 */
export function showsMoneyRow(v: number | string | null | undefined): boolean {
  return Number(v ?? 0) !== 0;
}
