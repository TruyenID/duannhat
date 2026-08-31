import { describe, expect, it } from "vitest";
import {
  formatDate,
  formatDateLong,
  formatDateRange,
  formatDateTime,
  formatDateWithWeekday,
  formatTime,
  formatYearMonth,
  formatYmd,
} from "./format-date";

const SAMPLE = new Date(2026, 5, 4, 14, 30, 25); // 2026-06-04 14:30:25 local time

describe("formatYmd", () => {
  it("returns ISO YYYY-MM-DD in local timezone", () => {
    expect(formatYmd(SAMPLE)).toBe("2026-06-04");
  });

  it("pads single-digit month and day", () => {
    expect(formatYmd(new Date(2026, 0, 1))).toBe("2026-01-01");
  });
});

describe("formatDate", () => {
  it("uses MM/DD ordering for Japanese", () => {
    expect(formatDate(SAMPLE, "ja")).toBe("2026/06/04");
  });

  it("uses MM/DD/YYYY for English (en-US)", () => {
    expect(formatDate(SAMPLE, "en")).toBe("06/04/2026");
  });

  it("uses DD/MM/YYYY for Vietnamese", () => {
    expect(formatDate(SAMPLE, "vi")).toBe("04/06/2026");
  });

  it("accepts ISO string input", () => {
    expect(formatDate("2026-06-04T00:00:00", "ja")).toBe("2026/06/04");
  });
});

describe("formatDateLong", () => {
  it("ja uses 年 月 日 markers", () => {
    expect(formatDateLong(SAMPLE, "ja")).toContain("2026年");
    expect(formatDateLong(SAMPLE, "ja")).toContain("6月");
  });

  it("en uses spelled-out month name", () => {
    expect(formatDateLong(SAMPLE, "en")).toContain("June");
    expect(formatDateLong(SAMPLE, "en")).toContain("2026");
  });

  it("vi uses 'tháng' month marker", () => {
    expect(formatDateLong(SAMPLE, "vi").toLowerCase()).toContain("tháng 6");
  });
});

describe("formatTime", () => {
  it("uses 24-hour clock for every locale", () => {
    expect(formatTime(SAMPLE, "ja")).toBe("14:30:25");
    expect(formatTime(SAMPLE, "en")).toBe("14:30:25");
    expect(formatTime(SAMPLE, "vi")).toBe("14:30:25");
  });

  it("can omit the seconds component", () => {
    expect(formatTime(SAMPLE, "ja", { withSeconds: false })).toBe("14:30");
  });
});

describe("formatDateTime", () => {
  it("composes ja date + 24h time", () => {
    const out = formatDateTime(SAMPLE, "ja");
    expect(out).toContain("2026/06/04");
    expect(out).toContain("14:30:25");
  });

  it("composes vi date + 24h time in dd/mm/yyyy order", () => {
    const out = formatDateTime(SAMPLE, "vi");
    expect(out).toContain("04/06/2026");
    expect(out).toContain("14:30:25");
  });
});

describe("formatYearMonth", () => {
  it("ja yields 2026年6月", () => {
    expect(formatYearMonth(SAMPLE, "ja")).toBe("2026年6月");
  });

  it("en yields 'Jun 2026'", () => {
    expect(formatYearMonth(SAMPLE, "en")).toBe("Jun 2026");
  });
});

describe("formatDateRange", () => {
  it("joins two locale-formatted dates with an arrow", () => {
    const from = new Date(2026, 5, 1);
    const to = new Date(2026, 5, 30);
    expect(formatDateRange(from, to, "ja")).toBe("2026/06/01 → 2026/06/30");
    expect(formatDateRange(from, to, "vi")).toBe("01/06/2026 → 30/06/2026");
  });
});

describe("formatDateWithWeekday", () => {
  it("prefixes a short weekday label", () => {
    const out = formatDateWithWeekday(SAMPLE, "ja");
    // 2026-06-04 is Thursday → 木 in JA
    expect(out).toContain("木");
    expect(out).toContain("2026/06/04");
  });
});
