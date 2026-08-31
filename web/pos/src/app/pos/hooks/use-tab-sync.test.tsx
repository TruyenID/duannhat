/**
 * useTabSync — the tab strip's contract with the server's open-order feed.
 *
 * The pin is the load-bearing part: a workstation drops an order from that
 * feed the instant its closing payment commits (`order_paid`, a direct cache
 * write with no round-trip), which used to unmount the money dialog sitting
 * over that very order — killing the split-bill receipt handover and with it
 * any way to print the hoá đơn đỏ.
 */

import { describe, expect, it, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { useTabSync, type UseTabSyncArgs } from "./use-tab-sync";
import type { PosTab } from "./use-pos-tabs";
import type { CustomerOrder } from "../types";

const TAB: PosTab = { tabId: "tab-1", orderId: "order-1", label: "ORD-1" };

function order(id: string, code = "ORD-1"): CustomerOrder {
  return { id, order_code: code } as unknown as CustomerOrder;
}

function setup(over: Partial<UseTabSyncArgs> = {}) {
  const reconcileWithServer = vi.fn();
  const renameLabel = vi.fn();
  const args: UseTabSyncArgs = {
    openOrders: [order("order-1")],
    tabs: [TAB],
    activeTab: TAB,
    reconcileWithServer,
    renameLabel,
    moneyDialogOpen: false,
    paymentReceiptOrderId: null,
    splitBillReceiptOrderId: null,
    ...over,
  };
  const view = renderHook((props: UseTabSyncArgs) => useTabSync(props), {
    initialProps: args,
  });
  return { ...view, reconcileWithServer, renameLabel, args };
}

/** The keep-set handed to the most recent reconcile call. */
function lastKeepSet(fn: ReturnType<typeof vi.fn>): ReadonlySet<string> {
  const call = fn.mock.calls.at(-1);
  return call?.[1] as ReadonlySet<string>;
}

describe("useTabSync — reconciliation", () => {
  it("reconciles against the open-order ids with an empty keep-set when idle", () => {
    const { reconcileWithServer } = setup();

    expect(reconcileWithServer).toHaveBeenCalledTimes(1);
    expect(reconcileWithServer.mock.calls[0]![0]).toEqual(new Set(["order-1"]));
    expect(lastKeepSet(reconcileWithServer).size).toBe(0);
  });

  it("does not reconcile before the first open-order fetch lands", () => {
    const { reconcileWithServer } = setup({ openOrders: undefined });
    expect(reconcileWithServer).not.toHaveBeenCalled();
  });

  it("pins the active tab's order while a money dialog is open over it", () => {
    const { reconcileWithServer } = setup({ moneyDialogOpen: true });
    expect(lastKeepSet(reconcileWithServer)).toEqual(new Set(["order-1"]));
  });

  it("pins the order behind an open payment receipt", () => {
    // The tab may already be gone from `activeTab` by then — the receipt state
    // carries the order id precisely so the pin survives that.
    const { reconcileWithServer } = setup({
      activeTab: null,
      paymentReceiptOrderId: "order-9",
    });
    expect(lastKeepSet(reconcileWithServer)).toEqual(new Set(["order-9"]));
  });

  it("pins the order behind an open split-bill receipt", () => {
    const { reconcileWithServer } = setup({
      activeTab: null,
      splitBillReceiptOrderId: "order-9",
    });
    expect(lastKeepSet(reconcileWithServer)).toEqual(new Set(["order-9"]));
  });

  it("re-reconciles with an empty keep-set as soon as the dialog closes", () => {
    // Otherwise a finished order's tab would sit there until the next poll.
    const { rerender, reconcileWithServer, args } = setup({ moneyDialogOpen: true });
    expect(lastKeepSet(reconcileWithServer)).toEqual(new Set(["order-1"]));

    rerender({ ...args, moneyDialogOpen: false });

    expect(reconcileWithServer).toHaveBeenCalledTimes(2);
    expect(lastKeepSet(reconcileWithServer).size).toBe(0);
  });

  it("does not re-reconcile when nothing relevant changed", () => {
    const { rerender, reconcileWithServer, args } = setup();
    rerender({ ...args });
    expect(reconcileWithServer).toHaveBeenCalledTimes(1);
  });
});

describe("useTabSync — label refresh (plan-041)", () => {
  it("renames a tab whose label no longer matches the live order_code", () => {
    // A LAN order opens as WS-…; the workstation later adopts the Cloud code.
    const { renameLabel } = setup({
      tabs: [{ tabId: "tab-1", orderId: "order-1", label: "WS-0007" }],
      openOrders: [order("order-1", "ORD-2026-2843")],
    });

    expect(renameLabel).toHaveBeenCalledWith("tab-1", "ORD-2026-2843");
  });

  it("leaves a matching label alone (a same-content refetch is a no-op)", () => {
    const { renameLabel } = setup({
      tabs: [{ tabId: "tab-1", orderId: "order-1", label: "ORD-1" }],
      openOrders: [order("order-1", "ORD-1")],
    });

    expect(renameLabel).not.toHaveBeenCalled();
  });
});
