import { describe, expect, it } from "vitest";

import { CURRENCIES, getCurrencyConfig, formatMoney, type CurrencyCode } from "./currency";

/**
 * The shop currency picker is served by the backend
 * (ShopOrderSettingsController::availableCurrencies), and it offers eight codes.
 * This module knew four.
 *
 * `getCurrencyConfig()` falls back to JPY for anything it does not recognise, so
 * the gap was silent and wrong in the worst way: pick Thai Baht in shop
 * settings, and the shift-open and shift-close screens — the cash-handling
 * surface — render ฿1,234.50 as "¥1,235", with the wrong symbol AND the
 * decimals dropped. The cashier counts a drawer against a figure that is not
 * the currency they hold.
 *
 * The list below is copied from the backend, which is the authority. If it adds
 * a ninth currency this test does not know about it — no frontend test can see
 * a PHP array — so the list is duplicated deliberately and the failure mode is
 * a stale duplicate rather than a silent yen fallback.
 */
const BACKEND_OFFERS: CurrencyCode[] = ["VND", "JPY", "USD", "EUR", "KRW", "CNY", "THB", "IDR"];

/**
 * Zero-fraction per the backend's own ZeroDecimalCurrency::CODES.
 * Everything else takes 2 — notably IDR, which is often assumed to be 0.
 */
const ZERO_DECIMAL: CurrencyCode[] = ["JPY", "VND", "KRW"];

describe("shop currency coverage", () => {
  it("configures every currency the backend offers", () => {
    const missing = BACKEND_OFFERS.filter((code) => !(code in CURRENCIES));

    expect(
      missing,
      `Offered by the shop settings picker but unknown here — these fall back to JPY:\n  ${missing.join(", ")}`
    ).toEqual([]);
  });

  it("never silently answers with yen for an offered currency", () => {
    // The fallback itself is fine for genuinely unknown input; what must not
    // happen is a code the operator can actually pick resolving to JPY.
    for (const code of BACKEND_OFFERS) {
      const config = getCurrencyConfig(code);
      if (code !== "JPY") {
        expect(config.code, `${code} resolved to ${config.code}`).toBe(code);
      }
    }
  });

  it("gives each currency the right number of decimals", () => {
    for (const code of BACKEND_OFFERS) {
      const expected = ZERO_DECIMAL.includes(code) ? 0 : 2;
      expect(CURRENCIES[code].decimals, `${code} decimals`).toBe(expected);
    }
  });

  it("keeps the minor units visible on a two-decimal currency", () => {
    // The concrete failure: 1234.5 baht must not print as a whole number.
    const baht = formatMoney(1234.5, getCurrencyConfig("THB"));

    expect(baht).toContain("฿");
    expect(baht).toMatch(/[.,]50/);
  });

  it("still falls back for input that is genuinely not a currency", () => {
    expect(getCurrencyConfig(undefined).code).toBe("JPY");
    expect(getCurrencyConfig("not-a-currency").code).toBe("JPY");
  });
});
