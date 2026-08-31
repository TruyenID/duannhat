/**
 * plan-051 (#1149 / #1150) — pure void-matrix + stock-timing logic.
 *
 * Extracted out of `../page.tsx` (2 200 lines of JSX) so the rules that decide
 * *which item statuses a shop may void in* can be tested without mounting the
 * whole settings screen. Behaviour is byte-identical to the inline version;
 * the page now imports from here.
 *
 * The invariants encoded below:
 *   • `pending` is a HARD FLOOR — always voidable, in every resolution branch,
 *     regardless of what the server sent (#1148 policy: edits are pending-only,
 *     voids beyond pending are the opt-in).
 *   • The server has three progressively older sources of truth and we read
 *     them in that order: resolved effective list → raw column → legacy
 *     boolean. A backend that does not serialize the two list fields yet must
 *     still produce a correct matrix.
 *   • Order is canonical (`VOID_MATRIX_STATUSES`), not whatever order the API
 *     happened to return — the checkbox column must not reshuffle per shop.
 */

// The four active item statuses of the void matrix, in display order.
export const VOID_MATRIX_STATUSES = ["pending", "preparing", "ready", "served"] as const;

export type VoidMatrixStatus = (typeof VOID_MATRIX_STATUSES)[number];

// #1150 — when a line's ingredients are deducted from stock.
export type StockDeductionTiming = "on_close" | "on_preparing" | "on_add";

export const STOCK_DEDUCTION_TIMINGS: StockDeductionTiming[] = [
  "on_close",
  "on_preparing",
  "on_add",
];

export const DEFAULT_STOCK_DEDUCTION_TIMING: StockDeductionTiming = "on_close";

/**
 * The subset of the order-settings payload this module reads. Structural, so
 * the full `OrderSettingsData` in `page.tsx` satisfies it without coupling the
 * two files.
 */
export interface VoidMatrixSettings {
  /** Legacy #1148 flag — true = void items in any status. */
  allow_item_edit_any_status: boolean;
  /** Raw column; `null` means "never configured, fall back to the flag". */
  item_voidable_statuses?: string[] | null;
  /** Server-resolved list (shop override merged over brand default). */
  effective_item_voidable_statuses?: string[];
}

/**
 * Client mirror of the backend's VoidableStatusResolver: server-resolved
 * effective list → raw column (non-null) → legacy allow_item_edit_any_status
 * fallback. Backend gap note: the settings endpoint may not serialize the two
 * list fields yet — the optional chain keeps this forward-compatible.
 */
export function resolveServerVoidableStatuses(settings: VoidMatrixSettings): VoidMatrixStatus[] {
  const configured = settings.effective_item_voidable_statuses ?? settings.item_voidable_statuses;
  const base: string[] = Array.isArray(configured)
    ? configured
    : settings.allow_item_edit_any_status
      ? [...VOID_MATRIX_STATUSES]
      : ["pending"];
  return VOID_MATRIX_STATUSES.filter((status) => status === "pending" || base.includes(status));
}

/** Order-insensitive set comparison — powers the "unsaved changes" flag. */
export function sameStatusList(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((status) => b.includes(status));
}

/**
 * Legacy #1148 mirror written alongside the matrix while the settings endpoint
 * / fleet may still read only the boolean: true ONLY for the lossless all-four
 * case; any narrower matrix maps to false (the strictest legacy interpretation
 * — pending-only — is the safe fallback).
 */
export function deriveLegacyItemEditFlag(statuses: readonly string[]): boolean {
  return statuses.length === VOID_MATRIX_STATUSES.length;
}
