import { describe, it, expect } from "vitest";
import { formatDate, formatDateTime, daysUntilExpiry, localDateString } from "./date";

/**
 * Bug round 1 — date-only fields shift to the previous day in negative-offset
 * timezones.
 *
 * Backend `DateString` fields (expiry_date, valid_from, valid_until, …) send a
 * date-only string like "2026-06-25". `new Date("2026-06-25")` parses as UTC
 * midnight, so formatting it through a negative-offset `timeZone` rolls the
 * calendar date back one day. Positive-offset zones (Asia/Tokyo, the default)
 * happen to be safe, which is why this slipped through.
 *
 * See the deliberate `+ "T00:00:00"` workaround already used in
 * material-lots/[id]/page.tsx — formatDate should do the equivalent so every
 * call site is correct.
 */
describe("formatDate — date-only input across timezones", () => {
  it("keeps the stored calendar date in a negative-offset timezone (regression)", () => {
    // 2026-06-25 must render as the 25th regardless of viewer timezone.
    expect(formatDate("2026-06-25", "en", "America/Los_Angeles")).toBe("06/25/2026");
  });

  it("is consistent for a positive-offset timezone", () => {
    expect(formatDate("2026-06-25", "en", "Asia/Tokyo")).toBe("06/25/2026");
  });

  it("formats the same calendar date for vi/ja locales", () => {
    expect(formatDate("2026-06-25", "vi", "America/Los_Angeles")).toBe("25/06/2026");
    expect(formatDate("2026-06-25", "ja", "America/Los_Angeles")).toBe("2026/06/25");
  });

  it("still renders a full datetime correctly (control — has explicit time)", () => {
    // A real datetime with an explicit instant is unaffected by the date-only trap.
    const out = formatDateTime("2026-06-25T10:30:00+09:00", "ja", "Asia/Tokyo");
    expect(out).toContain("2026/06/25");
    expect(out).toContain("10:30");
  });

  it("returns the placeholder for empty/invalid input", () => {
    expect(formatDate(null, "en")).toBe("—");
    expect(formatDate("not-a-date", "en")).toBe("—");
  });
});

/**
 * Bug round 2 — DaysUntilBadge off-by-one. The expiry-day diff must not depend
 * on the browser timezone: the expiry is a calendar date, "today" is the
 * branch's calendar date. Deterministic given an explicit nowMs + branch tz.
 */
describe("daysUntilExpiry", () => {
  // 2026-06-30 12:00 in America/Los_Angeles (UTC-7) → branch "today" = 06-30.
  const nowLA = Date.parse("2026-06-30T19:00:00Z");

  it("counts a future expiry from the branch's today", () => {
    expect(daysUntilExpiry("2026-07-01", nowLA, "America/Los_Angeles")).toBe(1);
    expect(daysUntilExpiry("2026-07-07", nowLA, "America/Los_Angeles")).toBe(7);
  });

  it("returns 0 for an expiry that is the branch's today (regression: not -1)", () => {
    expect(daysUntilExpiry("2026-06-30", nowLA, "America/Los_Angeles")).toBe(0);
  });

  it("returns negative for an already-past expiry", () => {
    expect(daysUntilExpiry("2026-06-29", nowLA, "America/Los_Angeles")).toBe(-1);
  });

  it("is identical regardless of the machine timezone (uses branch tz only)", () => {
    // Same instant, expressed two ways — the result depends only on the branch
    // tz argument, never the host. 2026-06-30 in Tokyo, expiry tomorrow.
    const nowTokyo = Date.parse("2026-06-30T01:00:00Z"); // 10:00 JST on the 30th
    expect(daysUntilExpiry("2026-07-01", nowTokyo, "Asia/Tokyo")).toBe(1);
  });
});

/**
 * Bug round 2 — default date-range filters used UTC "today" (toISOString),
 * dropping today's data in positive-offset timezones. localDateString uses the
 * LOCAL calendar date and is deterministic for a locally-constructed Date.
 */
describe("localDateString", () => {
  it("returns the LOCAL calendar date (regression: not the UTC date)", () => {
    // Local June 30, 07:00 — toISOString() would report 06-29 in UTC+offset
    // timezones; localDateString must keep the local day.
    expect(localDateString(new Date(2026, 5, 30, 7, 0))).toBe("2026-06-30");
  });

  it("zero-pads month and day", () => {
    expect(localDateString(new Date(2026, 0, 5, 12, 0))).toBe("2026-01-05");
  });
});
