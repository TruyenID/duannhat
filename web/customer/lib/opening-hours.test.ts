import assert from "node:assert/strict";
import test from "node:test";
import { closingTimeLabel, hasPublishedHours, isOpenAt, nextOpening } from "./opening-hours.ts";
import type { WeeklyHoursMap } from "@/data/brands";

/**
 * #1160 — must agree with backend `BranchOpeningHours` case for case: the two
 * gate the same pickup slot, and a disagreement means the customer is told
 * "fine" and then handed a 422.
 */
const weekdays = (open: string, close: string): WeeklyHoursMap => ({
  mon: { open, close, closed: false },
  tue: { open, close, closed: false },
  wed: { open, close, closed: false },
  thu: { open, close, closed: false },
  fri: { open, close, closed: false },
  sat: { open, close, closed: false },
  sun: { closed: true },
});

// 2026-04-27 is a Monday.
const tokyo = (isoLocal: string) => new Date(`2026-04-27T${isoLocal}+09:00`);

test("accepts inside the window, rejects before opening and after closing", () => {
  const hours = weekdays("09:00", "18:00");

  assert.equal(isOpenAt(hours, tokyo("17:59:00"), "Asia/Tokyo"), true);
  assert.equal(isOpenAt(hours, tokyo("18:01:00"), "Asia/Tokyo"), false);
  assert.equal(isOpenAt(hours, tokyo("08:59:00"), "Asia/Tokyo"), false);
});

test("rejects a day marked closed", () => {
  // 2026-04-26 is a Sunday.
  const sunday = new Date("2026-04-26T12:00:00+09:00");

  assert.equal(isOpenAt(weekdays("09:00", "18:00"), sunday, "Asia/Tokyo"), false);
});

test("treats close <= open as an overnight window", () => {
  const hours: WeeklyHoursMap = {
    mon: { open: "18:00", close: "02:00", closed: false },
    tue: { closed: true },
  };

  assert.equal(isOpenAt(hours, tokyo("23:00:00"), "Asia/Tokyo"), true);
  // The Tuesday 01:00 tail still belongs to Monday's window…
  assert.equal(isOpenAt(hours, new Date("2026-04-28T01:00:00+09:00"), "Asia/Tokyo"), true);
  // …but 03:00 is past it, and Tuesday itself is closed.
  assert.equal(isOpenAt(hours, new Date("2026-04-28T03:00:00+09:00"), "Asia/Tokyo"), false);
});

test("imposes no constraint when hours are unpublished or unusable", () => {
  // Fail-open, matching the server: most shops never filled weekly_hours in.
  assert.equal(hasPublishedHours(null), false);
  assert.equal(isOpenAt(null, tokyo("03:00:00"), "Asia/Tokyo"), true);
  assert.equal(isOpenAt({}, tokyo("03:00:00"), "Asia/Tokyo"), true);
  assert.equal(
    isOpenAt({ mon: { open: "noon", close: "18:00" } }, tokyo("12:00:00"), "Asia/Tokyo"),
    true,
  );
});

test("judges the instant on the SHOP's clock, not the device's", () => {
  const hours = weekdays("09:00", "22:00");
  // One instant: 23:30 in Tokyo (shut), 21:30 in Ho Chi Minh, 14:30 in London.
  const instant = new Date("2026-04-27T14:30:00Z");

  assert.equal(isOpenAt(hours, instant, "Asia/Tokyo"), false);
  assert.equal(isOpenAt(hours, instant, "Asia/Ho_Chi_Minh"), true);
  assert.equal(isOpenAt(hours, instant, "Europe/London"), true);
});

test("#1447 — a MISSING timezone silently swaps in the device clock", () => {
  // The fallback below is deliberate for a *garbage* zone, but it fires just as
  // quietly when a caller simply forgot to pass one — and then the shop's wall
  // clock is read on the customer's phone. That is the whole of #1447: the
  // dine-in menu built its branch from GET /customer/tables/{qrToken}, which
  // carried `weekly_hours` but not `timezone`, so a phone in Vietnam judged a
  // Tokyo shop two hours early and showed "Cửa hàng đã đóng cửa" while it was
  // open. Take-away was unaffected — it resolves its branch from
  // /customer/branches, which has carried `timezone` since #1160.
  //
  // Pinned here so the size of the hazard is on the record: dropping the zone
  // does not degrade, it INVERTS the verdict.
  const hours = weekdays("11:00", "22:00");
  // 11:51 Tokyo — open. The same instant is 09:51 in Ho Chi Minh — before
  // opening. Both are the same Monday, so the weekday can't confound it.
  const instant = new Date("2026-04-27T02:51:00Z");

  const originalTz = process.env.TZ;
  process.env.TZ = "Asia/Ho_Chi_Minh";
  try {
    assert.equal(isOpenAt(hours, instant, "Asia/Tokyo"), true);
    assert.equal(isOpenAt(hours, instant, undefined), false);
    assert.equal(isOpenAt(hours, instant, null), false);
  } finally {
    process.env.TZ = originalTz;
  }
});

test("falls back to the device clock for an unknown timezone instead of blocking", () => {
  // A garbage zone must not make every slot look closed — checkout would be
  // unusable for that shop.
  //
  // #1200 — the fixture must be open on EVERY day, `weekdays()` closes Sunday.
  // The fallback reads the DEVICE clock, and the instant here (2026-04-27
  // 12:00 +09:00 = 03:00 UTC, a Monday) is still Sunday 23:00 on any machine
  // at UTC-4 or further west. The assertion then failed on which weekday the
  // runner happened to be in rather than on the fallback it is about — green
  // in Tokyo and UTC, red in America/*, which is where CI runs.
  const openAlways: WeeklyHoursMap = {
    ...weekdays("00:00", "23:59"),
    sun: { open: "00:00", close: "23:59", closed: false },
  };

  assert.equal(isOpenAt(openAlways, tokyo("12:00:00"), "Mars/Olympus"), true);
});

test("quotes the closing time of the targeted day", () => {
  assert.equal(closingTimeLabel(weekdays("09:00", "18:00"), tokyo("20:00:00"), "Asia/Tokyo"), "18:00");
  // Sunday is closed outright — nothing to quote.
  assert.equal(
    closingTimeLabel(weekdays("09:00", "18:00"), new Date("2026-04-26T12:00:00+09:00"), "Asia/Tokyo"),
    null,
  );
});

// =============================================================================
// #1167 — nextOpening: what we promise a customer who arrived while shut
// =============================================================================

test("finds the next opening later the same day", () => {
  // 05:00 Monday, opens 09:00 — today, not tomorrow.
  assert.deepEqual(nextOpening(weekdays("09:00", "18:00"), tokyo("05:00:00"), "Asia/Tokyo"), {
    dayOffset: 0,
    day: "mon",
    time: "09:00",
  });
});

test("rolls to the next day once today's window has opened", () => {
  // 23:00 Monday — Monday's 09:00 is behind us, so Tuesday's is next.
  assert.deepEqual(nextOpening(weekdays("09:00", "18:00"), tokyo("23:00:00"), "Asia/Tokyo"), {
    dayOffset: 1,
    day: "tue",
    time: "09:00",
  });
});

test("skips closed days when looking for the next opening", () => {
  // Saturday 19:00, past closing → Sunday is 定休日 → Monday 09:00.
  const saturdayEvening = new Date("2026-04-25T19:00:00+09:00");

  assert.deepEqual(nextOpening(weekdays("09:00", "18:00"), saturdayEvening, "Asia/Tokyo"), {
    dayOffset: 2,
    day: "mon",
    time: "09:00",
  });
});

test("wraps a whole week for a shop open on a single weekday", () => {
  const saturdayOnly: WeeklyHoursMap = { sat: { open: "10:00", close: "16:00", closed: false } };
  const saturdayAfterClosing = new Date("2026-04-25T17:00:00+09:00");

  assert.deepEqual(nextOpening(saturdayOnly, saturdayAfterClosing, "Asia/Tokyo"), {
    dayOffset: 7,
    day: "sat",
    time: "10:00",
  });
});

test("promises nothing when the shop publishes no hours", () => {
  assert.equal(nextOpening(null, tokyo("05:00:00"), "Asia/Tokyo"), null);
  assert.equal(nextOpening({}, tokyo("05:00:00"), "Asia/Tokyo"), null);
});

test("reads the next opening on the SHOP's clock, not the device's", () => {
  const hours = weekdays("09:00", "22:00");
  // 2026-04-27T14:30:00Z = Monday 23:30 Tokyo (shut) → Tuesday 09:00 Tokyo.
  // Ho Chi Minh reads the same instant as Monday 21:30 — still open, but its
  // next opening is Tuesday too; London (15:30 Mon) likewise.
  const instant = new Date("2026-04-27T14:30:00Z");

  assert.deepEqual(nextOpening(hours, instant, "Asia/Tokyo"), { dayOffset: 1, day: "tue", time: "09:00" });
  assert.deepEqual(nextOpening(hours, instant, "Asia/Ho_Chi_Minh"), { dayOffset: 1, day: "tue", time: "09:00" });
  assert.deepEqual(nextOpening(hours, instant, "Europe/London"), { dayOffset: 1, day: "tue", time: "09:00" });
});
