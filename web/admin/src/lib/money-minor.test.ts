// @vitest-environment node
import { describe, expect, it } from "vitest";

import type { LocaleCode } from "@/i18n";
import { currencyMinorDigits, formatMinor, isUnknownCurrency } from "./money-minor";

// Intl renders JPY as a half- or full-width yen sign depending on the Intl
// locale (¥ U+00A5 vs ￥ U+FFE5), so match either form.
const YEN = /[¥￥]/;
const DONG = "₫";

/** Number formatting locale per UI locale — mirrors `lib/money-minor.ts`. */
const INTL: Record<LocaleCode, string> = { ja: "ja-JP", en: "ja-JP", vi: "vi-VN" };

/**
 * Read the NUMERIC VALUE back out of a formatted money string.
 *
 * Comparing digit sequences is not enough: ¥1,234 and ¥12.34 carry the same
 * digits, and ¥12.34 is precisely the bug under test. So the separators are
 * resolved from Intl for the locale in play and the string is parsed back to a
 * number — asserting the VALUE, not its punctuation.
 */
function amountOf(text: string, locale: LocaleCode): number {
  const parts = new Intl.NumberFormat(INTL[locale]).formatToParts(12345.6);
  const group = parts.find((p) => p.type === "group")?.value ?? ",";
  const decimal = parts.find((p) => p.type === "decimal")?.value ?? ".";
  const numeric = text
    .replace(/[^\d\-.,]/g, "")
    .split(group)
    .join("")
    .split(decimal)
    .join(".");
  return Number(numeric);
}

/**
 * #1157 — the settlement API is `*_minor` only, and the reconciliation screens
 * are read by accountants matching rows against a bank statement.
 *
 * `minor / 100` is correct for USD and WRONG by two orders of magnitude for
 * every currency this platform actually bills in (JPY, VND). The failure does
 * not announce itself: ¥12.34 in place of ¥1,234 is still a plausible amount.
 */
describe("formatMinor — zero-decimal currencies are never divided", () => {
  it("renders a JPY minor amount at full value", () => {
    const out = formatMinor(1234, "ja", "JPY");
    expect(out).toMatch(YEN);
    expect(amountOf(out, "ja")).toBe(1234);
  });

  it("renders a VND minor amount at full value", () => {
    const out = formatMinor(1234567, "vi", "VND");
    expect(out).toContain(DONG);
    expect(amountOf(out, "vi")).toBe(1234567);
  });

  it("keeps the row currency even when the UI locale disagrees", () => {
    // A JPY payout read by a Vietnamese-locale accountant is still yen, and is
    // still not divided — vi renders the decimal mark as "," so a naive
    // digit-sequence check would have missed a /100 here.
    const out = formatMinor(1000, "vi", "JPY");
    expect(out).toMatch(YEN);
    expect(out).not.toContain(DONG);
    expect(amountOf(out, "vi")).toBe(1000);
  });
});

describe("formatMinor — two- and three-decimal currencies ARE scaled", () => {
  it("divides USD by 100", () => {
    expect(amountOf(formatMinor(1234, "en", "USD"), "en")).toBe(12.34);
  });

  it("divides BHD by 1000 (three minor digits)", () => {
    expect(amountOf(formatMinor(1234, "en", "BHD"), "en")).toBe(1.234);
  });

  it("renders negative amounts (refund rows) without losing the sign", () => {
    expect(amountOf(formatMinor(-1234, "ja", "JPY"), "ja")).toBe(-1234);
    expect(amountOf(formatMinor(-1234, "en", "USD"), "en")).toBe(-12.34);
  });
});

/**
 * When the currency is missing or unrecognised we print the raw integer with a
 * label rather than guessing a divisor. A visibly unformatted number makes the
 * reader ask; a confidently wrong money amount does not.
 */
describe("formatMinor — unknown currency falls back to raw minor units", () => {
  it("does not scale a null currency", () => {
    const out = formatMinor(1234, "ja", null, { unknownCurrencyLabel: "minor" });
    expect(out).toContain("minor");
    expect(amountOf(out, "ja")).toBe(1234);
  });

  it("does not scale a code ICU has no data for", () => {
    // Well-formed but not a real currency: Intl.NumberFormat would silently
    // apply the ISO default of 2 decimals here.
    const out = formatMinor(1234, "en", "XYZ", { unknownCurrencyLabel: "minor" });
    expect(out).toContain("XYZ");
    expect(out).toContain("minor");
    expect(amountOf(out, "en")).toBe(1234);
  });

  it("does not scale a malformed code", () => {
    expect(amountOf(formatMinor(1234, "en", "JP", { unknownCurrencyLabel: "minor" }), "en")).toBe(
      1234
    );
    expect(amountOf(formatMinor(1234, "en", "", { unknownCurrencyLabel: "minor" }), "en")).toBe(
      1234
    );
  });
});

describe("currencyMinorDigits", () => {
  it("reads the exponent from ICU, not from a hand-maintained table", () => {
    expect(currencyMinorDigits("JPY")).toBe(0);
    expect(currencyMinorDigits("VND")).toBe(0);
    expect(currencyMinorDigits("KRW")).toBe(0);
    expect(currencyMinorDigits("USD")).toBe(2);
    expect(currencyMinorDigits("EUR")).toBe(2);
    expect(currencyMinorDigits("BHD")).toBe(3);
  });

  it("accepts lower-case and padded codes", () => {
    expect(currencyMinorDigits(" jpy ")).toBe(0);
  });

  it("returns null — not 2 — for anything it cannot verify", () => {
    expect(currencyMinorDigits(null)).toBeNull();
    expect(currencyMinorDigits(undefined)).toBeNull();
    expect(currencyMinorDigits("")).toBeNull();
    expect(currencyMinorDigits("XYZ")).toBeNull();
    expect(isUnknownCurrency("XYZ")).toBe(true);
    expect(isUnknownCurrency("JPY")).toBe(false);
  });
});

describe("formatMinor — tolerates the wire shapes the API can produce", () => {
  it("parses an integer that arrived as a string", () => {
    expect(amountOf(formatMinor("1234", "ja", "JPY"), "ja")).toBe(1234);
  });

  it("renders zero rather than NaN", () => {
    expect(amountOf(formatMinor(null, "ja", "JPY"), "ja")).toBe(0);
    expect(formatMinor(undefined, "ja", "JPY")).not.toContain("NaN");
    expect(formatMinor("abc", "ja", "JPY")).not.toContain("NaN");
  });
});
