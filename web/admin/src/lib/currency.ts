import type { LocaleCode } from "@/i18n";

/**
 * Per-locale currency + Intl mapping. The platform bills in JPY for the
 * Japanese/English markets and VND for the Vietnamese market — both are
 * zero-decimal currencies, so amounts are integers from the backend.
 */
const LOCALE_TO_CURRENCY: Record<LocaleCode, { intl: string; currency: string }> = {
  ja: { intl: "ja-JP", currency: "JPY" },
  en: { intl: "ja-JP", currency: "JPY" },
  vi: { intl: "vi-VN", currency: "VND" },
};

/**
 * Coerce a money amount to a finite number, tolerating the string form that
 * Laravel emits for `decimal:2` casts (e.g. `total_amount = "2490.00"`). The
 * omnify TS types declare these fields as `number`, but the JSON they carry
 * at runtime is a string, so `Number.isFinite("2490.00")` is `false` and the
 * naive guard collapses every amount to 0 (#511). Parse strings here so every
 * `formatCurrency` call-site is hardened, rather than casting each field.
 *
 * Returns 0 for genuinely non-numeric input (NaN / null / undefined / "").
 */
function toFiniteNumber(amount: number | string | null | undefined): number {
  if (typeof amount === "number") return Number.isFinite(amount) ? amount : 0;
  if (typeof amount === "string") {
    const parsed = Number(amount);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

/**
 * Format an integer money amount as locale-aware currency with grouping
 * separators (e.g. ja/en → "¥1,234,567", vi → "1.234.567 ₫").
 *
 * Non-finite input (NaN / undefined coerced upstream) renders as the
 * zero-amount string rather than "¥NaN".
 *
 * Pass `currencyCode` to override the locale-default currency — required
 * whenever the displayed amount belongs to a domain object with its own
 * currency (e.g. a JPY till session viewed by a VN-locale user must keep
 * its ¥ symbol; rendering it as ₫ is a correctness bug, not just a
 * cosmetic one). The number formatting (grouping separator, decimal mark)
 * still follows the UI locale.
 */
export function formatCurrency(
  amount: number | string | null | undefined,
  locale: LocaleCode,
  currencyCode?: string,
  /**
   * Sub-unit precision override. Money is whole-unit by default; tax rows
   * stamped with `tax_rounding_decimals` (plan-045) pass that value so
   * e.g. ¥93.50 keeps its half-yen.
   */
  fractionDigits = 0
): string {
  const value = toFiniteNumber(amount);
  const localeDefault = LOCALE_TO_CURRENCY[locale];
  const intl = localeDefault.intl;
  const currency = (currencyCode ?? localeDefault.currency).toUpperCase();
  return new Intl.NumberFormat(intl, {
    style: "currency",
    currency,
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value);
}

/**
 * Format a CATALOG price, which may legitimately carry sub-units.
 *
 * `formatCurrency` is whole-unit by default because that is right for the money
 * the platform actually moves. Catalog prices are not that: `selling_price` and
 * `extra_price` are `decimal(15,2)` columns, so an operator CAN save 1234.56 —
 * and rounding it to ¥1,235 on screen makes a correctly-saved price look
 * mis-saved. Whole amounts still render whole, so the common case gains no
 * "¥1,000.00" noise.
 */
export function formatPriceAmount(
  amount: number | string | null | undefined,
  locale: LocaleCode,
  currencyCode?: string
): string {
  const value = toFiniteNumber(amount);
  return formatCurrency(value, locale, currencyCode, Number.isInteger(value) ? 0 : 2);
}

/**
 * The bare currency symbol for the active locale (ja/en → "¥", vi → "₫").
 *
 * For input adornments only — a `<span>` sitting inside a number field, where
 * there is no amount to format yet. Everywhere an actual amount is rendered,
 * use `formatCurrency` / `formatPriceAmount` instead; they also place the
 * symbol on the correct side, which a hand-written adornment cannot do (pair
 * this with `currencySymbolPosition` when you must hand-place it).
 *
 * `currencyCode` overrides the locale default for the same reason it does on
 * `formatCurrency` — the field edits a domain object's money, so it must show
 * that object's currency, not the reader's.
 */
export function currencySymbol(locale: LocaleCode, currencyCode?: string): string {
  const localeDefault = LOCALE_TO_CURRENCY[locale];
  const currency = (currencyCode ?? localeDefault.currency).toUpperCase();
  return (
    currencyParts(locale, currency).find((p) => p.type === "currency")?.value ?? currency
  );
}

/**
 * Which side of the number the symbol belongs on — "prefix" for ¥1,000,
 * "suffix" for 1.000 ₫.
 *
 * Note this is a property of the LOCALE, not of the currency: `vi-VN` suffixes
 * every currency it renders, so `currencySymbolPosition("vi", "JPY")` is
 * "suffix" even though ja/en render JPY as a prefix. `currencyCode` is still
 * accepted because the two are not universally independent — it only ever
 * changes WHICH symbol is measured, never overrides the locale's convention.
 *
 * Read from Intl rather than hardcoded, so adding a currency to
 * `LOCALE_TO_CURRENCY` needs no change here.
 *
 * Only input adornments need this. Formatted amounts already carry the symbol
 * on the right side.
 */
export function currencySymbolPosition(
  locale: LocaleCode,
  currencyCode?: string
): "prefix" | "suffix" {
  const localeDefault = LOCALE_TO_CURRENCY[locale];
  const currency = (currencyCode ?? localeDefault.currency).toUpperCase();
  const parts = currencyParts(locale, currency);
  const symbolAt = parts.findIndex((p) => p.type === "currency");
  const numberAt = parts.findIndex((p) => p.type === "integer");
  // A currency Intl doesn't recognise yields no integer part to compare
  // against; suffix is the safer default (it reads as a unit, not a sigil).
  if (symbolAt === -1 || numberAt === -1) return "suffix";
  return symbolAt < numberAt ? "prefix" : "suffix";
}

function currencyParts(locale: LocaleCode, currency: string): Intl.NumberFormatPart[] {
  return new Intl.NumberFormat(LOCALE_TO_CURRENCY[locale].intl, {
    style: "currency",
    currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).formatToParts(0);
}

/**
 * Compact money formatting for chart axes — keeps the currency symbol but
 * abbreviates large magnitudes (e.g. "¥1.2M"). Falls back to the full
 * grouped form below 1,000 so small values stay legible.
 */
export function formatCurrencyCompact(
  amount: number | string | null | undefined,
  locale: LocaleCode,
  currencyCode?: string
): string {
  const value = toFiniteNumber(amount);
  const localeDefault = LOCALE_TO_CURRENCY[locale];
  // Currency follows the domain object (pass `currencyCode`), never the UI
  // locale — only the number formatting (grouping, abbreviation) is localized.
  const currency = (currencyCode ?? localeDefault.currency).toUpperCase();
  return new Intl.NumberFormat(localeDefault.intl, {
    style: "currency",
    currency,
    notation: "compact",
    maximumFractionDigits: 1,
  }).format(value);
}
