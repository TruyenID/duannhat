import { useState } from "react";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import type { PrintResultTarget } from "../components/print-result-dialog";
import { formatCurrency } from "../lib/totals";

export interface PrintResultState {
  target: PrintResultTarget;
  detail: string;
}

export interface UsePrintResultResult {
  /** Non-null while the print-offer dialog should be on screen. */
  printResult: PrintResultState | null;
  /**
   * A debt payment settles the order, so the tab close is already handled by
   * the payment dialog's own close path (it refetches and pops the tab once
   * the order lands in `closed`). What's left is showing the result — with a
   * print action the cashier can decline.
   */
  showDebtCharged: (paymentId: string, amount: number, orderId: string) => void;
  dismiss: () => void;
}

/**
 * Result screen for the two flows that create something printable (a debt
 * slip, a VAT invoice). Neither prints on its own any more — the write has
 * already succeeded and the cashier gets an explicit print action they are
 * free to decline.
 */
export function usePrintResult(): UsePrintResultResult {
  const { t } = useTranslation();
  const [printResult, setPrintResult] = useState<PrintResultState | null>(null);

  function showDebtCharged(paymentId: string, amount: number, orderId: string) {
    toast.success(t("pos.toast.debt_charged"));
    setPrintResult({
      target: { kind: "debt", orderId, paymentId, amount },
      detail: formatCurrency(amount),
    });
  }

  function dismiss() {
    setPrintResult(null);
  }

  return { printResult, showDebtCharged, dismiss };
}
