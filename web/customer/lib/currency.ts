"use client";

import { useCallback, useMemo } from "react";
import { useBrand } from "@/context/brand-context";
import {
  ZERO_FRACTION,
  THREE_DECIMAL,
  getRoundingStep,
  roundUpToStep,
  type SplitBillRoundingMode,
} from "@/lib/split-rounding";

// Re-exported so existing importers (`@/lib/currency`) keep working. The pure
// money math now lives in `@/lib/split-rounding` so it can be unit-tested
// without pulling in React / brand-context.
export { getRoundingStep, roundUpToStep };
export type { SplitBillRoundingMode };

/**
 * Currency formatter centralized in 1 place. Tất cả hiển thị giá tiền trong
 * customer-web đi qua đây để khi shop đổi `currency_code` (ShopOrderSetting)
 * thì UI tự đổi symbol + định dạng (prefix/postfix) mà không sửa từng component.
 *
 * Locale dùng để format được derive TỪ CURRENCY (USD → en-US, JPY → ja-JP,
 * VND → vi-VN, EUR → de-DE) — không phải locale UI. Lý do: `Intl.NumberFormat`
 * ưu tiên quy ước của locale, nên nếu pass `vi-VN` + `USD` sẽ ra `1.500 US$`
 * (postfix kiểu Việt) thay vì `$1,500` (prefix kiểu Mỹ). User muốn thấy symbol
 * theo quy ước "quê hương" của currency → map locale theo currency.
 */

export function useCurrency(): {
  /** Format số → chuỗi đầy đủ kèm symbol đúng vị trí theo "home locale" của currency.
   *  Đây là API chính — gọi `fmt(value)` thay vì tự ghép symbol + số. */
  format: (value: number) => string;
  /** ISO 4217 đang dùng — tiện cho debug / log / pass vào Stripe. */
  code: string;
} {
  const { currentBranch } = useBrand();
	  const code = currentBranch.currency_code || "JPY";

	  const formatter = useMemo(() => {
	    const upper = code.toUpperCase();
	    const maxFractionDigits = ZERO_FRACTION.has(upper)
	      ? 0
	      : THREE_DECIMAL.has(upper)
	        ? 3
	        : 2;
	    return new Intl.NumberFormat(currencyToHomeLocale(code), {
	      style: "currency",
	      currency: code,
	      // JPY/VND không dùng phần lẻ; một số tiền tệ dùng 3 chữ số thập phân; còn lại mặc định 2 chữ số.
	      maximumFractionDigits: maxFractionDigits,
	      minimumFractionDigits: ZERO_FRACTION.has(upper) ? 0 : 0,
	    });
	  }, [code]);

  const format = useCallback(
    (value: number) => formatter.format(Number.isFinite(value) ? value : 0),
    [formatter],
  );

  return { format, code };
}

/**
 * Pure helper khi không có context (vd. inside cart-context provider tự nó).
 * Caller chỉ cần truyền `currency` — locale tự derive theo home locale của currency.
 */
export function formatCurrency(value: number, currency: string): string {
	  // Guard against a missing/empty currency — Intl.NumberFormat throws when
	  // `currency` is undefined/empty. Falls back to JPY (platform default).
	  const safeCurrency = currency || "JPY";
	  const upper = safeCurrency.toUpperCase();
	  const maxFractionDigits = ZERO_FRACTION.has(upper)
	    ? 0
	    : THREE_DECIMAL.has(upper)
	      ? 3
	      : 2;
	  const fmt = new Intl.NumberFormat(currencyToHomeLocale(safeCurrency), {
	    style: "currency",
	    currency: safeCurrency,
	    maximumFractionDigits: maxFractionDigits,
	    minimumFractionDigits: ZERO_FRACTION.has(upper) ? 0 : 0,
	  });
	  return fmt.format(Number.isFinite(value) ? value : 0);
}

// ---- internal helpers ----

/**
 * Map ISO 4217 currency → BCP 47 locale của quốc gia phát hành. Đảm bảo
 * symbol position và separator khớp quy ước "quê hương" của currency thay
 * vì quy ước của UI locale.
 */
function currencyToHomeLocale(code: string): string {
  switch (code) {
    case "USD": return "en-US";
    case "JPY": return "ja-JP";
    case "VND": return "vi-VN";
    case "EUR": return "de-DE";
    case "GBP": return "en-GB";
    case "KRW": return "ko-KR";
    case "CNY": return "zh-CN";
    case "TWD": return "zh-TW";
    case "HKD": return "zh-HK";
    case "SGD": return "en-SG";
    case "THB": return "th-TH";
    case "IDR": return "id-ID";
    case "MYR": return "ms-MY";
    case "PHP": return "en-PH";
    case "AUD": return "en-AU";
    case "CAD": return "en-CA";
    default: return "en-US";
  }
}
