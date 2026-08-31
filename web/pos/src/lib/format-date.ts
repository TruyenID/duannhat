/**
 * Centralised date / time formatting for pos-web.
 *
 * Every UI surface in pos-web that renders a date or time MUST go
 * through this module. Direct `Intl.DateTimeFormat`, `toLocaleString`,
 * or hand-rolled `${y}/${m}/${d}` formatting is a review-block — they
 * silently drift across the app and break locale parity.
 *
 * Why this exists:
 *   - Each locale has a different expected format (en `Jun 4, 2026`,
 *     ja `2026/06/04`, vi `04/06/2026`). Hard-coding `en-GB` "to keep
 *     dd/MM/yyyy" only works for ja+vi by accident and is wrong for en
 *     users who expect MM/DD/YYYY.
 *   - POS terminals are 24-hour shop floor surfaces — operators don't
 *     want "2:30 PM"; everything is `14:30` regardless of locale.
 *   - The shop receipt + dashboard + reports all need the SAME formatted
 *     output for a given timestamp so cross-checking by sight works.
 *
 * Locale routing: pos-web's `useTranslation()` exposes "ja" / "en" /
 * "vi". `bcp47()` maps each to a real Intl tag so the underlying
 * locale data resolves correctly.
 */

import type { LocaleCode } from "@/i18n";

const LOCALE_TAG: Record<LocaleCode, string> = {
  ja: "ja-JP",
  // en-US so end-of-September date components stay in MM/DD ordering
  // for receipts displayed to English-speaking customers (operators
  // pick their own locale; this is the customer-facing fallback).
  en: "en-US",
  vi: "vi-VN",
};

function bcp47(locale: string): string {
  return LOCALE_TAG[locale as LocaleCode] ?? locale;
}

function toDate(value: Date | string | number): Date {
  return value instanceof Date ? value : new Date(value);
}

// ─────────────────────────────────────────────────────────────────────
//  ISO YYYY-MM-DD — locale-independent, for API params + cache keys
// ─────────────────────────────────────────────────────────────────────

/**
 * `YYYY-MM-DD` in the browser's local timezone. NOT locale-formatted:
 * use for query strings (`?from=YYYY-MM-DD`), cache keys, and any
 * other value that travels over the wire. For display, call
 * `formatDate()` instead.
 */
export function formatYmd(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

// ─────────────────────────────────────────────────────────────────────
//  Locale-aware display formats
// ─────────────────────────────────────────────────────────────────────

/**
 * Numeric date with leading zeros — the densest form, used for table
 * cells + filter chips where we want a compact comparable string.
 *   ja → `2026/06/04`
 *   en → `06/04/2026`
 *   vi → `04/06/2026`
 */
export function formatDate(d: Date | string | number, locale: string): string {
  return new Intl.DateTimeFormat(bcp47(locale), {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(toDate(d));
}

/**
 * Date with the spelled-out month name. Used for headers + section
 * titles where the visual weight pays for the extra width.
 *   ja → `2026年6月4日`
 *   en → `June 4, 2026`
 *   vi → `4 tháng 6, 2026`
 */
export function formatDateLong(d: Date | string | number, locale: string): string {
  return new Intl.DateTimeFormat(bcp47(locale), {
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(toDate(d));
}

/**
 * Numeric date prefixed with the abbreviated weekday — the format
 * the POS header uses next to the clock, where the day-of-week is the
 * primary cue and the date itself is secondary context.
 *   ja → `木 2026/06/04`
 *   en → `Thu, 06/04/2026`
 *   vi → `Th 5, 04/06/2026`
 */
export function formatDateWithWeekday(
  d: Date | string | number,
  locale: string,
): string {
  return new Intl.DateTimeFormat(bcp47(locale), {
    weekday: "short",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(toDate(d));
}

/**
 * Year + month only, used for monthly granularity pickers.
 *   ja → `2026年6月`
 *   en → `Jun 2026`
 *   vi → `Tháng 6 2026`
 */
export function formatYearMonth(
  d: Date | string | number,
  locale: string,
): string {
  return new Intl.DateTimeFormat(bcp47(locale), {
    year: "numeric",
    month: "short",
  }).format(toDate(d));
}

// ─────────────────────────────────────────────────────────────────────
//  Time + datetime — 24-hour for every locale (POS convention)
// ─────────────────────────────────────────────────────────────────────

/**
 * 24-hour clock. `withSeconds=true` by default (matches the header
 * tick); pass `false` for receipts where the second precision is noise.
 *   all locales → `14:30:25`
 */
export function formatTime(
  d: Date | string | number,
  locale: string,
  options: { withSeconds?: boolean } = {},
): string {
  const { withSeconds = true } = options;
  return new Intl.DateTimeFormat(bcp47(locale), {
    hour: "2-digit",
    minute: "2-digit",
    ...(withSeconds && { second: "2-digit" }),
    hour12: false,
  }).format(toDate(d));
}

/**
 * Numeric date + 24-hour time. Used by receipts and update-timestamp
 * labels — the format every operator audits against the order log.
 *   ja → `2026/06/04 14:30:25`
 *   en → `06/04/2026, 14:30:25`
 *   vi → `04/06/2026 14:30:25`
 */
export function formatDateTime(
  d: Date | string | number,
  locale: string,
  options: { withSeconds?: boolean } = {},
): string {
  const { withSeconds = true } = options;
  return new Intl.DateTimeFormat(bcp47(locale), {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    ...(withSeconds && { second: "2-digit" }),
    hour12: false,
  }).format(toDate(d));
}

/** Numeric date range — `2026/06/01 → 2026/06/30`. */
export function formatDateRange(
  from: Date | string | number,
  to: Date | string | number,
  locale: string,
): string {
  return `${formatDate(from, locale)} → ${formatDate(to, locale)}`;
}
