// src/lib/format.ts
import type { OrderItemExtra } from '../types/kiosk';

/**
 * Number-formatting locale derived from the CURRENCY, not the UI locale.
 *
 * customer-web — the other customer-facing surface — settled this and wrote
 * down why: Intl.NumberFormat follows the locale's conventions, so pairing a
 * vi-VN locale with USD yields "1.500 US$" instead of "$1,500". A guest expects
 * money in the conventions of the currency they are paying in.
 *
 * This module previously hardcoded 'ja-VN'… 'ja-JP' for every currency, so a
 * Vietnamese guest at a kiosk in Vietnam read prices grouped the Japanese way:
 * 1234567 rendered "1,234,567đ" where vi-VN gives "1.234.567đ". Vietnamese uses
 * "." for thousands and "," for decimals, so on a small amount ("1,500đ") the
 * separator is actively misleading.
 */
const CURRENCY_TO_LOCALE: Record<string, string> = {
  JPY: 'ja-JP',
  VND: 'vi-VN',
  USD: 'en-US',
  EUR: 'de-DE',
  KRW: 'ko-KR',
  CNY: 'zh-CN',
  THB: 'th-TH',
  IDR: 'id-ID',
};

function localeForCurrency(currency: string): string {
  return CURRENCY_TO_LOCALE[currency.toUpperCase()] ?? 'ja-JP';
}

export function formatCurrency(amount: number, currency = 'JPY'): string {
  // VND: ký hiệu đồng "đ" đứng SAU số tiền (1.000đ). JPY (￥) và USD ($)
  // giữ ký hiệu đứng TRƯỚC theo chuẩn Intl.
  if (currency === 'VND') {
    return `${new Intl.NumberFormat(localeForCurrency(currency)).format(amount)}đ`;
  }
  return new Intl.NumberFormat(localeForCurrency(currency), {
    style: 'currency',
    currency,
  }).format(amount);
}

/**
 * Display fragments for an order-item extra. `add` extras render `+ Label` with
 * the line-total price; when qty>1 the label is prefixed `5 x Egg` to match the
 * workstation receipt. `remove` extras render `− Label` (no qty prefix, no
 * price when 0) so "no sugar" doesn't show a stray "¥0".
 *
 * `linePrice` is the qty-aware total (price × quantity); use it for both
 * display and any extrasTotal math so the sub-line and the order grand-total
 * stay consistent.
 */
export function formatExtraDisplay(
  extra: OrderItemExtra,
  currency = 'JPY',
): {
  sign: '+' | '−';
  isRemove: boolean;
  label: string;
  linePrice: number;
  price: string | null;
} {
  const isRemove = extra.modifier_type === 'remove';
  const sign: '+' | '−' = isRemove ? '−' : '+';
  const qty = extra.quantity ?? 1;
  const linePrice = extra.price * qty;
  const label = !isRemove && qty > 1 ? `${qty} x ${extra.label}` : extra.label;
  let price: string | null;
  if (linePrice === 0) {
    price = isRemove ? null : formatCurrency(0, currency);
  } else {
    price = isRemove
      ? `-${formatCurrency(linePrice, currency)}`
      : formatCurrency(linePrice, currency);
  }
  return { sign, isRemove, label, linePrice, price };
}

export function formatCountdown(totalSeconds: number): string {
  const m = Math.floor(totalSeconds / 60);
  const s = totalSeconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

/**
 * Normalize a free-typed amount into half-width digits only.
 *
 * A Japanese soft keyboard can emit full-width numerals (７７５) and users may
 * type grouping separators (1,000) or a currency sign (¥775). `Number()`
 * returns NaN for all of those, which would silently reject a valid cash
 * amount. Strip everything but digits and fold full-width → half-width so the
 * value always parses. JPY/VND are integer currencies, so decimals are dropped.
 */
export function sanitizeAmountInput(raw: string): string {
  return raw
    .replace(/[０-９]/g, (d) => String.fromCharCode(d.charCodeAt(0) - 0xfee0))
    .replace(/[^\d]/g, '');
}
