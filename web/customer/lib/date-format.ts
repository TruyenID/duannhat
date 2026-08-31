import type { Locale } from "@/i18n/config";

/**
 * Locale-aware date/time rendering for the guest surface (#1261).
 *
 * Six screens formatted dates with a hardcoded locale — two to `vi-VN`, four to
 * `ja-JP` — in an app that runs three languages and defaults to `ja`. The same
 * instant reads:
 *
 *     ja-JP   2026/07/30
 *     vi-VN   30/07/2026
 *     en-US   07/30/2026
 *
 * so a Japanese guest at a table saw the Vietnamese order and a Vietnamese guest
 * reading their own order history saw the Japanese one. Wrong in both
 * directions, on the screens customers actually look at.
 *
 * The mapping already existed inline in components/ui/calendar.tsx and in
 * lib/currency.ts; it lives here now so a seventh screen has somewhere to reach
 * for rather than inventing its own.
 *
 * The timezone: these default to the DEVICE's zone, so a guest reading an order
 * from home in another country sees a time the shop never recorded — the same
 * defect #1248 fixed for admin. Fixing it everywhere needs the branch timezone
 * in the order payload, which most of these screens still do not receive.
 *
 * godx-tempo#1767 added the opt-in `timeZone` argument for the one screen that
 * DOES have it — `/checkout`, where the pickup picker sits next to opening
 * hours read on the shop's clock, and the two disagreeing is the bug. Pass it
 * where the branch zone is known; leave it off and nothing changes.
 */
const LOCALE_TO_INTL: Record<Locale, string> = {
  ja: "ja-JP",
  vi: "vi-VN",
  en: "en-US",
};

export function intlLocale(locale: string): string {
  return LOCALE_TO_INTL[locale as Locale] ?? LOCALE_TO_INTL.ja;
}

/** `30/07/2026` in vi, `2026/07/30` in ja, `07/30/2026` in en. */
export function formatGuestDate(value: string | number | Date, locale: string): string {
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return "";

  return new Intl.DateTimeFormat(intlLocale(locale), {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(date);
}

/**
 * `14:05` — 24-hour in every supported locale, but grouped per Intl.
 *
 * `timeZone` is optional and defaults to the device's (see the module note).
 */
export function formatGuestTime(
  value: string | number | Date,
  locale: string,
  timeZone?: string | null,
): string {
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return "";

  const render = (zone?: string | null) =>
    new Intl.DateTimeFormat(intlLocale(locale), {
      ...(zone ? { timeZone: zone } : {}),
      hour: "2-digit",
      minute: "2-digit",
      hour12: false,
    }).format(date);

  try {
    return render(timeZone);
  } catch {
    // An IANA name this runtime doesn't know makes Intl throw. A bad config
    // string must not take down the screen it is printed on — and falling back
    // to the device clock is what `lib/branch-clock.ts` and `isOpenAt` already
    // do, so the whole screen still agrees with itself.
    return render(null);
  }
}

/** Date and time together, the shape the account screens render. */
export function formatGuestDateTime(value: string | number | Date, locale: string): string {
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return "";

  return new Intl.DateTimeFormat(intlLocale(locale), {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(date);
}
