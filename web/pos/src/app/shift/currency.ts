/**
 * Plan 030 — currency helpers shared between Shift Open and Close screens.
 *
 * `CURRENCIES` holds per-currency display config (symbol + position + locale
 * + decimals). `formatMoney` + `denomLabel` keep the two screens identical
 * in how amounts render so cashier muscle memory doesn't break across
 * pages.
 */

import type { Denomination } from "@/services/till-service";

// Must cover every code the backend offers in the shop currency picker
// (ShopOrderSettingsController::availableCurrencies). A code missing here falls
// through getCurrencyConfig() to the JPY default, so a Thai shop would have
// rendered ฿ amounts as "¥1,234" with no decimals — silently, on the
// cash-handling screens.
export type CurrencyCode =
  | "JPY"
  | "VND"
  | "USD"
  | "EUR"
  | "KRW"
  | "CNY"
  | "THB"
  | "IDR";

export interface CurrencyConfig {
  code: CurrencyCode;
  symbol: string;
  symbolPosition: "prefix" | "suffix";
  locale: string;
  decimals: number;
  /** Long display used in the currency picker option (e.g. "日本円 (¥)"). */
  display: string;
}

export const CURRENCIES: Record<CurrencyCode, CurrencyConfig> = {
  JPY: {
    code: "JPY",
    symbol: "¥",
    symbolPosition: "prefix",
    locale: "ja-JP",
    decimals: 0,
    display: "日本円 (¥)",
  },
  VND: {
    code: "VND",
    symbol: "₫",
    symbolPosition: "suffix",
    locale: "vi-VN",
    decimals: 0,
    display: "Việt Nam đồng (₫)",
  },
  USD: {
    code: "USD",
    symbol: "$",
    symbolPosition: "prefix",
    locale: "en-US",
    decimals: 2,
    display: "US Dollar ($)",
  },
  EUR: {
    code: "EUR",
    symbol: "€",
    symbolPosition: "suffix",
    locale: "de-DE",
    decimals: 2,
    display: "Euro (€)",
  },
  // decimals follow the backend's own authority — ZeroDecimalCurrency::CODES
  // lists KRW (and JPY/VND) as zero-fraction; CNY, THB and IDR are not on it.
  KRW: {
    code: "KRW",
    symbol: "₩",
    symbolPosition: "prefix",
    locale: "ko-KR",
    decimals: 0,
    display: "Korean Won (₩)",
  },
  CNY: {
    code: "CNY",
    symbol: "¥",
    symbolPosition: "prefix",
    locale: "zh-CN",
    decimals: 2,
    display: "Chinese Yuan (¥)",
  },
  THB: {
    code: "THB",
    symbol: "฿",
    symbolPosition: "prefix",
    locale: "th-TH",
    decimals: 2,
    display: "Thai Baht (฿)",
  },
  IDR: {
    code: "IDR",
    symbol: "Rp",
    symbolPosition: "prefix",
    locale: "id-ID",
    decimals: 2,
    display: "Indonesian Rupiah (Rp)",
  },
};

export function getCurrencyConfig(code: string | undefined | null): CurrencyConfig {
  if (code && code in CURRENCIES) return CURRENCIES[code as CurrencyCode];
  return CURRENCIES.JPY;
}

export function formatAmount(amount: number, cur: CurrencyConfig): string {
  return amount.toLocaleString(cur.locale, {
    minimumFractionDigits: cur.decimals,
    maximumFractionDigits: cur.decimals,
  });
}

export function formatMoney(
  amount: number,
  cur: CurrencyConfig,
  spaced = true,
): string {
  const num = formatAmount(amount, cur);
  const sep = spaced ? " " : "";
  return cur.symbolPosition === "suffix"
    ? `${num}${sep}${cur.symbol}`
    : `${cur.symbol}${sep}${num}`;
}

export function denomLabel(d: Denomination, cur: CurrencyConfig): string {
  return d.label ?? formatMoney(d.value, cur, false);
}

/**
 * Counted cash = denomination total + odd-change / adjustment, rounded to
 * 2 decimals. Mirrors the backend exactly — TillSessionService::close()
 * computes `round($denomCash + $cashAdjustment, 2)` — so the client-side
 * variance preview matches the settled figure and no phantom variance
 * appears (issue #542). The round-to-cents guards against binary-float
 * drift (e.g. 0.1 + 0.2) so the total is exact to the cent.
 */
export function sumCountedCash(denomTotal: number, adjustment: number): number {
  return Math.round((denomTotal + adjustment) * 100) / 100;
}
