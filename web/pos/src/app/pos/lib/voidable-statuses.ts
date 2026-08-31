/**
 * resolveVoidableStatuses — plan-051 (#1149) client mirror of the backend's
 * VoidableStatusResolver. One function, same semantics at every tier:
 *
 *   1. `effective_item_voidable_statuses` (server-resolved) when the settings
 *      payload carries it.
 *   2. `item_voidable_statuses` (raw column) when non-null.
 *   3. Legacy flag fallback: `allow_item_edit_any_status` true → all four
 *      active statuses, false/absent → pending-only.
 *
 * `pending` is ALWAYS in the result (hard floor — a pending line is never
 * un-voidable), and unknown strings are dropped so a newer backend can add
 * statuses without breaking the cart's includes() checks.
 *
 * NOTE (backend gap at P4 time): the shop settings endpoint does not yet
 * serialize tiers 1–2 — resolution lands on tier 3 until the settings
 * serializer ships. The optional fields make this forward-compatible.
 */

import type { OrderItemStatus } from "../types";

export const ACTIVE_ITEM_STATUSES = [
  "pending",
  "preparing",
  "ready",
  "served",
] as const;

export type VoidableStatus = (typeof ACTIVE_ITEM_STATUSES)[number];

export interface VoidableStatusSettings {
  effective_item_voidable_statuses?: string[] | null;
  item_voidable_statuses?: string[] | null;
  allow_item_edit_any_status?: boolean;
}

function sanitize(list: string[]): VoidableStatus[] {
  const known = ACTIVE_ITEM_STATUSES.filter(
    (status) => status === "pending" || list.includes(status),
  );
  return [...known];
}

export function resolveVoidableStatuses(
  settings: VoidableStatusSettings | undefined,
): VoidableStatus[] {
  if (!settings) return ["pending"];

  const configured =
    settings.effective_item_voidable_statuses ??
    settings.item_voidable_statuses;

  if (Array.isArray(configured)) {
    return sanitize(configured);
  }

  return settings.allow_item_edit_any_status
    ? [...ACTIVE_ITEM_STATUSES]
    : ["pending"];
}

/** Convenience gate used by the cart: can a line in `status` be voided? */
export function isStatusVoidable(
  status: OrderItemStatus,
  voidableStatuses: readonly string[],
): boolean {
  return status !== "voided" && voidableStatuses.includes(status);
}
