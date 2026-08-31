import { useEffect, useMemo, useRef } from "react";
import type { PosTab } from "./use-pos-tabs";
import type { CustomerOrder } from "../types";

export interface UseTabSyncArgs {
  /** `openOrdersQuery.data?.data` — undefined until the first fetch lands. */
  openOrders: CustomerOrder[] | undefined;
  tabs: PosTab[];
  activeTab: PosTab | null;
  reconcileWithServer: (
    openOrderIds: Set<string>,
    keepOrderIds?: ReadonlySet<string> | null,
  ) => void;
  renameLabel: (tabId: string, label: string) => void;
  /** True while PaymentDialog or SplitBillDialog is open over the active tab. */
  moneyDialogOpen: boolean;
  /** Order id behind an open PaymentReceiptDialog, if any. */
  paymentReceiptOrderId: string | null;
  /** Order id behind an open SplitBillReceiptDialog, if any. */
  splitBillReceiptOrderId: string | null;
}

/**
 * Keeps the local tab strip in step with the server's open-order feed: drops
 * tabs the server has finished with, and refreshes labels the server has
 * renamed. Both are driven off the same feed, so they live together.
 *
 * ## Why tabs are pinned while money is being taken
 *
 * An order leaving the open-order feed normally means "done, close the tab".
 * But the feed can drop an order while the cashier is still mid-flow on it:
 * the workstation broadcasts `order_paid` the instant the closing payment
 * commits, and `use-workstation-socket` answers by filtering that order
 * straight out of the cached open list — a direct cache write with no
 * round-trip, so it routinely reaches the browser BEFORE the POST that caused
 * it has resolved.
 *
 * Reconciling on that signal tore the flow down: the tab went, `activeOrder`
 * went undefined, and every dialog behind page.tsx's `activeOrder &&` guard
 * unmounted. Split-bill died one beat from the finish line — the final row's
 * success was written to an unmounted component, the `onAllRowsPaid` handover
 * that opens SplitBillReceiptDialog never ran, and the cashier landed on the
 * tables overview with no receipt screen and no way to print the hoá đơn đỏ
 * for an order that had just been paid in full.
 *
 * So a tab is pinned while a money dialog or a receipt screen is open over it.
 * Nothing leaks: those flows close their own tab (`handleSplitBillClose`,
 * `handlePaymentDialogClose`, and the two `complete*Receipt` handlers), and
 * the moment the pin lifts this effect re-runs and reconciles what is left.
 */
export function useTabSync({
  openOrders,
  tabs,
  activeTab,
  reconcileWithServer,
  renameLabel,
  moneyDialogOpen,
  paymentReceiptOrderId,
  splitBillReceiptOrderId,
}: UseTabSyncArgs): void {
  // `activeTab.orderId`, not the active order's id: the order query can be
  // between fetches at exactly the moment we most need its tab kept.
  const activeOrderId = activeTab?.orderId ?? null;
  const keepOrderIds = useMemo(() => {
    const ids = new Set<string>();
    if (moneyDialogOpen && activeOrderId) ids.add(activeOrderId);
    if (paymentReceiptOrderId) ids.add(paymentReceiptOrderId);
    if (splitBillReceiptOrderId) ids.add(splitBillReceiptOrderId);
    return ids;
  }, [moneyDialogOpen, activeOrderId, paymentReceiptOrderId, splitBillReceiptOrderId]);

  useEffect(() => {
    if (!openOrders) return;
    reconcileWithServer(new Set(openOrders.map((o) => o.id)), keepOrderIds);
  }, [openOrders, reconcileWithServer, keepOrderIds]);

  // plan-041 — a LAN order opens with a provisional code (WS-...) which the
  // workstation later swaps for the Cloud ORD-#### via the order.code_assigned
  // socket event. The tab label is captured at createTab time and would stay
  // stale; refresh any tab whose label no longer matches its live order_code.
  //
  // `tabs` is read through a ref (not an effect dep) on purpose: renameLabel
  // mutates tab state, so depending on `tabs` here would re-run the effect and
  // — combined with reconcileWithServer always producing a fresh array — spin
  // into an infinite "Maximum update depth exceeded" loop. Keyed on the orders
  // data only; renameLabel is a stable useCallback. Idempotent: only mutates
  // when a label actually differs, so a same-content refetch is a no-op.
  // Declared BEFORE the effect that reads it: effects within one hook run in
  // declaration order, so the ref is already current when the label pass below
  // runs in the same commit.
  const tabsRef = useRef(tabs);
  useEffect(() => {
    tabsRef.current = tabs;
  });
  useEffect(() => {
    if (!openOrders) return;
    const codeById = new Map(openOrders.map((o) => [o.id, o.order_code]));
    for (const tab of tabsRef.current) {
      const liveCode = codeById.get(tab.orderId);
      if (liveCode && liveCode !== tab.label) {
        renameLabel(tab.tabId, liveCode);
      }
    }
  }, [openOrders, renameLabel]);
}
