import type { LocaleCode } from "@/i18n";

const LOCALE_TO_INTL: Record<LocaleCode, string> = {
  vi: "vi-VN",
  en: "en-US",
  ja: "ja-JP",
};

/**
 * Format an ISO date string from the backend as a locale-aware date.
 * vi → DD/MM/YYYY, en → MM/DD/YYYY, ja → YYYY/MM/DD
 *
 * Pass `timezone` (IANA identifier e.g. "Asia/Tokyo") to display in the
 * branch/shop timezone. Omitting it — or passing null, which is what
 * `useShopTimezone()` returns outside a shop route — falls back to the
 * browser's local TZ.
 */
export function formatDate(
  iso: string | null | undefined,
  locale: LocaleCode,
  timezone?: string | null
): string {
  if (!iso) return "—";
  // Date-only fields (YYYY-MM-DD: expiry_date, valid_from/until, …) carry no
  // time or zone. Parse as UTC midnight and render in UTC so the stored
  // calendar date never rolls back a day in a negative-offset timezone
  // (e.g. "2026-06-25" must stay the 25th even in America/Los_Angeles).
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(iso);
  const d = new Date(dateOnly ? `${iso}T00:00:00Z` : iso);
  if (Number.isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat(LOCALE_TO_INTL[locale], {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    ...(dateOnly ? { timeZone: "UTC" } : timezone ? { timeZone: timezone } : {}),
  }).format(d);
}

/** Format an ISO datetime string — time portion only (HH:mm), locale-aware. */
export function formatTime(
  iso: string | null | undefined,
  locale: LocaleCode,
  timezone?: string | null
): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat(LOCALE_TO_INTL[locale], {
    hour: "2-digit",
    minute: "2-digit",
    ...(timezone ? { timeZone: timezone } : {}),
  }).format(d);
}

/**
 * Format an ISO datetime string from the backend including time.
 * vi → DD/MM/YYYY HH:mm, en → MM/DD/YYYY HH:mm, ja → YYYY/MM/DD HH:mm
 *
 * Pass `timezone` (IANA identifier e.g. "Asia/Tokyo") to display in the
 * branch/shop timezone. Omitting it — or passing null, which is what
 * `useShopTimezone()` returns outside a shop route — falls back to the
 * browser's local TZ.
 */
export function formatDateTime(
  iso: string | null | undefined,
  locale: LocaleCode,
  timezone?: string | null
): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat(LOCALE_TO_INTL[locale], {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    ...(timezone ? { timeZone: timezone } : {}),
  }).format(d);
}

/**
 * Whole-day difference between a date-only value (`YYYY-MM-DD`, e.g. an expiry
 * date) and "today" in the given branch timezone. Positive = N days in the
 * future, 0 = today, negative = already past.
 *
 * Both sides are anchored at UTC midnight so the subtraction is timezone-clean:
 * the expiry is a calendar date (no instant/zone — it must NOT be re-projected
 * through a timezone), while "today" is the calendar date the branch is
 * currently on, derived once from `nowMs`. The previous inline version parsed
 * the expiry at the BROWSER's local midnight and then re-projected it into the
 * branch timezone — a spurious second conversion that produced an off-by-one
 * (and a premature "Expired" badge) whenever browser TZ ≠ branch TZ.
 */
export function daysUntilExpiry(dateOnly: string, nowMs: number, timezone: string): number {
  const expiryMidnight = Date.parse(`${dateOnly}T00:00:00Z`);
  // The branch's current calendar date (YYYY-MM-DD), then anchored at UTC.
  const branchToday = new Intl.DateTimeFormat("en-CA", { timeZone: timezone }).format(
    new Date(nowMs)
  );
  const todayMidnight = Date.parse(`${branchToday}T00:00:00Z`);
  return Math.ceil((expiryMidnight - todayMidnight) / 86_400_000);
}

/**
 * `YYYY-MM-DD` for the LOCAL calendar date of `date` (default: now). Use this
 * for default date-range filters instead of `date.toISOString().slice(0, 10)`,
 * which returns the UTC date — in a positive-offset timezone (e.g. Asia/Tokyo)
 * that is the PREVIOUS day before 09:00 local, silently shifting the window a
 * day earlier and excluding today's data.
 */
export function localDateString(date: Date = new Date()): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}
