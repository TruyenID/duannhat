import { describe, expect, it } from "vitest";
import { parseScheduleTime, pickActiveMenu } from "./pick-active-menu";

/** Minutes-since-midnight → a Date on a fixed day. Only H:M is ever read. */
function at(hhmm: string): Date {
  const [h, m] = hhmm.split(":").map(Number);
  return new Date(2026, 7, 4, h, m, 0);
}

const morning = { id: "m", start_time: "06:00:00", end_time: "11:00:00" };
const lunch = { id: "l", start_time: "11:00:00", end_time: "15:00:00" };
const night = { id: "n", start_time: "22:00:00", end_time: "02:00:00" };

describe("parseScheduleTime", () => {
  it("accepts both HH:MM and HH:MM:SS", () => {
    expect(parseScheduleTime("07:30")).toBe(450);
    expect(parseScheduleTime("07:30:00")).toBe(450);
  });

  it("returns null for absent or unparseable values", () => {
    expect(parseScheduleTime(null)).toBeNull();
    expect(parseScheduleTime(undefined)).toBeNull();
    expect(parseScheduleTime("")).toBeNull();
    expect(parseScheduleTime("lunch")).toBeNull();
  });
});

describe("pickActiveMenu", () => {
  it("picks the menu whose window covers now", () => {
    expect(pickActiveMenu([morning, lunch], at("12:00"))?.id).toBe("l");
    expect(pickActiveMenu([morning, lunch], at("07:00"))?.id).toBe("m");
  });

  it("handles a window crossing midnight", () => {
    expect(pickActiveMenu([night], at("23:30"))?.id).toBe("n");
    expect(pickActiveMenu([night], at("01:30"))?.id).toBe("n");
    expect(pickActiveMenu([night], at("12:00"))?.id).toBe("n"); // no match → first
  });

  it("falls back to the first menu when no window matches", () => {
    expect(pickActiveMenu([morning, lunch], at("20:00"))?.id).toBe("m");
  });

  it("returns undefined for an empty list", () => {
    expect(pickActiveMenu([], at("12:00"))).toBeUndefined();
  });
});

describe("pickActiveMenu — eligibility (#1765)", () => {
  it("never returns an ineligible menu, even when its window matches", () => {
    // The money case: a spot order at lunchtime where the lunch menu is
    // Takeaway. Picking it would snapshot 8% onto an eat-in line.
    const picked = pickActiveMenu(
      [morning, lunch],
      at("12:00"),
      (m) => m.id !== "l",
    );

    expect(picked?.id).toBe("m");
  });

  it("prefers the eligible menu over an ineligible first entry", () => {
    const picked = pickActiveMenu(
      [lunch, morning],
      at("20:00"),
      (m) => m.id !== "l",
    );

    expect(picked?.id).toBe("m");
  });

  // The cliff this fallback exists to avoid: on a feed that states no service
  // type, the predicate rejects everything. Honouring it would leave menuId
  // null and MenuCatalog's product grid locked — a tax guard turned outage.
  it("ignores a predicate that would exclude every menu", () => {
    const picked = pickActiveMenu([morning, lunch], at("12:00"), () => false);

    expect(picked?.id).toBe("l");
  });
});
