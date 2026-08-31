/**
 * usePosTabs — POS tab metadata persisted to localStorage.
 *
 * Plan-007 Phase 5: the tab state is now server-authoritative. localStorage
 * holds only `{ tabId, orderId, label }` — the order body is always fetched
 * via `useOrder(orderId)`. The previous v1 shape stored full CustomerOrder
 * snapshots; v2 drops them. On mount we wipe v1 to avoid stale reads.
 */

import { useCallback, useEffect, useState } from "react";

export interface PosTab {
  tabId: string;
  orderId: string;
  label: string;
}

export interface UsePosTabsResult {
  tabs: PosTab[];
  activeTabId: string | null;
  activeTab: PosTab | null;
  createTab: (orderId: string, label: string) => string;
  closeTab: (tabId: string) => void;
  setActiveTabId: (tabId: string | null) => void;
  renameLabel: (tabId: string, label: string) => void;
  reorder: (tabs: PosTab[]) => void;
  /**
   * Drop tabs whose orderId is not in the provided allowlist (server's open
   * orders).
   *
   * `keepOrderIds` survives reconciliation regardless. It exists because the
   * open-order feed can drop an order WHILE the cashier is still mid-flow on
   * it: the workstation broadcasts `order_paid` the moment the closing payment
   * commits, and `use-workstation-socket` filters that order out of the cached
   * open list synchronously — no round-trip, so it routinely lands BEFORE the
   * POST that caused it has even resolved in the browser. Reconciling on that
   * signal unmounted the payment / split-bill dialog out from under the
   * cashier: the last split row's success was written to an unmounted
   * component, the completion handover never fired, and the POS jumped to the
   * tables overview with no receipt screen — no way to print the hoá đơn đỏ.
   * See `page.tsx` for what it pins.
   */
  reconcileWithServer: (
    openOrderIds: Set<string>,
    keepOrderIds?: ReadonlySet<string> | null,
  ) => void;
}

const STORAGE_KEY = "pos:tabs:v2";
const ACTIVE_KEY = "pos:tabs:active:v2";
const LEGACY_TABS_KEY = "pos:tabs:v1";
const LEGACY_ACTIVE_KEY = "pos:tabs:active:v1";

interface PersistedState {
  tabs: PosTab[];
  activeTabId: string | null;
}

function load(): PersistedState {
  if (typeof window === "undefined") return { tabs: [], activeTabId: null };
  try {
    // One-shot migration: wipe v1 (full CustomerOrder snapshots) so its
    // stale types don't leak into v2 expectations.
    if (localStorage.getItem(LEGACY_TABS_KEY) !== null) {
      localStorage.removeItem(LEGACY_TABS_KEY);
      localStorage.removeItem(LEGACY_ACTIVE_KEY);
    }

    const raw = localStorage.getItem(STORAGE_KEY);
    const active = localStorage.getItem(ACTIVE_KEY);
    const tabs = raw ? (JSON.parse(raw) as PosTab[]) : [];
    // Defensive narrowing — drop any rows missing required fields.
    const safe = tabs.filter(
      (t): t is PosTab =>
        !!t && typeof t.tabId === "string" && typeof t.orderId === "string",
    );
    return { tabs: safe, activeTabId: active };
  } catch {
    return { tabs: [], activeTabId: null };
  }
}

function save(tabs: PosTab[], activeTabId: string | null): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(tabs));
    if (activeTabId) localStorage.setItem(ACTIVE_KEY, activeTabId);
    else localStorage.removeItem(ACTIVE_KEY);
  } catch {
    // quota / private mode — ignore
  }
}

export function usePosTabs(): UsePosTabsResult {
  const [state, setState] = useState<PersistedState>(() => load());

  useEffect(() => {
    save(state.tabs, state.activeTabId);
  }, [state]);

  const activeTab =
    state.tabs.find((t) => t.tabId === state.activeTabId) ?? null;

  const createTab = useCallback((orderId: string, label: string): string => {
    const tabId = `tab-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    setState((prev) => ({
      tabs: [...prev.tabs, { tabId, orderId, label }],
      activeTabId: tabId,
    }));
    return tabId;
  }, []);

  const closeTab = useCallback((tabId: string) => {
    setState((prev) => {
      const tabs = prev.tabs.filter((t) => t.tabId !== tabId);
      const activeTabId =
        prev.activeTabId === tabId
          ? (tabs[tabs.length - 1]?.tabId ?? null)
          : prev.activeTabId;
      return { tabs, activeTabId };
    });
  }, []);

  const setActiveTabId = useCallback((tabId: string | null) => {
    setState((prev) => ({ ...prev, activeTabId: tabId }));
  }, []);

  const renameLabel = useCallback((tabId: string, label: string) => {
    setState((prev) => ({
      ...prev,
      tabs: prev.tabs.map((t) => (t.tabId === tabId ? { ...t, label } : t)),
    }));
  }, []);

  const reorder = useCallback((tabs: PosTab[]) => {
    setState((prev) => ({ ...prev, tabs }));
  }, []);

  const reconcileWithServer = useCallback(
    (openOrderIds: Set<string>, keepOrderIds?: ReadonlySet<string> | null) => {
      setState((prev) => {
        const tabs = prev.tabs.filter(
          (t) => openOrderIds.has(t.orderId) || !!keepOrderIds?.has(t.orderId),
        );
        const wasOrderTab = prev.tabs.some((t) => t.tabId === prev.activeTabId);
        const stillOpen = tabs.some((t) => t.tabId === prev.activeTabId);
        // Keep the active tab when it survived reconciliation, OR when it's a
        // pinned tab (overview / takeaway) that never lived in `tabs`. Only a
        // dropped ORDER tab falls back to another open tab — a pinned tab must
        // not get bumped to an order tab just because its id isn't in `tabs`.
        const activeTabId =
          prev.activeTabId && (stillOpen || !wasOrderTab)
            ? prev.activeTabId
            : (tabs[0]?.tabId ?? null);
        return { tabs, activeTabId };
      });
    },
    [],
  );

  return {
    tabs: state.tabs,
    activeTabId: state.activeTabId,
    activeTab,
    createTab,
    closeTab,
    setActiveTabId,
    renameLabel,
    reorder,
    reconcileWithServer,
  };
}
