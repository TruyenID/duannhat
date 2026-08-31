import { describe, it, expect } from "vitest";
import {
  currencySymbol,
  currencySymbolPosition,
  formatCurrency,
  formatCurrencyCompact,
  formatPriceAmount,
} from "./currency";

// Intl renders JPY as a half- or full-width yen sign depending on the Intl
// locale (¥ U+00A5 vs ￥ U+FFE5), so match either form.
const YEN = /[¥￥]/;
const DONG = "₫";

/**
 * #431 — money amounts must render in the DOMAIN object's currency, not a
 * currency derived from the UI locale. The locale only controls number
 * formatting (grouping separators / abbreviation); switching language must
 * never switch the currency symbol.
 */
describe("formatCurrency — currency follows the data, not the locale", () => {
  it("keeps the passed currency regardless of UI locale", () => {
    // A JPY order viewed under the Vietnamese locale must still show ¥, not ₫.
    expect(formatCurrency(1000, "vi", "JPY")).toMatch(YEN);
    expect(formatCurrency(1000, "vi", "JPY")).not.toContain(DONG);
    // A VND order viewed under ja/en must still show ₫, not ¥.
    expect(formatCurrency(1000, "ja", "VND")).toContain(DONG);
    expect(formatCurrency(1000, "ja", "VND")).not.toMatch(YEN);
    expect(formatCurrency(1000, "en", "VND")).toContain(DONG);
  });

  it("falls back to the locale default only when no currency is passed", () => {
    expect(formatCurrency(1000, "vi")).toContain(DONG); // vi → VND
    expect(formatCurrency(1000, "ja")).toMatch(YEN); // ja → JPY
  });

  it("keeps sub-unit precision when fractionDigits is passed (#555 M7 / plan-045 tax rows)", () => {
    // A JPY tax row stamped with tax_rounding_decimals=2 must keep ¥93.50.
    expect(formatCurrency("93.5", "ja", "JPY", 2)).toContain("93.50");
    // Without the override money stays whole-unit (no fraction digits).
    expect(formatCurrency("93.5", "ja", "JPY")).not.toContain(".");
  });

  it("localizes the number grouping without changing the currency", () => {
    // Same JPY currency under two locales — currency identical, grouping differs.
    const en = formatCurrency(1234567, "en", "JPY");
    const vi = formatCurrency(1234567, "vi", "JPY");
    expect(en).toMatch(YEN);
    expect(vi).toMatch(YEN);
    expect(en).toContain(","); // en/ja intl groups with comma
    expect(vi).toContain("."); // vi intl groups with dot
  });
});

/**
 * #511 — backend `decimal:2` casts serialize to JSON strings ("2490.00"),
 * but the omnify TS types declare the field as `number`. The old guard
 * `Number.isFinite("2490.00") === false` collapsed every string amount to 0,
 * so the order list "Tổng tiền" column always showed 0 ₫.
 */
describe("formatCurrency — coerces string-decimal amounts (#511)", () => {
  it("parses the Laravel decimal string form instead of rendering 0", () => {
    const jpy = formatCurrency("2490.00", "ja", "JPY");
    expect(jpy).toMatch(YEN);
    expect(jpy).toContain("2,490");
    expect(jpy).not.toContain("0 ￥");

    const vnd = formatCurrency("4595.00", "vi", "VND");
    expect(vnd).toContain("4.595");
    expect(vnd).toContain(DONG);
  });

  it("still renders 0 for genuinely non-numeric input", () => {
    expect(formatCurrency("", "ja", "JPY")).toMatch(/0/);
    expect(formatCurrency("abc", "ja", "JPY")).toMatch(/0/);
    expect(formatCurrency(null, "ja", "JPY")).toMatch(/0/);
    expect(formatCurrency(undefined, "ja", "JPY")).toMatch(/0/);
    expect(formatCurrency(Number.NaN, "ja", "JPY")).toMatch(/0/);
  });

  it("formatCurrencyCompact also parses string-decimal amounts", () => {
    const compact = formatCurrencyCompact("1200000.00", "vi", "JPY");
    expect(compact).toMatch(YEN);
    expect(compact).not.toContain("0 ￥");
  });
});

describe("formatCurrencyCompact — accepts an explicit currency (#431)", () => {
  it("keeps the passed currency regardless of locale", () => {
    expect(formatCurrencyCompact(1_200_000, "vi", "JPY")).toMatch(YEN);
    expect(formatCurrencyCompact(1_200_000, "vi", "JPY")).not.toContain(DONG);
    expect(formatCurrencyCompact(1_200_000, "ja", "VND")).toContain(DONG);
  });

  it("falls back to the locale default when no currency is passed", () => {
    expect(formatCurrencyCompact(1_200_000, "ja")).toMatch(YEN);
  });
});

describe("currencySymbol — bare symbol for input adornments", () => {
  it("follows the locale default when no currency is passed", () => {
    expect(currencySymbol("ja")).toMatch(YEN);
    expect(currencySymbol("en")).toMatch(YEN);
    expect(currencySymbol("vi")).toBe(DONG);
  });

  it("follows the passed currency over the locale, like formatCurrency", () => {
    expect(currencySymbol("vi", "JPY")).toMatch(YEN);
    expect(currencySymbol("ja", "VND")).toBe(DONG);
  });

  it("returns just the symbol — no digits, no grouping", () => {
    expect(currencySymbol("ja")).not.toMatch(/\d/);
    expect(currencySymbol("vi")).not.toMatch(/\d/);
  });
});

describe("currencySymbolPosition — which side the symbol belongs on", () => {
  it("reports prefix for ¥ and suffix for ₫", () => {
    expect(currencySymbolPosition("ja")).toBe("prefix");
    expect(currencySymbolPosition("en")).toBe("prefix");
    expect(currencySymbolPosition("vi")).toBe("suffix");
  });

  it("is a locale convention, not a currency one — vi suffixes even JPY", () => {
    // Guards the doc claim: passing a currency picks WHICH symbol is measured,
    // it never overrides how the locale places it.
    expect(currencySymbolPosition("vi", "JPY")).toBe("suffix");
    expect(currencySymbolPosition("ja", "VND")).toBe("prefix");
  });

  it("agrees with where formatCurrency actually puts the symbol", () => {
    // The adornment and the formatted amount must not disagree on screen.
    for (const locale of ["ja", "en", "vi"] as const) {
      const formatted = formatCurrency(1000, locale);
      const symbol = currencySymbol(locale);
      const expected = formatted.indexOf(symbol) < formatted.search(/\d/) ? "prefix" : "suffix";
      expect(currencySymbolPosition(locale)).toBe(expected);
    }
  });
});

/**
 * `selling_price` / `extra_price` are decimal(15,2) columns, so a catalog price
 * can legitimately carry sub-units. Rounding 1234.56 to ¥1,235 on screen makes
 * a correctly-saved price look mis-saved.
 */
describe("formatPriceAmount — keeps sub-units only when the price has them", () => {
  it("renders whole prices whole", () => {
    expect(formatPriceAmount(1000, "ja")).not.toContain(".");
    expect(formatPriceAmount("1000.00", "ja")).not.toContain(".");
  });

  it("keeps the decimals when the price actually has them", () => {
    expect(formatPriceAmount("1234.56", "ja")).toContain("1,234.56");
    expect(formatPriceAmount(1234.5, "ja")).toContain("1,234.50");
  });

  it("still follows the currency it is given", () => {
    expect(formatPriceAmount("1234.56", "vi", "JPY")).toMatch(YEN);
    expect(formatPriceAmount(1000, "ja", "VND")).toContain(DONG);
  });

  it("renders non-numeric input as zero rather than NaN", () => {
    expect(formatPriceAmount(null, "ja")).toMatch(/0/);
    expect(formatPriceAmount(Number.NaN, "ja")).toMatch(/0/);
  });
});
