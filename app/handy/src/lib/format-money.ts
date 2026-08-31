const LOCALE_MAP: Record<string, string> = {
  JPY: 'ja-JP',
  VND: 'vi-VN',
  USD: 'en-US',
  EUR: 'de-DE',
};

export function formatMoney(amount: number | string, currencyCode: string): string {
  const n = typeof amount === 'string' ? parseFloat(amount) : amount;
  if (isNaN(n)) return '—';

  try {
    const locale = LOCALE_MAP[currencyCode] ?? 'ja-JP';
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: currencyCode,
      minimumFractionDigits: 0,
      maximumFractionDigits: currencyCode === 'JPY' ? 0 : 2,
    }).format(n);
  } catch {
    // Fallback: prepend ¥ for JPY, otherwise show code + amount
    const rounded = Math.round(n);
    if (currencyCode === 'JPY') return `¥${rounded.toLocaleString()}`;
    return `${currencyCode} ${rounded.toLocaleString()}`;
  }
}
