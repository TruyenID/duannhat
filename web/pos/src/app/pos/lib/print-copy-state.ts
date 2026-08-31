/**
 * Print-copy state (#1875) — pure, so the rule can be tested without a printer,
 * a workstation, or a dialog.
 *
 * The question the POS has to answer before printing a money document is not
 * "has this order been printed" but "has THIS PAYER been printed for THIS KIND":
 * on a split bill every guest's first sheet is an original, and only that
 * guest's second one carries 「BAN IN #N」. The counter is per kind AND per
 * scope — printing a receipt must not make that customer's first hoá đơn đỏ come
 * out stamped as a copy.
 *
 * It drives the `In gốc` / `In lại` pair (#2535 A7). A single "In" button would
 * have to GUESS which of the two it is, and it would guess wrong exactly on the
 * class of orders #2535 created: paid, but never printed, so their first sheet
 * is a genuine original.
 */

import type { PrintKindCounts } from "@/services/workstation-print-service";

export interface PrintCopyState {
  /** True only when we KNOW paper exists for this scope. */
  alreadyPrinted: boolean;
  /** Copies already issued for this scope. */
  printedCount: number;
  /** What the sheet about to print will be numbered. */
  nextCopyNo: number;
  /**
   * True when the workstation did not report counts at all — an older build, or
   * the LAN probe failed. The UI must stay SILENT here rather than claim
   * "chưa in": a confident wrong answer is worse than no answer, because it is
   * the one a cashier acts on. The `In gốc` / `In lại` pair collapses to one
   * neutral `In` in this row for the same reason.
   */
  unknown: boolean;
}

/**
 * "Không biết" — dùng cho CẢ hai nguồn của sự không biết: workstation không trả
 * tally, và workstation không nói lượt in không-nhắm-ai rơi vào phạm vi nào.
 * Vế thứ hai cũng là không biết thật sự: đọc bừa `order_scope` cho ra 0 vĩnh
 * viễn trên đơn một người trả (`resolvePrintScope` branch ②).
 */
export const UNKNOWN_PRINT_COPY_STATE: PrintCopyState = {
  alreadyPrinted: false,
  printedCount: 0,
  nextCopyNo: 1,
  unknown: true,
};

const UNKNOWN = UNKNOWN_PRINT_COPY_STATE;

/**
 * Reads the tally for the scope this surface is about to print.
 *
 * `paymentId` names one split payer; without it the scope is the whole-order
 * slip — a separate document with its own counter, which is why it reads
 * `order_scope` and never the sum of the payers.
 */
export function printCopyState(
  counts: PrintKindCounts | undefined,
  paymentId?: string | null,
): PrintCopyState {
  if (!counts) return UNKNOWN;

  const scope = paymentId
    ? counts.by_payment?.find((row) => row.payment_id === paymentId)
    : counts.order_scope;

  // A payer with no row has genuinely never been printed — that is a known
  // zero, not an unknown, so the surface stays quiet and prints a clean first
  // sheet. Only a missing `counts` block is unknown.
  const printedCount = scope?.count ?? 0;

  return {
    alreadyPrinted: printedCount > 0,
    printedCount,
    nextCopyNo: printedCount + 1,
    unknown: false,
  };
}
