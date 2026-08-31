/**
 * "Đã in ×N" — how many hoá đơn đỏ this scope already has (#1875).
 *
 * One component so the receipt screen and the split-bill screen cannot drift
 * into saying it two different ways about the same piece of paper.
 *
 * Renders NOTHING when the count is zero or unknown. Silence is the honest
 * output for "this workstation is older than #1875 and cannot tell me": a
 * confident "chưa in" from a missing field is what puts a second original in a
 * customer's hand, and it is the one a cashier would act on.
 */

import { ReceiptTextIcon } from "lucide-react";

import { useTranslation } from "@/providers/app-provider";
import type { PrintKindCounts } from "@/services/workstation-print-service";
import { printCopyState } from "../lib/print-copy-state";

export interface RedInvoicePrintedBadgeProps {
  counts: PrintKindCounts | undefined;
  /** Names one split payer; omit for the whole-order slip's own tally. */
  paymentId?: string | null;
}

export function RedInvoicePrintedBadge({
  counts,
  paymentId,
}: RedInvoicePrintedBadgeProps) {
  const { t } = useTranslation();
  const state = printCopyState(counts, paymentId);

  if (!state.alreadyPrinted) return null;

  return (
    <span
      data-slot="red-invoice-printed-badge"
      className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/5 dark:text-amber-200"
    >
      <ReceiptTextIcon className="size-3" />
      {t("pos.red_invoice.printed_count", {
        count: String(state.printedCount),
      })}
    </span>
  );
}
