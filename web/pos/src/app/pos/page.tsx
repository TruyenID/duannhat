import { useEffect, useMemo, useRef, useState } from "react";
import { useParams } from "react-router-dom";
import { MobileCartDock } from "./components/mobile-cart-dock";
import { PosHeader } from "./components/pos-header";
import { PosTabBarConnected, OVERVIEW_TAB_ID, TAKEAWAY_TAB_ID } from "./components/pos-tab-bar";
import { TablesOverview } from "./components/tables-overview";
import { TakeawayOrdersView } from "./components/takeaway-orders-view";
import { TableHistoryView } from "./components/table-history-view";
import { MenuCatalog } from "./components/menu-catalog";
import { DebtSearchDialog } from "./components/debt-search-dialog";
import { PrintResultDialog } from "./components/print-result-dialog";
import { OrderCart } from "./components/order-cart";
import { EditItemDialog } from "./components/edit-item-dialog";
import { CreateOrderDialog } from "./components/create-order-dialog";
import { AssignTableDialog } from "./components/assign-table-dialog";
import { GuestCountDialog } from "./components/guest-count-dialog";
import { ChangeTableDialog } from "./components/change-table-dialog";
import { MergeTableDialog } from "./components/merge-table-dialog";
import { UnmergeTableDialog } from "./components/unmerge-table-dialog";
import { VoidItemDialog } from "./components/void-item-dialog";
import { StackingConflictDialog } from "./components/stacking-conflict-dialog";
import { CloseTabConfirmDialog } from "./components/close-tab-confirm-dialog";
import { PaymentDialog } from "./components/payment-dialog";
import { ClosingReceipt } from "./components/closing-receipt";
import { SplitBillReceiptDialog } from "./components/split-bill-receipt-dialog";
import { SplitBillDialog } from "./components/split-bill-dialog";
import type {
  CustomerOrder,
} from "./types";
import { decideCloseTab } from "./lib/close-tab-policy";
import { usePosTabs } from "./hooks/use-pos-tabs";
import { useCartItemActions } from "./hooks/use-cart-item-actions";
import { useOrderLifecycle } from "./hooks/use-order-lifecycle";
import { useEditOrderItem } from "./hooks/use-edit-order-item";
import { usePaymentActions } from "./hooks/use-payment-actions";
import { usePrintResult } from "./hooks/use-print-result";
import { useTableAssignment } from "./hooks/use-table-assignment";
import { useReceiptFlow } from "./hooks/use-receipt-flow";
import { useTabSync } from "./hooks/use-tab-sync";
import { useDebtSettlement } from "./hooks/use-debt-settlement";
import { useShop } from "@/hooks/api/use-shop";
import {
  useAddItems,
  useReleaseCoupon,
  useCreateOrder,
  useOpenOrders,
  useTakeawayOrders,
  useOrder,
  useSplitBill,
  useUpdateItem,
  useVoidItem,
} from "@/hooks/api/use-orders";
import { useCustomerOutstanding } from "@/hooks/api/use-customer-outstanding";
import { usePaymentMethods } from "@/hooks/api/use-payment-methods";
import {
  checkoutCapableOptions,
  effectiveOptionToPaymentMethod,
} from "./lib/effective-payment-options";
import { useShopOrderSettings } from "@/hooks/api/use-shop-order-settings";
import { useVoidReasons } from "@/hooks/api/use-void-reasons";
import { resolveVoidableStatuses } from "./lib/voidable-statuses";
import { useShopMenuProducts } from "@/hooks/api/use-shop-menus";
import { useTables, useUpdateTableStatus } from "@/hooks/api/use-tables";
import { getApiErrorMessage } from "@/lib/api-error";
import { useLocale, useTranslation } from "@/providers/app-provider";
import { useQueryClient } from "@tanstack/react-query";
import { orderKeys } from "@/hooks/api/query-keys";
import { setActiveCurrency } from "./lib/totals";
import { useWorkstation } from "@/providers/workstation-provider";
import { useLiveChannel } from "./hooks/use-live-channel";

// localStorage key for the menu picker — scoped per shop. Factored into a
// function so the shopSlug read from URL params can produce a fresh key
// when staff navigates between shops without reloading.
const selectedMenuKey = (shopSlug: string) => `pos:menu:v1:${shopSlug}`;

export function PosPage() {
  const { t } = useTranslation();
  const qc = useQueryClient();

  // shopSlug comes from the URL path (/:shopSlug). The route only matches
  // when the segment is present, so useParams always returns it here —
  // empty-string fallback is defensive and will cascade into `enabled:
  // !!shopSlug` guards on every downstream query.
  const params = useParams<{ shopSlug: string }>();
  const SHOP_SLUG = params.shopSlug ?? "";
  const SELECTED_MENU_KEY = selectedMenuKey(SHOP_SLUG);
  const settleDebt = useDebtSettlement(SHOP_SLUG);

  // The backend localizes order-item names by Accept-Language (same as the
  // menu). The order detail cache is keyed without locale (it's driven by
  // mutation setQueryData), so a language switch on an already-open order
  // wouldn't refetch on its own. Invalidate the order queries when the
  // operator changes language so open orders re-localize in place — matching
  // the menu. Skip the initial mount so first load doesn't double-fetch.
  const { locale } = useLocale();
  const didMountLocaleRef = useRef(false);
  useEffect(() => {
    if (!didMountLocaleRef.current) {
      didMountLocaleRef.current = true;
      return;
    }
    if (!SHOP_SLUG) return;
    qc.invalidateQueries({ queryKey: orderKeys.all(SHOP_SLUG) });
  }, [locale, qc, SHOP_SLUG]);

  // apiFetch's shop context is published by <ShopScope> at the route level,
  // above AuthGuard/ShiftGate. It cannot live here: ShiftGate renders `null`
  // while its own /pos/till/current query is in flight, so this component
  // isn't mounted yet when the first request goes out.

  const shopQuery = useShop(SHOP_SLUG);
  const shopName = shopQuery.data?.data.name ?? SHOP_SLUG;

  const { mode, workstationReachable } = useWorkstation();

  // Opens the workstation socket and answers "must the WS-driven lists poll?"
  // (#541, #1792). Must run BEFORE those queries — see use-live-channel.ts.
  const { listRefetchInterval } = useLiveChannel(SHOP_SLUG, workstationReachable, mode);

  const tablesQuery = useTables(SHOP_SLUG, {}, { refetchInterval: listRefetchInterval });
  const tables = tablesQuery.data?.data ?? [];

  const effectiveOptionsQuery = usePaymentMethods(SHOP_SLUG);
  const effectiveOptionsEnvelope = effectiveOptionsQuery.data?.data;
  const effectiveOptions = effectiveOptionsEnvelope?.options ?? [];
  const policyRevision = effectiveOptionsEnvelope?.revision ?? 0;
  const checkoutPaymentMethods = useMemo(
    () =>
      checkoutCapableOptions(effectiveOptions).map(effectiveOptionToPaymentMethod),
    [effectiveOptions],
  );

  // Shop-level UX toggles (BR-SOS04). `enable_quick_order` = true replaces
  // the create-order dialog with a one-tap button that posts a floating order.
  // The cached value drives passive UI (nothing for now); the real
  // decision happens inside handleOpenCreate which forces a fresh DB read
  // on every click so flipping the toggle in admin takes effect without
  // a POS reload.
  const shopOrderSettingsQuery = useShopOrderSettings(SHOP_SLUG);

  // plan-051 (#1149) — reason picker list for the void-item dialog. An
  // unreachable list (LAN without a mirror) just means the dialog falls
  // back to the legacy free-text input, so errors are swallowed here.
  const voidReasonsQuery = useVoidReasons(SHOP_SLUG);

  // plan-051 — per-status void matrix. Server-resolved effective list when
  // the settings payload carries it; legacy allow_item_edit_any_status
  // fallback otherwise. `pending` is always voidable.
  const voidableStatuses = useMemo(
    () => resolveVoidableStatuses(shopOrderSettingsQuery.data?.data),
    [shopOrderSettingsQuery.data],
  );

  // BR-SOS06 — push the shop's configured display currency into the
  // module-level `formatCurrency` resolver. `useShopOrderSettings` polls
  // every 60s, so flipping the currency in admin-web takes effect on POS
  // within a minute at worst, immediately on tab refocus at best.
  const shopCurrency = shopOrderSettingsQuery.data?.data.currency_code;
  useEffect(() => {
    setActiveCurrency(shopCurrency);
  }, [shopCurrency]);

  // The order feeds are WS-driven too, so they take the same gate (#1792).
  const openOrdersQuery = useOpenOrders(SHOP_SLUG, {
    refetchInterval: listRefetchInterval,
  });
  // Takeaway orders have their own feed (order_type=takeaway) so a busy dine-in
  // floor can't crowd them out of the shared 100-order open page.
  const takeawayOrdersQuery = useTakeawayOrders(SHOP_SLUG, {
    refetchInterval: listRefetchInterval,
  });

  const {
    tabs,
    activeTabId,
    activeTab,
    createTab,
    closeTab,
    setActiveTabId,
    reconcileWithServer,
    renameLabel,
  } = usePosTabs();

  // Active tab order hydration
  const orderQuery = useOrder(SHOP_SLUG, activeTab?.orderId ?? null);
  const activeOrder: CustomerOrder | undefined = orderQuery.data?.data ?? undefined;

  // Takeaway orders — dedicated feed (order_type=takeaway). They carry no
  // table so they never surface on the tables grid; the overview "Đơn
  // takeaway" button + drawer are the way in.
  const takeawayOrders = takeawayOrdersQuery.data?.data ?? [];

  // Open (or switch to) an order's tab by id. Shared by the overview grid
  // (serving-table tap) and the takeaway drawer so both behave identically.
  // Looks in BOTH feeds because a takeaway order opened from the drawer may not
  // be present in the (dine-in-dominated) open-orders page.
  function handleOpenOrderById(orderId: string) {
    const existing = tabs.find((tb) => tb.orderId === orderId);
    if (existing) {
      setActiveTabId(existing.tabId);
      return;
    }
    const order =
      (openOrdersQuery.data?.data ?? []).find((o) => o.id === orderId) ??
      takeawayOrders.find((o) => o.id === orderId);
    if (order) createTab(order.id, order.order_code);
  }

  // Menu selection persists across sessions
  const [selectedMenuId, setSelectedMenuId] = useState<string | null>(() =>
    typeof window === "undefined" ? null : localStorage.getItem(SELECTED_MENU_KEY)
  );
  useEffect(() => {
    if (selectedMenuId) localStorage.setItem(SELECTED_MENU_KEY, selectedMenuId);
    else localStorage.removeItem(SELECTED_MENU_KEY);
  }, [selectedMenuId, SELECTED_MENU_KEY]);

  // Plan 016 — page-level access to menu products so we can resolve the
  // matching ShopMenuProduct (variant SKUs + topping_groups) for any
  // cart line being edited. MenuCatalog reads the same hook with the
  // same key, so TanStack Query dedupes the request.
  const menuProductsQuery = useShopMenuProducts(SHOP_SLUG, selectedMenuId, {
    // Keep this key identical to MenuCatalog's unfiltered query so edit mode
    // subscribes to the product data already visible on screen.
    per_page: 100,
  });

  // Dialog state
  const [createOpen, setCreateOpen] = useState(false);
  // When staff taps a specific free table on the overview, this holds its
  // id until CreateOrderDialog mounts + reads it. Cleared when the dialog
  // closes (success or cancel) so subsequent "+" clicks open empty.
  const [presetTableIdForCreate, setPresetTableIdForCreate] = useState<string | null>(null);
  const [assignTableOpen, setAssignTableOpen] = useState(false);
  const [guestCountOpen, setGuestCountOpen] = useState(false);
  const [changeTableOpen, setChangeTableOpen] = useState(false);
  const [mergeTableOpen, setMergeTableOpen] = useState(false);
  const [unmergeTableOpen, setUnmergeTableOpen] = useState(false);
  const [debtSearchOpen, setDebtSearchOpen] = useState(false);
  // Per-table history — opened from a table card's "…" menu. When set, the
  // full-page TableHistoryView takes over the main content area.
  const [historyTable, setHistoryTable] = useState<{ id: string; name: string } | null>(null);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [splitBillOpen, setSplitBillOpen] = useState(false);
  const [splitCount, setSplitCount] = useState(2);
  // The two post-payment receipt screens + the deferred tab close they own.
  // Both dialogs hand over a snapshot before closing themselves; the tab is
  // popped only once staff dismisses the receipt. See the hook for the
  // synchronous-ref trick the close handlers below depend on.
  const receipts = useReceiptFlow({
    activeOrder,
    activeTab,
    closeTab,
    closeSplitBillDialog: () => setSplitBillOpen(false),
  });
  const [closeTabTarget, setCloseTabTarget] = useState<{ tabId: string; label: string } | null>(
    null
  );

  // Tab strip ⇄ server open-order feed: drop finished tabs, refresh renamed
  // labels — and PIN the tab a money dialog / receipt screen is sitting on, so
  // the workstation's `order_paid` broadcast can't unmount the flow one beat
  // before it hands over. Full reasoning in the hook.
  useTabSync({
    openOrders: openOrdersQuery.data?.data,
    tabs,
    activeTab,
    reconcileWithServer,
    renameLabel,
    moneyDialogOpen: paymentOpen || splitBillOpen,
    paymentReceiptOrderId: receipts.paymentReceipt?.orderId ?? null,
    splitBillReceiptOrderId: receipts.splitBillReceipt?.orderId ?? null,
  });

  // Inline error banner (cleared on next successful mutation or manual dismiss)
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const surfaceError = (e: unknown) => {
    // Always surface *something* — a swallowed error on a till reads as
    // success. getApiErrorMessage guarantees a non-empty string for every
    // input, falling back to the generic "unknown error" label.
    setErrorMessage(getApiErrorMessage(e, t("common.error_unknown")));
  };

  // Which tables (+ guest count) the order is bound to, and the failed-move
  // banner that flow can produce.
  const tableAssignment = useTableAssignment({
    shopSlug: SHOP_SLUG,
    activeOrder,
    tables,
    setErrorMessage,
    surfaceError,
  });
  const { clearPendingUnmerge } = tableAssignment;

  // Mutations
  const createOrder = useCreateOrder(SHOP_SLUG);

  // Order-scoped mutations against the active order. Item hooks now take
  // itemId as a mutation variable, so one hook instance per order covers
  // every item in the cart.
  const activeOrderId = activeOrder?.id ?? "";
  const addItems = useAddItems(SHOP_SLUG, activeOrderId);
  const updateTableStatus = useUpdateTableStatus(SHOP_SLUG);
  const updateItem = useUpdateItem(SHOP_SLUG, activeOrderId);
  const voidItem = useVoidItem(SHOP_SLUG, activeOrderId);
  const releaseCoupon = useReleaseCoupon(SHOP_SLUG, activeOrderId);

  // Posting money + the cache invalidation that has to follow it. No UI
  // state here: tab lifecycle and receipt screens live with their dialogs.
  const payments = usePaymentActions({ shopSlug: SHOP_SLUG, activeOrder });

  // Offer-to-print screen for the two writes that produce paper (debt slip,
  // VAT invoice). The write already succeeded; the cashier may decline.
  const printResult = usePrintResult();

  // Split-bill calculator — only fetches while the dialog is open AND the
  // order is in checkout/paying. `enabled` gates the request so we don't
  // hammer the endpoint on every render.
  const splitBillEnabled =
    splitBillOpen &&
    !!activeOrder &&
    (activeOrder.status === "checkout" || activeOrder.status === "paying");
  const splitBillQuery = useSplitBill(
    SHOP_SLUG,
    activeOrder?.id ?? null,
    splitCount,
    splitBillEnabled
  );

  // Outstanding debt for the active order's customer — excludes the
  // active order itself (which is being paid right now).
  const outstandingQuery = useCustomerOutstanding(SHOP_SLUG, activeOrder?.customer_id ?? null);
  const outstanding = useMemo(
    () => (outstandingQuery.data?.data ?? []).filter((o) => o.id !== activeOrder?.id),
    [outstandingQuery.data, activeOrder]
  );

  // Lookup fn for PosTabBar chip summaries
  const getOrderFromCache = useMemo(
    () => (orderId: string) => {
      const cached = qc.getQueryData<{ data: CustomerOrder }>(orderKeys.detail(SHOP_SLUG, orderId));
      return cached?.data;
    },
    [qc, SHOP_SLUG]
  );

  // ------------------------------------------------------------------
  //  Handlers
  // ------------------------------------------------------------------

  async function handleCreateConfirm(body: Parameters<typeof createOrder.mutateAsync>[0]) {
    setErrorMessage(null);
    // Let the error propagate so CreateOrderDialog keeps itself open and
    // shows the server's validation message inline. The mutation hook still
    // fires a toast via its onError handler.
    const response = await createOrder.mutateAsync(body);
    const o = response.data;
    // Tab label is always the immutable order_code so it never shifts when
    // tables get assigned/changed/merged later in the order's lifecycle.
    createTab(o.id, o.order_code);
  }

  // BR-SOS04: quick mode short-circuits the dialog and posts an empty body.
  // Errors surface in the inline banner (no dialog to host them).
  //
  // On every click we force-refetch the shop order settings so the
  // quick-order decision reflects the CURRENT DB value — a manager flipping
  // the toggle in admin is reflected immediately without a POS reload. If
  // the refetch fails (network), fall back to the cached value so the POS
  // stays usable; if there's no cached value either, default to the dialog
  // flow (safer — no accidental empty orders).
  async function handleOpenCreate(presetTableId?: string) {
    setErrorMessage(null);
    setPresetTableIdForCreate(presetTableId ?? null);
    let quickEnabled = false;
    try {
      const fresh = await shopOrderSettingsQuery.refetch();
      quickEnabled = fresh.data?.data.enable_quick_order ?? false;
    } catch {
      quickEnabled = shopOrderSettingsQuery.data?.data.enable_quick_order ?? false;
    }
    if (!quickEnabled) {
      setCreateOpen(true);
      return;
    }
    // Quick-order path skips the dialog → still attach the preset table
    // when staff tapped one on the overview, so the created floating
    // order is bound to that table.
    try {
      await handleCreateConfirm(presetTableId ? { table_ids: [presetTableId] } : {});
    } catch (e) {
      surfaceError(e);
    } finally {
      setPresetTableIdForCreate(null);
    }
  }

  /**
   * ✕ trên tab = dọn màn hình, KHÔNG đụng tới đơn: không gọi API, đơn ở lại
   * `open`. Chỉ hỏi lại khi đóng xong thì không còn lối mở lại đơn — lý do đầy
   * đủ ở `lib/close-tab-policy.ts`.
   */
  function handleCloseTab(tabId: string) {
    const tab = tabs.find((t) => t.tabId === tabId);
    if (!tab) return;

    if (decideCloseTab(getOrderFromCache(tab.orderId)) === "warn_unreachable") {
      setCloseTabTarget({ tabId, label: tab.label });
      return;
    }

    closeTab(tabId);
  }

  // Adding / changing / voiding LINES in the cart.
  const cartItems = useCartItemActions({
    activeOrder,
    addItems,
    updateItem,
    voidItem,
    releaseCoupon,
    setErrorMessage,
    surfaceError,
  });
  const { clearVoidItemTarget } = cartItems;

  // Moving the ORDER itself through its lifecycle (checkout / accept / void)
  // plus the coupon attached to it.
  const orderLifecycle = useOrderLifecycle({
    shopSlug: SHOP_SLUG,
    activeOrder,
    activeTab,
    closeTab,
    releaseCoupon,
    setErrorMessage,
    surfaceError,
  });

  // Dialog state is global (not per-tab), so switching tabs while a dialog
  // was open would leave the modal hanging on top of a different order's
  // context — staff sees a "ghost" SplitBill / Payment / etc. modal pop
  // up after creating a new order or switching tabs. Reset all dialog
  // flags whenever the active tab changes so each tab starts clean.
  // `voidItemTarget` and `pendingUnmerge` likewise reference the active
  // order's items/tables and would mismatch on switch.
  useEffect(() => {
    setCreateOpen(false);
    setAssignTableOpen(false);
    setGuestCountOpen(false);
    setChangeTableOpen(false);
    setMergeTableOpen(false);
    setUnmergeTableOpen(false);
    setPaymentOpen(false);
    setSplitBillOpen(false);
    clearVoidItemTarget();
    clearPendingUnmerge();
    setErrorMessage(null);
    // NOTE: the receipt screens (useReceiptFlow) are intentionally NOT reset
    // here — they have their own lifecycle and MUST survive the tab going
    // away. The full reasoning lives in the hook's docblock.
  }, [activeTabId, clearPendingUnmerge, clearVoidItemTarget]);

  // Editing an existing cart line — resolving which ShopMenuProduct it came
  // from, then writing the change back (a SKU swap is add+void, not update).
  const editItem = useEditOrderItem({
    shopSlug: SHOP_SLUG,
    activeOrder,
    menuProductsQuery,
    addItems,
    updateItem,
    voidItem,
    setErrorMessage,
    surfaceError,
  });


  function handleOpenSplitBill() {
    if (!activeOrder) return;
    setErrorMessage(null);
    // Seed split count from guest_count so staff usually doesn't have to
    // touch the picker — fall back to 2 if the field is null/0/1.
    const seed = activeOrder.guest_count ?? 2;
    setSplitCount(seed >= 2 ? seed : 2);
    setSplitBillOpen(true);
  }

  /**
   * C4: drop split-bill state and reopen the regular PaymentDialog so
   * the order is collected as a single payment instead. The dialog
   * itself clears its localStorage snapshot before invoking this.
   */
  function handleCancelSplitBill() {
    setSplitBillOpen(false);
    setPaymentOpen(true);
  }

  /**
   * Closes the SplitBillDialog. Unlike the regular PaymentDialog, split-bill
   * MUST keep the tab around while the order is only partially paid — staff
   * is still collecting from the remaining customers in sequence and needs
   * to reopen the dialog to continue. We only pop the tab when the order is
   * fully settled (`remaining_amount` reached 0), regardless of whether the
   * backend ended in `closed` (cash auto-confirm) or `paying` (card/transfer
   * awaiting confirmation) — both mean staff is done with this order.
   */
  async function handleSplitBillClose(nowOpen: boolean) {
    setSplitBillOpen(nowOpen);
    if (nowOpen) return;
    // Receipt screen owns the tab close in the all-paid flow (same
    // synchronous-ref pattern as PaymentDialog → PaymentReceiptDialog).
    if (receipts.consumePendingSplitBillReceipt()) return;
    if (!activeTab || !activeOrder) return;
    const refetched = await orderQuery.refetch();
    const data = refetched.data?.data;
    if (!data) return;
    const remaining = Number(data.remaining_amount ?? 0);
    if (data.status === "closed" || remaining <= 0) {
      closeTab(activeTab.tabId);
    }
  }

  /**
   * Runs after PaymentDialog finishes (success or user dismiss). If the
   * active order has moved into a post-payment lifecycle (`closed` = paid
   * in full, or `paying` = partial paid), pop the tab — UNLESS the receipt
   * dialog is about to be shown (or already is), in which case the tab
   * close is deferred to the receipt screen. Staff bailout
   * (status still `checkout`) keeps the tab as before.
   */
  async function handlePaymentDialogClose(nowOpen: boolean) {
    setPaymentOpen(nowOpen);
    if (nowOpen) return;
    // Receipt screen owns the tab close in the success flow. useReceiptFlow
    // flips its flag synchronously in the same render tick as
    // `onOpenChange(false)`, so the check is reliable (unlike reading the
    // receipt state, which would still be the stale `null`).
    if (receipts.consumePendingPaymentReceipt()) return;
    if (!activeTab || !activeOrder) return;
    const refetched = await orderQuery.refetch();
    const status = refetched.data?.data.status;
    if (status === "closed" || status === "paying") {
      closeTab(activeTab.tabId);
    }
  }

  // ------------------------------------------------------------------
  //  Render
  // ------------------------------------------------------------------

  const singleTable = activeOrder?.tables?.length === 1 ? activeOrder.tables[0] : null;

  // Mobile cart Sheet — see MobileCartDock for the layout it drives.
  const [cartSheetOpen, setCartSheetOpen] = useState(false);
  // Auto-close the cart sheet when staff switches tabs so they don't see
  // a previous tab's cart context after switching.
  useEffect(() => {
    setCartSheetOpen(false);
  }, [activeTabId]);

  const activeItemCount = activeOrder?.items?.filter((i) => i.status !== "voided").length ?? 0;

  const orderCartElement = (
    <OrderCart
      order={activeOrder}
      isLoading={orderQuery.isLoading}
      errorMessage={errorMessage}
      onDismissError={() => setErrorMessage(null)}
      pendingUnmerge={tableAssignment.pendingUnmerge}
      onRetryUnmerge={tableAssignment.retryUnmerge}
      onDismissPendingUnmerge={clearPendingUnmerge}
      onAddItem={() => {
        /* unused — add goes through MenuCatalog */
      }}
      onChangeQty={cartItems.changeQuantity}
      onUpdateItemStatus={cartItems.updateItemStatus}
      onVoidItem={cartItems.openVoidItemDialog}
      onEditItemToppings={(itemId) => {
        void editItem.openEditItem(itemId);
      }}
      onCheckout={orderLifecycle.checkout}
      onAttemptCheckout={orderLifecycle.canCheckout}
      onApplyCoupon={orderLifecycle.applyCoupon}
      onReleaseCoupon={orderLifecycle.releaseCoupon}
      onPay={() => setPaymentOpen(true)}
      onSplitBill={handleOpenSplitBill}
      onVoid={orderLifecycle.voidOrder}
      onReopen={orderLifecycle.reopenOrder}
      onAcceptOrder={() => {
        void orderLifecycle.acceptOrder();
      }}
      acceptPending={orderLifecycle.acceptPending}
      onAssignTable={() => setAssignTableOpen(true)}
      onEditGuestCount={() => setGuestCountOpen(true)}
      onChangeTable={() => setChangeTableOpen(true)}
      onMergeTable={() => setMergeTableOpen(true)}
      onUnmergeTable={() => setUnmergeTableOpen(true)}
      serviceChargeRate={Number(shopOrderSettingsQuery.data?.data.service_charge_rate ?? 0)}
      pricesIncludeTax={shopOrderSettingsQuery.data?.data?.prices_include_tax ?? false}
      voidableStatuses={voidableStatuses}
      currencyCode={shopOrderSettingsQuery.data?.data?.currency_code}
    />
  );

  return (
    <div className="bg-background flex h-screen flex-col overflow-hidden">
      <PosHeader
        shopName={shopName}
        onDebtLookup={() => setDebtSearchOpen(true)}
      />

      <PosTabBarConnected
        tabs={tabs}
        activeTabId={activeTabId}
        getOrder={getOrderFromCache}
        onSelect={setActiveTabId}
        onClose={handleCloseTab}
        onCreate={handleOpenCreate}
        takeawayCount={takeawayOrders.length}
      />

      {historyTable ? (
        <TableHistoryView
          shopSlug={SHOP_SLUG}
          table={historyTable}
          onClose={() => setHistoryTable(null)}
        />
      ) : activeTabId === OVERVIEW_TAB_ID || activeTabId === null ? (
        <TablesOverview
          tables={tables}
          orders={openOrdersQuery.data?.data ?? []}
          onOpenOrder={handleOpenOrderById}
          onCreateOrder={handleOpenCreate}
          onChangeStatus={(tableId, status) =>
            updateTableStatus.mutate({ tableId, status })
          }
          takeawayCount={takeawayOrders.length}
          onOpenTakeaway={() => setActiveTabId(TAKEAWAY_TAB_ID)}
          onViewHistory={(tableId) => {
            const tb = tables.find((t) => t.id === tableId);
            setHistoryTable({ id: tableId, name: tb?.name ?? tb?.code ?? "" });
          }}
        />
      ) : activeTabId === TAKEAWAY_TAB_ID ? (
        <TakeawayOrdersView
          orders={takeawayOrders}
          onOpenOrder={handleOpenOrderById}
          currencyCode={shopOrderSettingsQuery.data?.data?.currency_code}
        />
      ) : (
        <div className="relative flex min-h-0 flex-1 flex-col overflow-hidden lg:grid lg:grid-cols-[minmax(0,1fr)_360px] xl:grid-cols-[minmax(0,1fr)_400px]">
          <section className="flex min-h-0 min-w-0 flex-col overflow-hidden">
            <MenuCatalog
              shopSlug={SHOP_SLUG}
              menuId={selectedMenuId}
              onSelectMenuId={setSelectedMenuId}
              // `confirmed` (counter-pay takeaway from customer-web) accepts
              // add-item too — Cloud addItems + workstation AddItems both
              // allow it, so staff can extend the cart at the counter.
              disabled={
                !activeOrder ||
                (activeOrder.status !== "open" &&
                  activeOrder.status !== "confirmed")
              }
              orderType={activeOrder?.order_type}
              onAddItem={cartItems.addItem}
              pricesIncludeTax={shopOrderSettingsQuery.data?.data?.prices_include_tax ?? false}
              currencyCode={shopOrderSettingsQuery.data?.data?.currency_code}
            />
          </section>

          {/* Sidebar cart — visible only at lg+ */}
          <div className="hidden min-h-0 lg:flex lg:flex-col lg:overflow-hidden">
            {orderCartElement}
          </div>

          <MobileCartDock
            open={cartSheetOpen}
            onOpenChange={setCartSheetOpen}
            itemCount={activeItemCount}
          >
            {orderCartElement}
          </MobileCartDock>
        </div>
      )}

      {/* Dialogs --------------------------------------------------- */}

      {editItem.editingLine && (
        <EditItemDialog
          line={editItem.editingLine}
          onSubmit={editItem.submitEditItem}
          onClose={editItem.closeEditItem}
        />
      )}

      <CreateOrderDialog
        open={createOpen}
        onOpenChange={(next) => {
          setCreateOpen(next);
          // Clear preset on close (success or cancel) so a subsequent
          // "+" tap from the tab bar opens an empty dialog.
          if (!next) setPresetTableIdForCreate(null);
        }}
        shopSlug={SHOP_SLUG}
        tables={tables}
        defaultTableIds={presetTableIdForCreate ? [presetTableIdForCreate] : undefined}
        onConfirm={handleCreateConfirm}
      />

      {activeOrder && (
        <>
          <AssignTableDialog
            open={assignTableOpen}
            onOpenChange={setAssignTableOpen}
            tables={tables}
            onConfirm={tableAssignment.assignTables}
          />

          <GuestCountDialog
            open={guestCountOpen}
            onOpenChange={setGuestCountOpen}
            value={activeOrder.guest_count}
            onConfirm={tableAssignment.setGuestCount}
          />

          {singleTable && (
            <ChangeTableDialog
              open={changeTableOpen}
              onOpenChange={setChangeTableOpen}
              fromTable={singleTable}
              tables={tables}
              onConfirm={tableAssignment.changeTable}
            />
          )}

          <MergeTableDialog
            open={mergeTableOpen}
            onOpenChange={setMergeTableOpen}
            currentTables={activeOrder.tables ?? []}
            tables={tables}
            onConfirm={tableAssignment.mergeTables}
          />

          {(activeOrder.tables ?? []).length >= 2 && (
            <UnmergeTableDialog
              open={unmergeTableOpen}
              onOpenChange={setUnmergeTableOpen}
              tables={activeOrder.tables ?? []}
              onConfirm={tableAssignment.unmergeTables}
            />
          )}

          <VoidItemDialog
            open={cartItems.voidItemTarget !== null}
            onOpenChange={(o) => {
              if (!o) clearVoidItemTarget();
            }}
            itemLabel={cartItems.voidItemTarget?.label ?? ""}
            reasons={voidReasonsQuery.data?.data ?? []}
            reasonsLoading={voidReasonsQuery.isLoading}
            onConfirm={cartItems.confirmVoidItem}
          />

          <PaymentDialog
            open={paymentOpen}
            onOpenChange={handlePaymentDialogClose}
            shopSlug={SHOP_SLUG}
            order={activeOrder}
            options={effectiveOptions}
            optionsLoading={effectiveOptionsQuery.isLoading}
            optionsError={effectiveOptionsQuery.error}
            onRetryOptions={() => void effectiveOptionsQuery.refetch()}
            policyRevision={policyRevision}
            outstanding={outstanding}
            outstandingLoading={outstandingQuery.isLoading}
            onCreatePayment={payments.createPayment}
            onCreateDebtPayment={payments.createDebtPayment}
            onDebtCharged={printResult.showDebtCharged}
            onPaymentSuccess={receipts.capturePaymentReceipt}
            onSwitchToSplit={
              activeOrder && activeOrder.guest_count != null && activeOrder.guest_count > 1
                ? () => {
                    setPaymentOpen(false);
                    handleOpenSplitBill();
                  }
                : undefined
            }
            currencyCode={shopOrderSettingsQuery.data?.data?.currency_code}
          />

          <SplitBillDialog
            open={splitBillOpen}
            onOpenChange={handleSplitBillClose}
            order={activeOrder}
            methods={checkoutPaymentMethods}
            methodsLoading={effectiveOptionsQuery.isLoading}
            splitData={splitBillQuery.data ?? null}
            splitLoading={splitBillQuery.isLoading || splitBillQuery.isFetching}
            splitError={
              splitBillQuery.error
                ? getApiErrorMessage(splitBillQuery.error, t("common.error_unknown"))
                : null
            }
            splitCount={splitCount}
            onChangeSplitCount={setSplitCount}
            onCreatePayment={payments.createSplitBillPayment}
            onRefundPayment={payments.refundSplitBillPayment}
            onPrintRowReceipt={payments.printSplitRowReceipt}
            shopSlug={SHOP_SLUG}
            onCancelSplit={handleCancelSplitBill}
            onAllRowsPaid={receipts.captureSplitBillReceipt}
            serviceChargeRate={Number(shopOrderSettingsQuery.data?.data.service_charge_rate ?? 0)}
            currencyCode={shopOrderSettingsQuery.data?.data?.currency_code}
          />
        </>
      )}

      {/* Result of a debt charge / invoice issue. The write already succeeded;
          this only asks whether to print. Rendered outside the activeOrder
          guard because the tab may already be gone by the time it shows. */}
      {printResult.printResult && (
        <PrintResultDialog
          open={true}
          onOpenChange={(o) => {
            if (!o) printResult.dismiss();
          }}
          target={printResult.printResult.target}
          detail={printResult.printResult.detail}
          onDone={printResult.dismiss}
        />
      )}

      {/* Debt lookup. Opened from the POS HEADER, so it works with no order on
          screen — "who owes us money" is a shop-wide question and used to be
          answerable only after creating an order. Settlement still needs a live
          order for the same customer (a backend rule, see handleSettleDebt), so
          the active order is handed in and the dialog decides. */}
      <DebtSearchDialog
        open={debtSearchOpen}
        onOpenChange={setDebtSearchOpen}
        shopSlug={SHOP_SLUG}
        activeOrder={
          activeOrder
            ? { id: activeOrder.id, customerId: activeOrder.customer_id ?? null }
            : null
        }
        paymentOptions={checkoutCapableOptions(effectiveOptions)}
        onSettle={settleDebt}
      />

      {/* Post-payment receipt — survives the activeOrder going away (tab
          close happens AFTER staff dismisses this dialog), so we render it
          outside the activeOrder conditional. State stays around until
          useReceiptFlow's complete handler clears it. */}
      {/* #2049 — hai kết cục của một phiên thu là hai MÀN khác nhau (thu đủ /
          đơn treo). Nhánh đó sống trong ClosingReceipt, không rải ở đây. */}
      <ClosingReceipt
        receipt={receipts.paymentReceipt}
        shopSlug={SHOP_SLUG}
        onCreateDebtPayment={payments.createDebtPayment}
        onDebtCharged={printResult.showDebtCharged}
        onComplete={receipts.completePaymentReceipt}
      />

      {/* Split-bill receipt — twin of paymentReceipt for the split flow. */}
      {receipts.splitBillReceipt && (
        <SplitBillReceiptDialog
          open={true}
          onOpenChange={(o) => {
            if (!o) receipts.completeSplitBillReceipt([]);
          }}
          data={receipts.splitBillReceipt.data}
          orderId={receipts.splitBillReceipt.orderId}
          customerId={receipts.splitBillReceipt.customerId}
          customerName={receipts.splitBillReceipt.customerName}
          onComplete={receipts.completeSplitBillReceipt}
        />
      )}

      <CloseTabConfirmDialog
        open={closeTabTarget !== null}
        onOpenChange={(o) => {
          if (!o) setCloseTabTarget(null);
        }}
        label={closeTabTarget?.label ?? ""}
        onConfirm={() => {
          if (closeTabTarget) closeTab(closeTabTarget.tabId);
          setCloseTabTarget(null);
        }}
      />

      {/* plan-019 — Stacking conflict on addItem of a Happy Hour item
          when a coupon is already attached. The "yes" CTA releases the
          coupon then retries the same addItem payload. */}
      <StackingConflictDialog
        conflict={cartItems.stackingConflict}
        onCancel={cartItems.dismissStackingConflict}
        onAutoResolve={cartItems.resolveStackingConflict}
        isPending={cartItems.stackingConflictPending}
      />
    </div>
  );
}
