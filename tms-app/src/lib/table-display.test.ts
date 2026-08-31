import { describe, it, expect } from "vitest";
import { getDisplayState, RECENTLY_PAID_MINUTES } from "./table-display";
import type { Table } from "../types/tms";

const NOW = 1_700_000_000_000;

function table(overrides: Partial<Table> = {}): Table {
  return {
    id: "t1",
    name: "B-01",
    status: "free",
    call_requested_at: null,
    paid_at: null,
    ...overrides,
  } as Table;
}

describe("getDisplayState", () => {
  it("maps a cleaning table to 'cleaning' (Đang dọn) — not 'free'", () => {
    // The bug: a fully-paid+closed table (status=cleaning) fell through to
    // 'free' and showed as Trống instead of Đang dọn.
    expect(getDisplayState(table({ status: "cleaning" }), NOW)).toBe("cleaning");
  });

  it("maps occupied → 'occupied'", () => {
    expect(getDisplayState(table({ status: "occupied" }), NOW)).toBe("occupied");
  });

  it("maps free → 'free' (and reserved/out_of_service default to free)", () => {
    expect(getDisplayState(table({ status: "free" }), NOW)).toBe("free");
    expect(getDisplayState(table({ status: "reserved" }), NOW)).toBe("free");
    expect(getDisplayState(table({ status: "out_of_service" }), NOW)).toBe("free");
  });

  it("call_staff outranks everything", () => {
    expect(
      getDisplayState(
        table({ status: "cleaning", call_requested_at: new Date(NOW).toISOString() }),
        NOW,
      ),
    ).toBe("call_staff");
  });

  it("recently_paid (within window) outranks cleaning, then cleaning shows after the window", () => {
    const justPaid = new Date(NOW - 10_000).toISOString(); // 10s ago
    expect(getDisplayState(table({ status: "cleaning", paid_at: justPaid }), NOW)).toBe(
      "recently_paid",
    );

    const stalePaid = new Date(NOW - (RECENTLY_PAID_MINUTES * 60_000 + 5_000)).toISOString();
    expect(getDisplayState(table({ status: "cleaning", paid_at: stalePaid }), NOW)).toBe(
      "cleaning",
    );
  });
});
