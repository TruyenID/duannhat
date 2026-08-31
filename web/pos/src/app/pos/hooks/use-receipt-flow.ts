import { useEffect, useRef, useState } from "react";
import { useTranslation } from "@/providers/app-provider";
import { isClosingOnHold } from "../lib/on-hold";
import type { PaymentSessionResult } from "../components/payment-dialog";
import type { PaymentReceiptEntry } from "../components/payment-receipt-dialog";
import type { SplitBillSessionResult } from "../components/split-bill-dialog";
import type { SplitBillReceiptData } from "../components/split-bill-receipt-dialog";
import type { PosTab } from "./use-pos-tabs";
import type { CustomerOrder } from "../types";

/**
 * Post-payment receipt screen — populated by PaymentDialog's
 * `onPaymentSuccess` callback. While the receipt dialog is open the
 * order tab stays alive; staff dismisses the receipt to finalize.
 */
export interface PaymentReceiptState {
  customer: { name?: string | null; phone?: string | null } | null;
  receipts: PaymentReceiptEntry[];
  totalPaid: number;
  tendered: number;
  paidAt: Date;
  /** Tab to close once staff dismisses the receipt. */
  tabIdToClose: string | null;
  /**
   * Snapshot of the just-paid order. The receipt renders outside the
   * `activeOrder` guard (and the tab may already be gone by then), so the
   * "Ghi nợ phần còn lại" + "Xuất hoá đơn đỏ" CTAs have to read the order
   * from here rather than from `activeOrder`.
   */
  orderId: string;
  customerId: string | null;
  /** What the CURRENT order still owes — excludes any old debts settled alongside. */
  remaining: number;
  /**
   * #2049 — đơn rơi vào trạng thái TREO (quán chưa nhận đủ tiền). Quyết định
   * màn nào được mở: `OnHoldReceiptDialog` (không có nút in) thay cho
   * `PaymentReceiptDialog`.
   *
   * Chốt NGAY TẠI ĐÂY, không đọc lại sau: đúng khoảnh khắc này client là bên
   * duy nhất biết sự thật. Payment vừa được ghi xong nên cờ `is_on_hold` của
   * server còn là verdict TRƯỚC phiên thu; đọc nó ở đây sẽ nói "không treo" cho
   * một đơn vừa mới thiếu tiền, và mở ra đúng màn hình có nút in.
   *
   * `remaining > 0` là bằng chứng client tự cầm, và nó không thể sai theo hướng
   * nguy hiểm: nó chỉ có thể nói "treo" cho một đơn thật sự còn thiếu tiền.
   */
  onHold: boolean;
  /** Tiền quán THỰC nhận cho đơn này trong phiên thu (không tính nợ cũ). */
  collected: number;
}

/**
 * Split-bill twin of {@link PaymentReceiptState} — shown when every guest in
 * a split-bill session has been collected. Has a selectable list of receipts
 * (for future workstation print integration). Tab close is deferred to
 * `completeSplitBillReceipt` just like the regular flow.
 */
export interface SplitBillReceiptState {
  data: SplitBillReceiptData;
  tabIdToClose: string | null;
  // Captured at settle time (activeOrder may be gone by the time the dialog
  // renders) — drive the per-guest receipt print + the order-level VAT invoice.
  orderId: string;
  customerId: string | null;
  customerName: string | null;
}

export interface UseReceiptFlowArgs {
  activeOrder: CustomerOrder | undefined;
  activeTab: PosTab | null;
  closeTab: (tabId: string) => void;
  /**
   * Called by `captureSplitBillReceipt` to dismiss SplitBillDialog manually,
   * because the receipt screen — not the dialog — owns the eventual closeTab.
   */
  closeSplitBillDialog: () => void;
}

export interface UseReceiptFlowResult {
  paymentReceipt: PaymentReceiptState | null;
  splitBillReceipt: SplitBillReceiptState | null;
  capturePaymentReceipt: (data: PaymentSessionResult) => void;
  completePaymentReceipt: () => void;
  captureSplitBillReceipt: (data: SplitBillSessionResult) => void;
  completeSplitBillReceipt: (selectedPaymentIds: string[]) => void;
  /**
   * True when a receipt screen is about to take over the tab close, and
   * clears the flag. See the ref comments below for why this is not state.
   */
  consumePendingPaymentReceipt: () => boolean;
  consumePendingSplitBillReceipt: () => boolean;
}

/**
 * The two post-payment receipt screens and the deferred tab close they own.
 *
 * Both flows share one shape: the dialog that collected the money hands over
 * a snapshot BEFORE closing itself, we stash it plus the tab id, and the tab
 * is only popped once staff dismisses the receipt — so the customer gets to
 * see what was charged.
 *
 * Deliberately NOT reset when the active tab changes. After a successful
 * payment the order's status flips to `closed`/`paying`, useOpenOrders no
 * longer returns it, and the reconcile effect drops the tab — which switches
 * activeTabId to OVERVIEW. Clearing on every tab change would make the
 * freshly-set receipt vanish before staff sees it.
 */
export function useReceiptFlow({
  activeOrder,
  activeTab,
  closeTab,
  closeSplitBillDialog,
}: UseReceiptFlowArgs): UseReceiptFlowResult {
  const { t } = useTranslation();

  const [paymentReceipt, setPaymentReceipt] = useState<PaymentReceiptState | null>(null);
  // Synchronous mirror of "a receipt is about to be shown" — set inside
  // capturePaymentReceipt in the SAME tick as `onOpenChange(false)` fires
  // on PaymentDialog. The dialog-close handler can't read `paymentReceipt`
  // state instead, because React batches the state update so the closure
  // sees the stale `null`. The ref updates synchronously and tells the
  // close handler to skip the closeTab.
  const paymentReceiptPendingRef = useRef(false);

  const [splitBillReceipt, setSplitBillReceipt] = useState<SplitBillReceiptState | null>(null);
  const splitBillReceiptPendingRef = useRef(false);

  /**
   * Last (order, tab) pair the cashier was actually collecting on.
   *
   * Both capture functions used to read `activeOrder` / `activeTab` directly
   * and `return` silently when either was missing — which turned a lost race
   * into "the receipt screen simply never appeared". The race is real and, on a
   * LAN workstation, was the normal case: the workstation broadcasts
   * `order_paid` as soon as the closing payment commits, `use-workstation-socket`
   * drops that order from the cached open list with no round-trip, and the
   * reconcile effect can therefore null out `activeTab` in the very same beat
   * the payment resolves. Page.tsx now pins the tab for the duration of the
   * flow so this should not trigger, but a capture is the LAST chance to show
   * the cashier what was charged — losing it silently is the worst possible
   * failure, so it falls back to the last known pair rather than giving up.
   *
   * Only ever holds a CONSISTENT pair — `activeOrder` is fetched by
   * `activeTab.orderId`, so a mismatch means a tab switch is mid-flight and
   * neither value should be recorded. That is what keeps a late capture from
   * being filed against whichever order the cashier moved on to.
   *
   * Recorded one commit behind (an effect, not a render write) and that is
   * exactly right: while the live pair is present the captures use it and
   * never look here, so the ref only ever has to answer "what was it just
   * before it went away".
   */
  const lastSessionRef = useRef<{ order: CustomerOrder; tab: PosTab } | null>(null);
  useEffect(() => {
    if (activeOrder && activeTab && activeTab.orderId === activeOrder.id) {
      lastSessionRef.current = { order: activeOrder, tab: activeTab };
    }
  }, [activeOrder, activeTab]);

  /** The (order, tab) a receipt being captured right now belongs to. */
  function receiptSession(): { order: CustomerOrder; tab: PosTab } | null {
    if (activeOrder && activeTab && activeTab.orderId === activeOrder.id) {
      return { order: activeOrder, tab: activeTab };
    }
    return lastSessionRef.current;
  }

  /**
   * PaymentDialog hands us a snapshot of every transaction it just posted
   * BEFORE it closes itself. We translate that into the receipt-screen
   * payload and stash the tab id we still need to pop — the tab close is
   * deferred until staff dismisses the receipt dialog (so the customer
   * can review what was charged).
   */
  function capturePaymentReceipt(data: PaymentSessionResult) {
    const session = receiptSession();
    if (!session) return;
    const { order, tab } = session;
    const currentCode = order.order_code;
    const currentLabel = t("pos.dialog.payment_receipt.entry_current");
    const currentDesc = t("pos.dialog.payment_receipt.entry_current_desc", {
      code: currentCode,
    });
    const debtLabel = t("pos.dialog.payment_receipt.entry_debt");
    const entries: PaymentReceiptEntry[] = data.entries.map((e, i) => ({
      index: i + 1,
      title: e.isDebt ? debtLabel : currentLabel,
      description: e.isDebt
        ? t("pos.dialog.payment_receipt.entry_debt_desc", { code: e.orderCode })
        : currentDesc,
      amount: e.amount,
    }));
    const fullName = order.customer
      ? [order.customer.first_name, order.customer.last_name]
          .filter((s): s is string => !!s && s.trim().length > 0)
          .join(" ")
          .trim() || null
      : null;
    // Flip the ref BEFORE setState so the dialog-close handler (which
    // fires synchronously right after this from PaymentDialog) sees the
    // pending receipt and skips the auto-close.
    // What THIS order still owes. `data.totalPaid` also covers old debts
    // settled in the same session, so only the non-debt rows count against
    // the active order's balance — otherwise settling a big old debt would
    // make the current order look overpaid and hide the "Ghi nợ phần còn
    // lại" CTA.
    const paidTowardsCurrent = data.entries
      .filter((e) => !e.isDebt)
      .reduce((sum, e) => sum + e.amount, 0);
    const currentRemaining = Math.max(
      0,
      Number(order.remaining_amount ?? order.total_amount ?? 0) -
        paidTowardsCurrent,
    );

    paymentReceiptPendingRef.current = true;
    setPaymentReceipt({
      customer: order.customer
        ? {
            name: fullName,
            phone: order.customer.phone ?? null,
          }
        : null,
      receipts: entries,
      totalPaid: data.totalPaid,
      tendered: data.tendered,
      paidAt: new Date(),
      tabIdToClose: tab.tabId,
      orderId: order.id,
      customerId: order.customer_id,
      remaining: currentRemaining,
      // #2049 — xem docblock của `onHold` trên interface về việc vì sao chốt ở
      // đây bằng `remaining` chứ không đọc cờ server.
      onHold: isClosingOnHold({
        remaining: currentRemaining,
        serverVerdict: order.is_on_hold ?? null,
      }),
      collected: paidTowardsCurrent,
    });
  }

  /**
   * Fired when staff hits "Hoàn tất" or "In biên lai" on the receipt
   * screen. Closes the deferred tab and clears the receipt state. The
   * print button is wired to this same handler for now (the workstation
   * print integration will swap it for a real RPC later).
   */
  function completePaymentReceipt() {
    const tabIdToClose = paymentReceipt?.tabIdToClose ?? null;
    // The flag's job ends with the receipt. Leaving it set would make the NEXT
    // dialog close in this page mount think a receipt was taking over the tab
    // close, and skip it. See `completeSplitBillReceipt` — that leak was real.
    paymentReceiptPendingRef.current = false;
    setPaymentReceipt(null);
    if (tabIdToClose) closeTab(tabIdToClose);
  }

  /**
   * SplitBillDialog hands us a snapshot of every guest's payment in the
   * same tick that the LAST row succeeds (and BEFORE the dialog
   * auto-closes via its own close handler). We capture the snapshot,
   * close SplitBillDialog manually, and open SplitBillReceiptDialog —
   * which owns the eventual `closeTab`.
   */
  function captureSplitBillReceipt(data: SplitBillSessionResult) {
    const session = receiptSession();
    if (!session) return;
    const { order, tab } = session;
    const tableLabel =
      (order.tables ?? []).map((tbl) => tbl.name ?? tbl.code).join(", ") || null;
    const receiptData: SplitBillReceiptData = {
      mode: data.mode,
      orderCode: order.order_code,
      tableLabel,
      guestCount: data.guestCount,
      totalAmount: data.totalAmount,
      perGuestAmount: data.perGuestAmount,
      paidAt: new Date(),
      guests: data.guests,
      remaining: 0,
    };
    const customerName = order.customer
      ? [order.customer.first_name, order.customer.last_name]
          .filter(Boolean)
          .join(" ")
          .trim() || null
      : null;
    splitBillReceiptPendingRef.current = true;
    setSplitBillReceipt({
      data: receiptData,
      tabIdToClose: tab.tabId,
      orderId: order.id,
      customerId: order.customer_id ?? null,
      customerName,
    });
    // Manually close the SplitBillDialog. This sets `open={false}` on a
    // CONTROLLED Radix dialog, which does NOT fire `onOpenChange` — so unlike
    // the PaymentDialog path, nothing consumes the flag here. It is cleared in
    // `completeSplitBillReceipt` instead; left set, the next split-bill dialog
    // the cashier closed by hand would skip its own tab close.
    closeSplitBillDialog();
  }

  /**
   * Fired when staff dismisses the SplitBillReceiptDialog (footer button
   * or X). Pops the tab if it still exists — reconcileWithServer may
   * have already dropped it once the order's status flipped.
   * `selectedPaymentIds` is captured for the future workstation print
   * integration; for now it's a no-op.
   */
  function completeSplitBillReceipt(_selectedPaymentIds: string[]) {
    const tabIdToClose = splitBillReceipt?.tabIdToClose ?? null;
    splitBillReceiptPendingRef.current = false;
    setSplitBillReceipt(null);
    if (tabIdToClose) closeTab(tabIdToClose);
  }

  function consumePendingPaymentReceipt(): boolean {
    if (!paymentReceiptPendingRef.current) return false;
    paymentReceiptPendingRef.current = false;
    return true;
  }

  function consumePendingSplitBillReceipt(): boolean {
    if (!splitBillReceiptPendingRef.current) return false;
    splitBillReceiptPendingRef.current = false;
    return true;
  }

  return {
    paymentReceipt,
    splitBillReceipt,
    capturePaymentReceipt,
    completePaymentReceipt,
    captureSplitBillReceipt,
    completeSplitBillReceipt,
    consumePendingPaymentReceipt,
    consumePendingSplitBillReceipt,
  };
}
