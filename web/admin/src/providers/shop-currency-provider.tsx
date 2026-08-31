"use client";

import { createContext, useContext } from "react";

/**
 * The currency a shop-scoped screen must render money in (#1260).
 *
 * `lib/currency.ts` maps a LOCALE to a currency — ja and en to JPY, vi to VND.
 * Its docblock is explicit that this is the platform's billing arrangement, and
 * for billing it is right. For a shop's own money it is not: currency belongs to
 * the shop, not to the language the reader picked. An English-speaking manager
 * of a Vietnamese shop was shown yen.
 *
 * Four shop screens bypassed even that, interpolating the symbol directly:
 *
 *     return `¥${n.toLocaleString("ja-JP")}`;
 *
 * which cannot take a currency at all, and additionally groups digits the
 * Japanese way for a Vietnamese amount.
 *
 * Same shape as ShopTimezoneProvider (#1248), for the same reason: a shop-scoped
 * fact that the presentation layer had no way to reach.
 */
const ShopCurrencyContext = createContext<string | null>(null);

export function ShopCurrencyProvider({
  currencyCode,
  children,
}: {
  currencyCode: string | null | undefined;
  children: React.ReactNode;
}) {
  const value = currencyCode?.trim() ? currencyCode.trim().toUpperCase() : null;

  return <ShopCurrencyContext.Provider value={value}>{children}</ShopCurrencyContext.Provider>;
}

/**
 * The shop's ISO 4217 code, or null outside a shop route / before it loads.
 *
 * Callers pass the result straight to `formatCurrency(amount, locale, code)`,
 * whose third argument is optional — null falls back to the locale default,
 * which is the previous behaviour and the only answer available when the shop's
 * currency is genuinely unknown.
 */
export function useShopCurrency(): string | null {
  return useContext(ShopCurrencyContext);
}
