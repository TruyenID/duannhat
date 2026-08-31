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

  // Default: free (covers free, reserved, out_of_service)
  return "free";
}
