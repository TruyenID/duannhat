"use client";

import { Card } from "@godxjp/ui";
import { formatCurrency } from "@/lib/currency";
import type { LocaleCode } from "@/i18n";

export interface RevenueStatCardProps {
  label: string;
  value: number;
  format?: "currency" | "number";
  /** UI locale — controls number formatting only. */
  locale?: LocaleCode;
  /**
   * ISO 4217 currency to render the value in. Currency follows the data, not
   * the UI locale (#431). Falls back to the locale default when omitted.
   */
  currencyCode?: string;
}
export function RevenueStatCard({
  label,
  value,
  format = "currency",
  locale = "ja",
  currencyCode,
}: RevenueStatCardProps) {
  const display =
    format === "currency" ? formatCurrency(value, locale, currencyCode) : value.toLocaleString();
  return (
    <Card data-slot="revenue-stat-card" className="p-4">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-1 text-xl font-semibold tabular-nums">{display}</p>
    </Card>
  );
}

/**
 * #1961 — what the revenue cards show when the filtered set spans more than one
 * currency.
 *
 * Not a formatting variant of {@link RevenueStatCard}: it deliberately shows NO
 * total. The backend sums `total_amount` across every matching order, so on a
 * mixed set that sum has added VND to JPY and there is no symbol that makes it
 * true. Printing it with any currency — even a correct-looking one — is the bug
 * this replaces, because a wrong number that looks precise is read as a fact.
 *
 * What it shows instead is the reason and the fix: which currencies are in
 * play, and that filtering to one branch restores the figure.
 */
export function MixedCurrencyStatCard({
  label,
  currencies,
  hint,
}: {
  label: string;
  currencies: string[];
  hint: string;
}) {
  return (
    <Card data-slot="mixed-currency-stat-card" className="p-4">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-1 text-sm font-medium">{currencies.join(" · ")}</p>
      <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
    </Card>
  );
}
