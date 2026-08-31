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

  it("maps free → 'free'", () => {
    expect(getDisplayState(table({ status: "free" }), NOW)).toBe("free");
  });

  /**
   * This case previously asserted that `reserved` and `out_of_service` also
   * resolve to "free". That assertion was changed deliberately, so here is why,
   * in case someone later needs to weigh reverting it.
   *
   * It carried no rationale, unlike the `cleaning` case directly above it, which
   * explains at length why falling through to "free" would be wrong. Meanwhile
   * four independent parts of the system treat both statuses as unavailable:
   * CustomerTableSessionService throws 423 "Table is not available" on a QR
   * scan; admin-web gives each its own colour in the status menu;
   * ShopDashboardService counts `reserved` with `occupied`; and
   * table_status.reserved / table_status.blocked already existed in all three
   * locale files, unreachable.
   *
   * The floor terminal was the only surface calling those tables empty — and it
   * is the surface staff use to seat people, so the disagreement ended with a
   * guest seated at a table whose QR then refused them.
   */
  it("shows a reserved table as reserved, not free", () => {
    expect(getDisplayState(table({ status: "reserved" }), NOW)).toBe("reserved");
  });

  it("shows an out-of-service table as out_of_service, not free", () => {
    expect(getDisplayState(table({ status: "out_of_service" }), NOW)).toBe("out_of_service");
  });

  it("still lets an active staff call outrank reserved", () => {
    // Priority order is unchanged. Someone at the table pressing the bell is a
    // person waiting right now, which outranks a booking.
    expect(
      getDisplayState(
        table({ status: "reserved", call_requested_at: "2026-07-30T10:00:00Z" }),
        NOW,
      ),
    ).toBe("call_staff");
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
