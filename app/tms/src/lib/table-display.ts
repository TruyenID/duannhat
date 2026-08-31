import type { Table, TableDisplayState } from "../types/tms";

/** How many minutes a table stays "recently paid" (light blue highlight). */
export const RECENTLY_PAID_MINUTES = 1;

/**
 * Map a table's raw backend data onto the visual state the floor dashboard
 * renders. Priority: call_staff > recently_paid > occupied > cleaning > free.
 *
 * `cleaning` is what the backend sets when an order is fully paid + closed
 * (OrderClosingService flips `tables.status` to `cleaning` so staff get a
 * bus-this-table signal). It MUST map to its own state — otherwise a just-paid
 * table falls through to `free` and shows as Trống instead of Đang dọn.
 *
 * `nowMs` is injectable so the recently-paid window is deterministic in tests.
 */
export function getDisplayState(
  table: Table,
  nowMs: number = Date.now(),
): TableDisplayState {
  // Priority 1: staff call active
  if (table.call_requested_at) return "call_staff";

  // Priority 2: recently paid (transient highlight within the window)
  if (table.paid_at) {
    const paidAt = new Date(table.paid_at).getTime();
    const cutoff = nowMs - RECENTLY_PAID_MINUTES * 60_000;
    if (paidAt > cutoff) return "recently_paid";
  }

  // Priority 3: occupied
  if (table.status === "occupied") return "occupied";

  // Priority 4: cleaning (fully-paid + closed → bus-this-table)
  if (table.status === "cleaning") return "cleaning";

  // Priority 5: reserved / out_of_service.
  //
  // These used to fall through to `free`, which left the floor terminal at odds
  // with every other part of the system. CustomerTableSessionService refuses a
  // QR scan on either status with 423 "Table is not available"; admin-web shows
  // them in their own colours; the shop dashboard counts `reserved` alongside
  // `occupied`; and the labels below already existed in all three locales,
  // unreachable. Only this screen — the one staff use to seat people — called
  // them empty. Staff seat a guest, the guest scans, the guest is refused.
  if (table.status === "reserved") return "reserved";
  if (table.status === "out_of_service") return "out_of_service";

  return "free";
}
