import { describe, expect, it } from "vitest";
import { renderHook } from "@testing-library/react";
import { ShopCurrencyProvider, useShopCurrency } from "@/providers/shop-currency-provider";
import { formatCurrency } from "@/lib/currency";

/**
 * #1260 — four shop screens interpolated the symbol directly:
 *
 *     return `¥${n.toLocaleString("ja-JP")}`;
 *
 * so a Vietnamese shop was shown its VND takings as yen, grouped the Japanese
 * way. They could not have taken a currency even if one were available, because
 * admin-web had no shop-currency context — the same gap #1248 closed for time.
 */

function wrapper(currencyCode: string | null | undefined) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <ShopCurrencyProvider currencyCode={currencyCode}>{children}</ShopCurrencyProvider>;
  };
}

describe("useShopCurrency", () => {
  it("hands the shop's currency to the screens inside it", () => {
    const { result } = renderHook(() => useShopCurrency(), { wrapper: wrapper("VND") });
    expect(result.current).toBe("VND");
  });

  it("normalises case, since ISO codes arrive from a settings column", () => {
    const { result } = renderHook(() => useShopCurrency(), { wrapper: wrapper("vnd") });
    expect(result.current).toBe("VND");
  });

  it("is null outside a shop route and for a blank column", () => {
    // Not an error: formatCurrency then falls back to the locale default, which
    // is the old behaviour and the only answer available when the shop's
    // currency is genuinely unknown.
    expect(renderHook(() => useShopCurrency()).result.current).toBeNull();
    for (const blank of ["", "   ", null, undefined]) {
      const { result } = renderHook(() => useShopCurrency(), { wrapper: wrapper(blank) });
      expect(result.current).toBeNull();
    }
  });
});

describe("what a Vietnamese shop's manager sees", () => {
  it("is dong, whatever language they read the page in", () => {
    // The defect in one line: currency followed the reader's locale (en → JPY),
    // or was hardcoded outright. It has to follow the shop.
    for (const locale of ["en", "ja", "vi"] as const) {
      const rendered = formatCurrency(1_234_567, locale, "VND");
      expect(rendered).not.toMatch(/[¥￥]/);
      expect(rendered).toMatch(/₫|VND/);
    }
  });

  it("still shows yen for a Japanese shop", () => {
    // The fix must not overshoot: JPY shops were never wrong.
    //
    // Both signs accepted, and the difference is the point: Intl renders JPY in
    // ja-JP as ￥ (FULLWIDTH, U+FFE5) while the hardcoded string used ¥
    // (U+00A5). So these four screens do change for Japanese shops — onto the
    // form every page already using formatCurrency has been rendering, which is
    // the consistency that was missing.
    expect(formatCurrency(1_234_567, "ja", "JPY")).toMatch(/[¥￥]/);
  });

  it("falls back to the locale default when the shop currency is unknown", () => {
    expect(formatCurrency(1000, "vi", undefined)).toMatch(/₫|VND/);
  });
});
