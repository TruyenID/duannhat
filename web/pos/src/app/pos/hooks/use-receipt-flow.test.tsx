/**
 * useReceiptFlow — the two capture functions, and the one thing they must
 * never do: drop a receipt on the floor.
 *
 * Both used to read `activeOrder` / `activeTab` straight off the current
 * render and `return` when either was missing. That is a silent failure at the
 * worst possible moment — the money is already taken, and the cashier is left
 * with no screen, no receipt, and no way to print the hoá đơn đỏ. It fires for
 * real: a workstation's `order_paid` broadcast can null the tab out in the
 * same beat the closing payment resolves. page.tsx now pins the tab for the
 * duration of the flow, and this fallback is the second line of defence.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { useReceiptFlow, type UseReceiptFlowArgs } from "./use-receipt-flow";
import type { PosTab } from "./use-pos-tabs";
import type { CustomerOrder } from "../types";

beforeEach(() => {
  localStorage.setItem("pos_locale", "en");
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const TAB: PosTab = { tabId: "tab-1", orderId: "order-1", label: "ORD-1" };

const ORDER = {
  id: "order-1",
  order_code: "ORD-T-0001",
  status: "checkout",
  total_amount: 150_000,
  remaining_amount: "150000",
  paid_amount: 0,
  customer: null,
  customer_id: null,
  tables: [{ id: "tb-1", name: "B-08", code: "B08" }],
  items: [],
} as unknown as CustomerOrder;

const SESSION = {
  mode: "by_items" as const,
  guests: [
    {
      index: 1,
      paymentId: "pay-a",
      methodName: "Cash",
      methodCode: "cash",
      amount: 100_000,
      paidAt: "17:42",
    },
    {
      index: 2,
      paymentId: "pay-b",
      methodName: "Cash",
      methodCode: "cash",
      amount: 50_000,
      paidAt: "17:44",
    },
  ],
  guestCount: 2,
  perGuestAmount: 100_000,
  totalAmount: 150_000,
};

function setup(initial: Partial<UseReceiptFlowArgs> = {}) {
  const closeTab = vi.fn();
  const closeSplitBillDialog = vi.fn();
  const view = renderHook(
    (props: { activeOrder: CustomerOrder | undefined; activeTab: PosTab | null }) =>
      useReceiptFlow({
        activeOrder: props.activeOrder,
        activeTab: props.activeTab,
        closeTab,
        closeSplitBillDialog,
      }),
    {
      wrapper: Wrapper,
      initialProps: {
        // `in` rather than `??` — an explicit `undefined` here is the "no
        // order has ever been active" case, not a request for the default.
        activeOrder: ("activeOrder" in initial ? initial.activeOrder : ORDER) as
          | CustomerOrder
          | undefined,
        activeTab: ("activeTab" in initial ? initial.activeTab : TAB) as PosTab | null,
      },
    },
  );
  return { ...view, closeTab, closeSplitBillDialog };
}

describe("useReceiptFlow — captureSplitBillReceipt", () => {
  it("captures the receipt from the live order + tab", () => {
    const { result, closeSplitBillDialog } = setup();

    act(() => result.current.captureSplitBillReceipt(SESSION));

    expect(result.current.splitBillReceipt).toMatchObject({
      orderId: "order-1",
      tabIdToClose: "tab-1",
      data: { orderCode: "ORD-T-0001", tableLabel: "B-08", totalAmount: 150_000 },
    });
    // The receipt screen — not the split dialog — owns the eventual tab close.
    expect(closeSplitBillDialog).toHaveBeenCalledTimes(1);
    expect(result.current.consumePendingSplitBillReceipt()).toBe(true);
  });

  it("still captures when the tab was yanked before the handover landed", () => {
    const { result, rerender } = setup();

    // `order_paid` → reconcileWithServer drops the tab → both go away.
    rerender({ activeOrder: undefined, activeTab: null });
    act(() => result.current.captureSplitBillReceipt(SESSION));

    expect(result.current.splitBillReceipt).toMatchObject({
      orderId: "order-1",
      tabIdToClose: "tab-1",
      data: { orderCode: "ORD-T-0001", totalAmount: 150_000 },
    });
  });

  it("attributes a late capture to the order it was collecting on, not a newer one", () => {
    const { result, rerender } = setup();
    const otherOrder = { ...ORDER, id: "order-2", order_code: "ORD-T-0002" } as CustomerOrder;
    const otherTab: PosTab = { tabId: "tab-2", orderId: "order-2", label: "ORD-2" };

    // Cashier moves on to another table; that pair becomes the last known one.
    rerender({ activeOrder: otherOrder, activeTab: otherTab });
    rerender({ activeOrder: undefined, activeTab: null });
    act(() => result.current.captureSplitBillReceipt(SESSION));

    expect(result.current.splitBillReceipt?.orderId).toBe("order-2");
    expect(result.current.splitBillReceipt?.tabIdToClose).toBe("tab-2");
  });

  it("gives up rather than guess when no order has ever been active", () => {
    const { result, closeSplitBillDialog } = setup({
      activeOrder: undefined,
      activeTab: null,
    });

    act(() => result.current.captureSplitBillReceipt(SESSION));

    expect(result.current.splitBillReceipt).toBeNull();
    expect(closeSplitBillDialog).not.toHaveBeenCalled();
  });

  it("closes the deferred tab only when the receipt is dismissed", () => {
    const { result, closeTab } = setup();

    act(() => result.current.captureSplitBillReceipt(SESSION));
    expect(closeTab).not.toHaveBeenCalled();

    act(() => result.current.completeSplitBillReceipt([]));

    expect(closeTab).toHaveBeenCalledWith("tab-1");
    expect(result.current.splitBillReceipt).toBeNull();
  });
});

describe("useReceiptFlow — capturePaymentReceipt", () => {
  const PAYMENT = {
    entries: [{ amount: 150_000, isDebt: false, orderCode: "ORD-T-0001" }],
    totalPaid: 150_000,
    tendered: 200_000,
  };

  it("captures from the live order + tab", () => {
    const { result } = setup();

    act(() => result.current.capturePaymentReceipt(PAYMENT as never));

    expect(result.current.paymentReceipt).toMatchObject({
      orderId: "order-1",
      tabIdToClose: "tab-1",
      totalPaid: 150_000,
      remaining: 0,
    });
  });

  it("still captures when the tab was yanked before the handover landed", () => {
    const { result, rerender } = setup();

    rerender({ activeOrder: undefined, activeTab: null });
    act(() => result.current.capturePaymentReceipt(PAYMENT as never));

    expect(result.current.paymentReceipt).toMatchObject({
      orderId: "order-1",
      tabIdToClose: "tab-1",
      totalPaid: 150_000,
    });
  });
});
