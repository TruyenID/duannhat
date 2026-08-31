import type { LocaleCode } from "@/i18n";
import { formatCurrency } from "@/lib/currency";

/**
 * Minor-unit money rendering for the settlement screens (#1157, plan-050 M5).
 *
 * The gateway settlement API speaks exclusively in `*_minor` — an INTEGER in
 * the currency's smallest unit. Turning that into something an accountant can
 * read needs the currency's exponent, and the exponent is NOT a constant:
 *
 *   JPY / VND / KRW → 0  (minor == major; ¥1234 is 1234, not 12.34)
 *   USD / EUR       → 2
 *   BHD / KWD       → 3
 *
 * The bug this file exists to prevent is `minor / 100` applied blindly: on a
 * JPY row it turns ¥1,234 into ¥12.34 — off by two orders of magnitude, and it
 * still *looks* like money, so nothing downstream notices. This is a screen
 * whose whole purpose is reconciling real bank deposits, so a plausible-looking
 * wrong number is the worst possible failure.
 *
 * The exponent comes from ICU (`Intl`), never from a table maintained here: a
 * hand-written list of zero-decimal currencies is one more place to drift, and
 * ICU already ships the ISO 4217 data.
 *
 * WHEN THE CURRENCY IS UNKNOWN WE DO NOT GUESS. `formatMinor` falls back to
 * printing the raw integer with an explicit "minor units" label. A visibly
 * unformatted number makes a human ask a question; a confidently wrong ¥ amount
 * does not.
 */

/** Number-only Intl locale per UI locale — mirrors `lib/currency.ts`. */
const LOCALE_TO_INTL: Record<LocaleCode, string> = {
  ja: "ja-JP",
  en: "ja-JP",
  vi: "vi-VN",
};

/** Exponent lookups are pure functions of the code — cache them. */
const MINOR_DIGITS_CACHE = new Map<string, number | null>();

function normalizeCode(currencyCode: string | null | undefined): string | null {
  if (typeof currencyCode !== "string") return null;
  const code = currencyCode.trim().toUpperCase();
  // ISO 4217 codes are exactly three letters. Anything else (empty string,
  // "JP", "JPYY", a display name) is not something we can price.
  return /^[A-Z]{3}$/.test(code) ? code : null;
}

/**
 * True when ICU actually recognises the code.
 *
 * `Intl.NumberFormat` accepts ANY well-formed three-letter code and silently
 * applies the ISO default exponent of 2 — so "XYZ" would come back as 2 and a
 * zero-decimal private currency would be divided by 100 without a word. ICU's
 * `DisplayNames` echoes the code back verbatim when it has no data for it,
 * which is the one signal that distinguishes "ICU knows this is 2" from "ICU
 * is guessing 2".
 */
function isKnownToIcu(code: string): boolean {
  if (typeof Intl.DisplayNames !== "function") return true; // no probe available
  try {
    const name = new Intl.DisplayNames(["en"], { type: "currency" }).of(code);
    return typeof name === "string" && name.toUpperCase() !== code;
  } catch {
    return false;
  }
}

/**
 * Digits between the minor unit and the major unit, or `null` when unknown.
 *
 * `null` is not "assume 2" — it is "refuse to convert", and every caller must
 * honour that.
 */
export function currencyMinorDigits(currencyCode: string | null | undefined): number | null {
  const code = normalizeCode(currencyCode);
  if (code === null) return null;

  const cached = MINOR_DIGITS_CACHE.get(code);
  if (cached !== undefined) return cached;

  let digits: number | null = null;
  if (isKnownToIcu(code)) {
    try {
      const resolved = new Intl.NumberFormat("en", {
        style: "currency",
        currency: code,
      }).resolvedOptions();
      digits = resolved.maximumFractionDigits ?? 0;
    } catch {
      digits = null;
    }
  }

  MINOR_DIGITS_CACHE.set(code, digits);
  return digits;
}

/** True when `formatMinor` will print raw minor units instead of a money amount. */
export function isUnknownCurrency(currencyCode: string | null | undefined): boolean {
  return currencyMinorDigits(currencyCode) === null;
}

/**
 * Coerce a minor amount to an integer. The API sends `(int)` casts, but a JSON
 * number that arrived as a string ("1234") must not collapse to 0.
 */
function toMinorInteger(minor: number | string | null | undefined): number {
  if (typeof minor === "number") return Number.isFinite(minor) ? minor : 0;
  if (typeof minor === "string") {
    const parsed = Number(minor);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

export interface FormatMinorOptions {
  /**
   * Suffix appended when the currency is unknown, so the reader can tell the
   * number is NOT a formatted money amount. The caller passes a translated
   * string; the default keeps the lib usable from tests.
   */
  unknownCurrencyLabel?: string;
}

/**
 * Render a `*_minor` integer for display.
 *
 * The conversion is division by a power of ten — arithmetic that only re-scales
 * a number the server already computed. No total is ever summed here: every
 * figure on the settlement screens comes from the API as-is.
 */
export function formatMinor(
  minor: number | string | null | undefined,
  locale: LocaleCode,
  currencyCode: string | null | undefined,
  options: FormatMinorOptions = {}
): string {
  const value = toMinorInteger(minor);
  const digits = currencyMinorDigits(currencyCode);

  if (digits === null) {
    const grouped = new Intl.NumberFormat(LOCALE_TO_INTL[locale] ?? "en").format(value);
    const label = options.unknownCurrencyLabel ?? "minor";
    const code = normalizeCode(currencyCode);
    return code ? `${grouped} ${code} (${label})` : `${grouped} (${label})`;
  }

  const major = digits === 0 ? value : value / 10 ** digits;
  return formatCurrency(major, locale, currencyCode as string, digits);
}
