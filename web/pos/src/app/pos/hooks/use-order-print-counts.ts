/**
 * Print tallies for one order, all kinds, from ONE probe (#1875 · #2535 A7).
 *
 * Answers "which of these guests already has paper, and of WHICH document", so
 * the cashier sees it BEFORE choosing `In gốc` or `In lại` rather than after the
 * second sheet is in the customer's hand.
 *
 * One hook, not one per document. `GET /api/lan/print/status?order_id=` returns
 * `receipt` · `red_invoice` · `debt_slip` in a single response, so a per-kind
 * hook would mean N round-trips to the workstation for one screen — and N
 * answers that can disagree, because each lands at a different instant.
 *
 * Read once when the surface opens and again after a print — deliberately NOT
 * polled. A 30s poll re-renders the page underneath an open dialog and has
 * already wiped a cashier's input once in this codebase; and nothing else can
 * change these numbers while this POS is the thing printing.
 */

import { useCallback, useEffect, useState } from "react";

import {
  workstationPrintService,
  type PrintKindCounts,
} from "@/services/workstation-print-service";

export interface OrderPrintCounts {
  /** Undefined means "not known" — an older workstation, or the probe failed.
   *  `printCopyState` turns that into `unknown`, which the UI must not guess at. */
  receipt: PrintKindCounts | undefined;
  redInvoice: PrintKindCounts | undefined;
  debtSlip: PrintKindCounts | undefined;
  /**
   * #2535 A7 — payment id an UNTARGETED print counts against, as resolved BY
   * THE WORKSTATION. `null` means the whole-order scope; `undefined` means the
   * workstation did not say, which must read as unknown rather than as "order
   * scope" (see `untargeted_scope` in the service).
   */
  untargetedScopePaymentId: string | null | undefined;
}

const NOTHING_KNOWN: OrderPrintCounts = {
  receipt: undefined,
  redInvoice: undefined,
  debtSlip: undefined,
  untargetedScopePaymentId: undefined,
};

export interface UseOrderPrintCounts {
  counts: OrderPrintCounts;
  /** Re-read after a print so the tally reflects the sheet that just came out. */
  refresh: () => void;
}

export function useOrderPrintCounts(
  orderId: string | undefined,
  enabled: boolean,
): UseOrderPrintCounts {
  const [counts, setCounts] = useState<OrderPrintCounts>(NOTHING_KNOWN);
  const [tick, setTick] = useState(0);

  const refresh = useCallback(() => setTick((n) => n + 1), []);

  useEffect(() => {
    if (!enabled || !orderId || !workstationPrintService.enabled) return;
    let cancelled = false;
    workstationPrintService
      .getPrintStatus({ orderId })
      .then((status) => {
        if (cancelled) return;
        setCounts({
          receipt: status.receipt,
          redInvoice: status.red_invoice,
          debtSlip: status.debt_slip,
          untargetedScopePaymentId: status.untargeted_scope
            ? status.untargeted_scope.payment_id
            : undefined,
        });
      })
      // Leaving every kind undefined reads as UNKNOWN downstream, and the pair
      // collapses to one neutral button. Never a toast: a failed status probe is
      // not a problem the cashier can act on, and it must not look like one.
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [orderId, enabled, tick]);

  return { counts, refresh };
}
