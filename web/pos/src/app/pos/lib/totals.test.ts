/**
 * #284 slice E — money-display coverage for the currency formatter module.
 * The module holds session-level mutable state (`activeCurrency`), so each
 * test restores VND (the documented default) to stay order-independent.
 */
import { afterEach, describe, expect, it } from "vitest";

import {
  formatCurrency,
  formatTaxAmount,
  getActiveCurrency,
  getCurrencySymbol,
  setActiveCurrency,
} from "./totals";

afterEach(() => {
  setActiveCurrency("VND");
});

describe("setActiveCurrency / getActiveCurrency", () => {
  it("defaults to VND and uppercases what it is given", () => {
    expect(getActiveCurrency()).toBe("VND");
    setActiveCurrency("jpy");
    expect(getActiveCurrency()).toBe("JPY");
  });

  it("ignores null/undefined/empty so a missing setting keeps the session currency", () => {
    setActiveCurrency("JPY");
    setActiveCurrency(null);
    setActiveCurrency(undefined);
    setActiveCurrency("");
    expect(getActiveCurrency()).toBe("JPY");
  });
});

describe("formatCurrency", () => {
  it("renders zero-decimal currencies with no fraction digits", () => {
    setActiveCurrency("JPY");
    expect(formatCurrency(1234)).toBe("￥1,234");
    // A sub-unit value must not leak decimals on a 0-digit currency.
    expect(formatCurrency(1234.56)).toBe("￥1,235");
  });

  it("renders 2-decimal currencies with cents", () => {
    expect(formatCurrency(9.5, "USD")).toBe("$9.50");
  });

  it("accepts numeric strings (API money rides as strings)", () => {
    setActiveCurrency("JPY");
    expect(formatCurrency("10000")).toBe("￥10,000");
  });

  it("per-call override wins without mutating the session currency", () => {
    setActiveCurrency("JPY");
    expect(formatCurrency(50000, "VND")).toContain("50.000");
    expect(getActiveCurrency()).toBe("JPY");
  });
});

describe("formatTaxAmount (plan-045 option-B)", () => {
  it("shows sub-unit tax on a 0-digit currency when decimals is set", () => {
    setActiveCurrency("JPY");
    expect(formatTaxAmount(93.5, 2)).toBe("￥93.50");
  });

  it("null/0 decimals falls back to the currency default (identical to formatCurrency)", () => {
    setActiveCurrency("JPY");
    expect(formatTaxAmount(93.5, null)).toBe(formatCurrency(93.5));
    expect(formatTaxAmount(93.5, 0)).toBe(formatCurrency(93.5));
  });
});

describe("getCurrencySymbol", () => {
  it("returns the short symbol for known currencies and the code for unknown ones", () => {
    expect(getCurrencySymbol("VND")).toBe("đ");
    expect(getCurrencySymbol("JPY")).toBe("¥");
    expect(getCurrencySymbol("XXX")).toBe("XXX");
  });
});
