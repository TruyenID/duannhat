/**
 * Maps each ISO 4217 currency code to a sensible `Intl.NumberFormat`
 * locale + a fraction-digit hint. Currencies whose smallest subunit is
 * the whole number (VND, JPY, KRW, IDR) render at 0 fraction digits;
 * the rest fall back to the currency's standard digits. Add more entries
 * here when the backend exposes a new currency.
 */
const CURRENCY_META: Record<
  string,
  { locale: string; fractionDigits: number; symbol: string }
> = {
  VND: { locale: "vi-VN", fractionDigits: 0, symbol: "đ" },
  JPY: { locale: "ja-JP", fractionDigits: 0, symbol: "¥" },
  KRW: { locale: "ko-KR", fractionDigits: 0, symbol: "₩" },
  IDR: { locale: "id-ID", fractionDigits: 0, symbol: "Rp" },
  USD: { locale: "en-US", fractionDigits: 2, symbol: "$" },
  EUR: { locale: "de-DE", fractionDigits: 2, symbol: "€" },
  CNY: { locale: "zh-CN", fractionDigits: 2, symbol: "¥" },
  THB: { locale: "th-TH", fractionDigits: 2, symbol: "฿" },
};

/**
 * Active currency for the running session. Module-level mutable so the
 * 80-ish `formatCurrency(value)` call sites scattered across pos-web
 * don't have to thread a currency arg through every component. The
 * `setActiveCurrency` setter is called once by the pos page after the
 * shop's order settings load, and subsequent renders pick up the new
 * value naturally. Default `"VND"` preserves the original behaviour.
 */
let activeCurrency: string = "VND";

export function setActiveCurrency(code: string | null | undefined): void {
  if (!code) return;
  const next = code.toUpperCase();
  if (next === activeCurrency) return;
  activeCurrency = next;
}

export function getActiveCurrency(): string {
  return activeCurrency;
}

/**
 * Short display symbol for the active (or overridden) currency — used by
 * places that show a "raw number + suffix" affordance (e.g. an editable
 * amount input where the formatter would interfere with typing).
 */
export function getCurrencySymbol(override?: string): string {
  const code = (override ?? activeCurrency).toUpperCase();
  return CURRENCY_META[code]?.symbol ?? code;
}

export function formatCurrency(value: number | string, override?: string): string {
  const code = override ?? activeCurrency;
  const meta = CURRENCY_META[code] ?? { locale: "en-US", fractionDigits: 2 };
  return new Intl.NumberFormat(meta.locale, {
    style: "currency",
    currency: code,
    maximumFractionDigits: meta.fractionDigits,
  }).format(Number(value));
}

/**
 * plan-045 option-B — format a TAX figure with an explicit number of fraction
 * digits (the order's `tax_rounding_decimals`), overriding the currency's own
 * default. On a JPY shop (default 0 digits) with decimals=2 this renders
 * ¥93.50 instead of the rounded ¥94, so the sub-unit tax the engine computed is
 * visible even though the payable total stays whole-yen. `decimals` null/≤0 →
 * the currency default (identical to formatCurrency).
 */
export function formatTaxAmount(
  value: number | string,
  decimals?: number | null,
  override?: string,
): string {
  const code = override ?? activeCurrency;
  const meta = CURRENCY_META[code] ?? { locale: "en-US", fractionDigits: 2 };
  const digits = decimals != null && decimals > 0 ? decimals : meta.fractionDigits;
  return new Intl.NumberFormat(meta.locale, {
    style: "currency",
    currency: code,
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(Number(value));
}

export function elapsedMinutes(iso: string): number {
  const start = new Date(iso).getTime();
  const now = Date.now();
  return Math.max(0, Math.round((now - start) / 60_000));
}
