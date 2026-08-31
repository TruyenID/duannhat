import { beforeEach, describe, expect, it } from "vitest";
import { act, renderHook } from "@testing-library/react";
import { usePosTabs } from "./use-pos-tabs";

const STORAGE_KEY = "pos:tabs:v2";
const ACTIVE_KEY = "pos:tabs:active:v2";
const LEGACY_TABS_KEY = "pos:tabs:v1";
const LEGACY_ACTIVE_KEY = "pos:tabs:active:v1";

beforeEach(() => {
  localStorage.clear();
});

describe("usePosTabs — initial state (plan-007 tab state)", () => {
  it("starts empty when localStorage has nothing", () => {
    const { result } = renderHook(() => usePosTabs());
    expect(result.current.tabs).toEqual([]);
    expect(result.current.activeTabId).toBeNull();
    expect(result.current.activeTab).toBeNull();
  });
});

describe("usePosTabs — createTab", () => {
  it("adds one tab, activates it, and mirrors to localStorage v2", () => {
    const { result } = renderHook(() => usePosTabs());

    let tabId = "";
    act(() => {
      tabId = result.current.createTab("ord1", "Bàn A2");
    });

    expect(result.current.tabs).toHaveLength(1);
    expect(result.current.tabs[0]).toMatchObject({
      tabId,
      orderId: "ord1",
      label: "Bàn A2",
    });
    // The freshly-created tab becomes active.
    expect(result.current.activeTabId).toBe(tabId);
    expect(result.current.activeTab?.orderId).toBe("ord1");

    // localStorage mirror — only { tabId, orderId, label }, never the order body.
    const persisted = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "[]");
    expect(persisted).toEqual([{ tabId, orderId: "ord1", label: "Bàn A2" }]);
    expect(localStorage.getItem(ACTIVE_KEY)).toBe(tabId);
  });
});

describe("usePosTabs — closeTab", () => {
  it("removes the tab and defaults active to the last remaining tab", () => {
    const { result } = renderHook(() => usePosTabs());

    let a = "";
    let b = "";
    act(() => {
      a = result.current.createTab("ord1", "A");
    });
    act(() => {
      b = result.current.createTab("ord2", "B");
    });

    // b is active (last created). Close a — active must stay/become the last
    // remaining tab (b).
    act(() => {
      result.current.closeTab(a);
    });

    expect(result.current.tabs.map((t) => t.tabId)).toEqual([b]);
    expect(result.current.activeTabId).toBe(b);
  });

  it("clears active to null when the last tab is closed", () => {
    const { result } = renderHook(() => usePosTabs());
    let only = "";
    act(() => {
      only = result.current.createTab("ord1", "A");
    });
    act(() => {
      result.current.closeTab(only);
    });
    expect(result.current.tabs).toEqual([]);
    expect(result.current.activeTabId).toBeNull();
    expect(localStorage.getItem(ACTIVE_KEY)).toBeNull();
  });
});

describe("usePosTabs — v1 → v2 migration (no stale leak)", () => {
  it("wipes the pre-migration v1 keys on init and starts v2 empty", () => {
    // Old shape stored full CustomerOrder snapshots under v1.
    localStorage.setItem(
      LEGACY_TABS_KEY,
      JSON.stringify([{ id: "ord-old", status: "open", items: [{ x: 1 }] }]),
    );
    localStorage.setItem(LEGACY_ACTIVE_KEY, "ord-old");

    const { result } = renderHook(() => usePosTabs());

    // No crash, no stale state — v2 starts empty.
    expect(result.current.tabs).toEqual([]);
    expect(result.current.activeTabId).toBeNull();

    // v1 keys were removed as part of the one-shot migration.
    expect(localStorage.getItem(LEGACY_TABS_KEY)).toBeNull();
    expect(localStorage.getItem(LEGACY_ACTIVE_KEY)).toBeNull();
  });

  it("drops malformed rows missing required fields (defensive narrowing)", () => {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify([
        { tabId: "good", orderId: "ord1", label: "OK" },
        { label: "no ids" },
        null,
      ]),
    );
    const { result } = renderHook(() => usePosTabs());
    expect(result.current.tabs).toEqual([
      { tabId: "good", orderId: "ord1", label: "OK" },
    ]);
  });
});

describe("usePosTabs — reconcileWithServer", () => {
  it("drops tabs whose orderId is not in the server's open-order set", () => {
    const { result } = renderHook(() => usePosTabs());
    act(() => {
      result.current.createTab("ord1", "A");
    });
    act(() => {
      result.current.createTab("ord2", "B");
    });

    act(() => {
      result.current.reconcileWithServer(new Set(["ord2"]));
    });

    expect(result.current.tabs.map((t) => t.orderId)).toEqual(["ord2"]);
    // active falls back to the surviving tab
    expect(result.current.activeTab?.orderId).toBe("ord2");
  });

  it("keeps a tab listed in keepOrderIds even when the server dropped it", () => {
    // The cashier has a money dialog open over ord1 when the workstation's
    // `order_paid` takes it out of the open-order feed. Dropping the tab there
    // unmounts the dialog mid-flow — see the hook's docblock.
    const { result } = renderHook(() => usePosTabs());
    act(() => {
      result.current.createTab("ord1", "A");
    });

    act(() => {
      result.current.reconcileWithServer(new Set<string>(), new Set(["ord1"]));
    });

    expect(result.current.tabs.map((t) => t.orderId)).toEqual(["ord1"]);
    expect(result.current.activeTab?.orderId).toBe("ord1");
  });

  it("drops the kept tab on the next reconcile once it is no longer kept", () => {
    // The pin is only for the duration of the flow — it must not strand a tab
    // for an order the server has finished with.
    const { result } = renderHook(() => usePosTabs());
    act(() => {
      result.current.createTab("ord1", "A");
    });
    act(() => {
      result.current.reconcileWithServer(new Set<string>(), new Set(["ord1"]));
    });
    expect(result.current.tabs).toHaveLength(1);

    act(() => {
      result.current.reconcileWithServer(new Set<string>(), new Set<string>());
    });

    expect(result.current.tabs).toEqual([]);
    expect(result.current.activeTabId).toBeNull();
  });
});
