/**
 * Order hooks — queries + mutations for the POS lifecycle.
 *
 * Plan-007 Phase 1 backend change: item mutations return the full
 * CustomerOrder, so the mutation hooks use `setQueryData(detail, response.data)`
 * instead of invalidate + refetch. Only the list key is invalidated when
 * chip summaries need refresh.
 */

import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
} from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { getT } from "@/providers/app-provider";
import {
  orderService,
  type OrderCheckoutInput,
  type OrderCreateInput,
  type OrderInitInput,
  type OrderItemInput,
  type OrderItemUpdateInput,
  type OrderUpdateInput,
} from "@/services/order-service";
import type { CustomerOrder } from "@/app/pos/types";
import { orderKeys, tableKeys } from "./query-keys";

// ---------------------------------------------------------------------------
//  Queries
// ---------------------------------------------------------------------------

/**
 * List orders currently in the tab-bar-visible lifecycle: pending, open,
 * dining, checkout, paying. `pending` is INCLUDED because takeaway orders
 * are created with `status = pending` (dine_in / spot start at `open`) — if
 * the filter dropped `pending`, a freshly-created takeaway order would be
 * absent from open-orders and `reconcileWithServer` would immediately drop
 * its tab, bouncing staff back to the table-select screen even though the
 * order was created + synced fine (#489). `paying` is INCLUDED so a
 * partially-paid order
 * (e.g. mid split-bill where staff has only collected from some of the
 * customers) keeps its tab — otherwise `reconcileWithServer` would drop
 * the tab the moment the first split-bill row succeeds, kicking staff
 * out of the dialog before they can collect from the rest. The regular
 * PaymentDialog flow that wants to drop a paying tab (customer walked
 * off) still does so explicitly via `handlePaymentDialogClose`, so the
 * "ghost paying tab" risk only exists if staff abandons the POS without
 * closing the dialog — recoverable by manually closing the tab.
 */
export const OPEN_ORDER_STATUSES =
  "pending,awaiting_confirmation,confirmed,open,dining,checkout,paying";

/**
 * Query filters for the open-orders feed. Exported as a stable constant so the
 * workstation socket busts the exact same query key — the two used to carry
 * duplicate string literals, which is precisely how they drift.
 */
export const OPEN_ORDER_FILTERS = {
  status: OPEN_ORDER_STATUSES,
  per_page: 100,
} as const;

/**
 * Poll options for the WS-driven order feeds. The caller decides: these lists
 * are kept fresh by workstation socket invalidations, so they poll ONLY when
 * there is no live channel (#1792). Mirrors `UseTablesOptions`.
 */
export interface UseOrderFeedOptions {
  /** Poll interval in ms; omit/undefined to disable active polling. */
  refetchInterval?: number;
}

export function useOpenOrders(
  shopSlug: string,
  { refetchInterval }: UseOrderFeedOptions = {},
) {
  const filters = OPEN_ORDER_FILTERS;

  return useQuery({
    queryKey: orderKeys.list(shopSlug, filters),
    queryFn: () => orderService.list(shopSlug, filters),
    enabled: !!shopSlug,
    staleTime: 30 * 1000,
    refetchInterval,
  });
}

/**
 * Filters for the takeaway drawer's dedicated feed. Kept as a stable exported
 * constant so the query key here matches the invalidation key the workstation
 * socket busts on takeaway order events (use-workstation-socket.ts) — a literal
 * inlined in two places would drift and silently break realtime.
 *
 * Why a SEPARATE query from useOpenOrders instead of filtering its result: the
 * open-orders feed is `per_page: 100` shared across ALL order types. A busy
 * dine-in floor (hundreds of open orders) would crowd takeaway orders out of
 * that first page, so the drawer + its count badge must query
 * `order_type=takeaway` on its own page budget.
 */
export const TAKEAWAY_ORDER_FILTERS = {
  status: OPEN_ORDER_STATUSES,
  order_type: "takeaway",
  per_page: 100,
} as const;

/**
 * Active takeaway orders (pending → paying). Always enabled so the overview's
 * "Đơn takeaway" badge stays live; the socket invalidates this key when a
 * takeaway order is created / updated / paid so the badge + drawer refetch.
 */
export function useTakeawayOrders(
  shopSlug: string,
  { refetchInterval }: UseOrderFeedOptions = {},
) {
  return useQuery({
    queryKey: orderKeys.list(shopSlug, TAKEAWAY_ORDER_FILTERS),
    queryFn: () => orderService.list(shopSlug, TAKEAWAY_ORDER_FILTERS),
    enabled: !!shopSlug,
    staleTime: 30 * 1000,
    refetchInterval,
  });
}

// Per-table history covers the full order lifecycle (open → closed → voided).
const TABLE_HISTORY_STATUSES = `${OPEN_ORDER_STATUSES},closed,voided,expired`;

/**
 * Every order bound to a table, newest-first, for the per-table history view.
 * Served LAN-first by the workstation (persistent table_id + order_tables pivot
 * → full local history); on a Cloud fallback only the table's live order comes
 * back (Cloud doesn't retain the historical table link). `dateFrom` (YYYY-MM-DD,
 * omit for "all") bounds the window.
 */
export function useTableOrders(
  shopSlug: string,
  tableId: string | null | undefined,
  opts: { dateFrom?: string } = {},
) {
  const filters = {
    table_id: tableId ?? "",
    status: TABLE_HISTORY_STATUSES,
    ...(opts.dateFrom ? { date_from: opts.dateFrom } : {}),
    per_page: 100,
    sort: "-created_at",
  };
  return useQuery({
    queryKey: orderKeys.list(shopSlug, filters),
    queryFn: () => orderService.list(shopSlug, filters),
    enabled: !!shopSlug && !!tableId,
    staleTime: 30 * 1000,
  });
}

/**
 * All-tables order history — every order (full lifecycle: open → closed →
 * voided) across the branch within a date window, newest-first, paginated.
 * Backs the OrderHistoryPage. An infinite query so a busy month/year loads
 * incrementally ("Xem thêm") instead of one giant first page; the workstation
 * and Cloud both return current_page / last_page / total meta (COUNT(*)), so
 * `getNextPageParam` stops cleanly at the last page.
 */
export function useAllOrdersHistory(
  shopSlug: string,
  opts: { dateFrom?: string; dateTo?: string } = {},
) {
  const filters = {
    status: TABLE_HISTORY_STATUSES,
    ...(opts.dateFrom ? { date_from: opts.dateFrom } : {}),
    ...(opts.dateTo ? { date_to: opts.dateTo } : {}),
    per_page: 50,
    sort: "-created_at",
  };
  return useInfiniteQuery({
    queryKey: orderKeys.history(shopSlug, filters),
    queryFn: ({ pageParam }) =>
      orderService.list(shopSlug, { ...filters, page: pageParam }),
    enabled: !!shopSlug,
    staleTime: 30 * 1000,
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
  });
}

export function useOrder(shopSlug: string, orderId: string | null | undefined) {
  return useQuery({
    queryKey: orderKeys.detail(shopSlug, orderId ?? ""),
    queryFn: () => orderService.get(shopSlug, orderId as string),
    enabled: !!shopSlug && !!orderId,
    staleTime: 30 * 1000,
  });
}

/**
 * Calculator query — recomputes per-person amounts from the server's
 * `remaining = total_amount - paid_amount`. We disable caching
 * (`staleTime: 0`) so each new payment that lands flips `paid_amount`
 * and the next invalidation produces a fresh, smaller split. Caller
 * is expected to invalidate on each successful `POST /payments`.
 */
export function useSplitBill(
  shopSlug: string,
  orderId: string | null,
  splitCount: number,
  enabled = true,
) {
  return useQuery({
    queryKey: orderKeys.splitBill(shopSlug, orderId ?? "", splitCount),
    queryFn: () =>
      orderService.getSplitBill(shopSlug, orderId as string, splitCount),
    enabled: enabled && !!shopSlug && !!orderId && splitCount >= 2,
    staleTime: 0,
  });
}

// ---------------------------------------------------------------------------
//  Mutation helpers
// ---------------------------------------------------------------------------

function toastError(error: unknown): void {
  if (error instanceof ApiError) {
    toast.error(
      (error.body?.message as string | undefined) ?? error.message,
    );
  } else if (error instanceof Error) {
    toast.error(error.message);
  } else {
    toast.error("Unknown error");
  }
}

type MutationOpts<TData, TVariables> = Omit<
  UseMutationOptions<TData, unknown, TVariables>,
  "mutationFn"
>;

// ---------------------------------------------------------------------------
//  Order-level mutations
// ---------------------------------------------------------------------------

export function useCreateOrder(
  shopSlug: string,
  opts: MutationOpts<{ data: CustomerOrder }, OrderCreateInput> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (body: OrderCreateInput) => orderService.create(shopSlug, body),
    onSuccess: (response, _variables) => {
      qc.setQueryData(
        orderKeys.detail(shopSlug, response.data.id),
        response,
      );
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      if (_variables.table_ids?.length) {
        qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      }
      toast.success(getT()("pos.toast.order_created"));
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

export function useInitOrder(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, OrderInitInput> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (body: OrderInitInput) =>
      orderService.init(shopSlug, orderId, body),
    onSuccess: (response, _variables) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      if (_variables.table_ids?.length) {
        qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      }
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

export function useUpdateOrder(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, OrderUpdateInput> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (body: OrderUpdateInput) =>
      orderService.update(shopSlug, orderId, body),
    onSuccess: (response, _variables) => {
      // Silent on success — the inline ✎ edit should feel instant, not spammy.
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

/**
 * Picks /init when the target field is currently blank (first-write-wins)
 * and /{id} when it's already set (last-write-wins). See plan-007 DESIGN
 * §"Endpoint-selection rule".
 *
 * Returns `null` when the order is not yet loaded — callers should guard
 * with `if (!save) return;`. Hook order stays stable because the inner
 * useInitOrder/useUpdateOrder are called unconditionally against the
 * empty-string orderId (their mutations stay idle until the real fn
 * replaces them on the next render).
 */
export function useOrderFieldSave(
  shopSlug: string,
  order: CustomerOrder | null,
) {
  const init = useInitOrder(shopSlug, order?.id ?? "");
  const update = useUpdateOrder(shopSlug, order?.id ?? "");

  if (!order) return null;

  return (patch: OrderInitInput & OrderUpdateInput) => {
    const tablesEmpty = (order.tables ?? []).length === 0;
    const guestNull = order.guest_count === null;

    const isInitialFill =
      (patch.table_ids !== undefined && tablesEmpty) ||
      (patch.guest_count !== undefined && guestNull);

    if (isInitialFill) {
      const initBody: OrderInitInput = {};
      if (patch.table_ids !== undefined) initBody.table_ids = patch.table_ids;
      if (patch.guest_count !== undefined) {
        initBody.guest_count = patch.guest_count;
      }
      return init.mutateAsync(initBody);
    }

    const updateBody: OrderUpdateInput = {};
    if (patch.guest_count !== undefined) {
      updateBody.guest_count = patch.guest_count;
    }
    if (patch.note !== undefined) updateBody.note = patch.note;
    if (patch.customer_id !== undefined) {
      updateBody.customer_id = patch.customer_id;
    }
    if (patch.order_type !== undefined) {
      updateBody.order_type = patch.order_type;
    }
    return update.mutateAsync(updateBody);
  };
}

export function useDeleteOrder(
  shopSlug: string,
  opts: MutationOpts<null, string> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (orderId: string) => orderService.delete(shopSlug, orderId),
    onSuccess: (_data, orderId) => {
      qc.removeQueries({ queryKey: orderKeys.detail(shopSlug, orderId) });
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      toast.success(getT()("pos.toast.order_deleted"));
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

// ---------------------------------------------------------------------------
//  Item mutations — Phase 1 change: responses carry the full order
//
//  Every item mutation returns the FULL order and writes it straight into the
//  detail cache via `setQueryData`. Because add / update-qty / remove / void
//  all mutate the same order and each response is a full snapshot, two
//  in-flight requests racing meant a stale response (e.g. quantity=1) could
//  land AFTER a newer one (quantity=2) and clobber it — the "increment qty
//  then add another item resets the first back to x1" bug (#563).
//
//  Fix: give all item mutations for one order the same mutation scope. React
//  Query runs same-scope mutations serially — the next mutationFn stays queued
//  until the previous one has fully settled (onSuccess/setQueryData included),
//  so requests are sent and applied in submission order and no stale full-order
//  response can overwrite a newer one. This adds no extra refetch.
// ---------------------------------------------------------------------------

/** Serialises all item mutations targeting the same order (see #563). */
function itemMutationScope(shopSlug: string, orderId: string) {
  return { id: `order-items:${shopSlug}:${orderId}` };
}

export function useAddItems(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, OrderItemInput[]> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    scope: itemMutationScope(shopSlug, orderId),
    mutationFn: (items: OrderItemInput[]) =>
      orderService.addItems(shopSlug, orderId, items),
    onSuccess: (response, _variables) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

export interface UpdateItemVariables {
  itemId: string;
  body: OrderItemUpdateInput;
}

export function useUpdateItem(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, UpdateItemVariables> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    scope: itemMutationScope(shopSlug, orderId),
    mutationFn: ({ itemId, body }: UpdateItemVariables) =>
      orderService.updateItem(shopSlug, orderId, itemId, body),
    onSuccess: (response) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

export function useRemoveItem(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, string> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    scope: itemMutationScope(shopSlug, orderId),
    mutationFn: (itemId: string) =>
      orderService.removeItem(shopSlug, orderId, itemId),
    onSuccess: (response) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

export interface VoidItemVariables {
  itemId: string;
  /**
   * Free-text reason / note. Required when no voidReasonId is sent
   * (backend required_without) and when the picked reason has
   * requires_note.
   */
  voidReason?: string;
  /** plan-051 — id of a VoidReason master row (drives the stock effect). */
  voidReasonId?: string;
}

/**
 * plan-051 — the three structured rejection codes the void-item endpoint
 * returns. Each maps to a specific operator-actionable toast; anything else
 * falls through to the generic toastError.
 */
function toastVoidItemError(error: unknown): void {
  if (error instanceof ApiError) {
    const code = error.body?.code;
    const t = getT();
    if (code === "ITEM_STATUS_NOT_VOIDABLE") {
      const statuses = error.body?.voidable_statuses;
      toast.error(
        t("pos.toast.void_item.status_not_voidable", {
          statuses: Array.isArray(statuses) ? statuses.join(", ") : "pending",
        }),
      );
      return;
    }
    if (code === "VOID_REASON_INVALID") {
      toast.error(t("pos.toast.void_item.reason_invalid"));
      return;
    }
    if (code === "VOID_NOTE_REQUIRED") {
      toast.error(t("pos.toast.void_item.note_required"));
      return;
    }
  }
  toastError(error);
}

/**
 * Explicit void-with-reason. Which statuses are voidable is a per-shop
 * matrix (plan-051 — `item_voidable_statuses`, pending always allowed);
 * the caller derives the gate via resolveVoidableStatuses and hides the
 * button for the rest. The backend 409s ITEM_STATUS_NOT_VOIDABLE if the
 * item has moved past the shop's matrix anyway.
 */
export function useVoidItem(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, VoidItemVariables> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    scope: itemMutationScope(shopSlug, orderId),
    mutationFn: ({ itemId, voidReason, voidReasonId }: VoidItemVariables) =>
      orderService.voidItem(shopSlug, orderId, itemId, {
        void_reason: voidReason,
        void_reason_id: voidReasonId,
      }),
    onSuccess: (response) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
    },
    onError: (error) => {
      toastVoidItemError(error);
    },
    ...opts,
  });
}

// ---------------------------------------------------------------------------
//  Workflow mutations
// ---------------------------------------------------------------------------

export function useCheckoutOrder(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, OrderCheckoutInput> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (body: OrderCheckoutInput = {}) =>
      orderService.checkout(shopSlug, orderId, body),
    onSuccess: (response, _variables) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      toast.success(getT()("pos.toast.order_checkout"));
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

/**
 * Accept a customer-submitted takeaway (pending|confirmed → open) — the
 * "Tiếp nhận đơn" button. On error the cached order is re-synced: a 409
 * usually means another terminal already accepted (or the order moved on),
 * so the button state must correct itself without a manual refresh.
 */
export function useConfirmOrder(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, void> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: () => orderService.confirm(shopSlug, orderId),
    onSuccess: (response) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      toast.success(getT()("pos.toast.order_accepted"));
    },
    onError: (error) => {
      qc.invalidateQueries({ queryKey: orderKeys.detail(shopSlug, orderId) });
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      toastError(error);
    },
    ...opts,
  });
}

/**
 * #2479 — mở lại bill chốt nhầm (`checkout` → `open`).
 *
 * Invalidate ĐÚNG tập mà void invalidate: detail + danh sách + bàn. Đơn quay về
 * sửa được nghĩa là dải tab, lưới bàn và giỏ đều đang hiển thị một trạng thái đã
 * cũ.
 */
export function useReopenOrder(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, string> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (reason: string) => orderService.reopen(shopSlug, orderId, reason),
    onSuccess: (response) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      toast.success(getT()("pos.toast.order_reopened"));
    },
    ...opts,
  });
}

export function useVoidOrder(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, string> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (voidReason: string) =>
      orderService.voidOrder(shopSlug, orderId, voidReason),
    onSuccess: (response, _variables) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      toast.success(getT()("pos.toast.order_voided"));
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

// ---------------------------------------------------------------------------
//  Coupon apply / release (plan-019)
//
//  Both mutate the order's `discount_amount` server-side via CouponService.
//  We cache-set the returned order so the cart re-renders with the new
//  `discount_amount` + `coupon_code_snapshot` immediately, then invalidate
//  the list cache so the order chip summary catches up.
// ---------------------------------------------------------------------------

export function useApplyCoupon(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<
    { data: CustomerOrder },
    {
      code: string;
      customer_id?: string | null;
      downgrade_exclusive_promotions?: boolean;
    }
  > = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (body: {
      code: string;
      customer_id?: string | null;
      downgrade_exclusive_promotions?: boolean;
    }) => orderService.applyCoupon(shopSlug, orderId, body),
    onSuccess: (response) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      toast.success(getT()("pos.coupon.applied_toast"));
    },
    // Intentionally no onError toast — the caller surfaces the structured
    // `error_code` from CouponException in the cart UI so the staff sees a
    // specific message ("Coupon expired", "Branch not eligible", …) instead
    // of a generic error.
    ...opts,
  });
}

export function useReleaseCoupon(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, void> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: () => orderService.releaseCoupon(shopSlug, orderId),
    onSuccess: (response) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      toast.success(getT()("pos.coupon.released_toast"));
    },
    onError: (error) => {
      toastError(error);
    },
    ...opts,
  });
}

// ---------------------------------------------------------------------------
//  Table management — merge + unmerge are low-level; useChangeTable is the
//  orchestrator the UI binds to.
// ---------------------------------------------------------------------------

export function useMergeTable(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, string> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (tableId: string) =>
      orderService.mergeTable(shopSlug, orderId, tableId),
    onSuccess: (response, _variables) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      // Keep the open-orders list fresh like every other order mutation (#540).
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
      // No toast — useChangeTable decides the single message for the swap.
    },
    // No onError override — useChangeTable surfaces the stage-specific Alert.
    ...opts,
  });
}

export function useUnmergeTable(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: CustomerOrder }, string> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (tableId: string) =>
      orderService.unmergeTable(shopSlug, orderId, tableId),
    onSuccess: (response, _variables) => {
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), response);
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      // Keep the open-orders list fresh like every other order mutation (#540).
      qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
    },
    // No onError override — useChangeTable surfaces the stage-specific Alert.
    ...opts,
  });
}

export type ChangeTableResult =
  | { ok: true }
  | { ok: false; stage: "merge" | "unmerge"; error: ApiError };

/**
 * Orchestrates the 2-step table swap. TB-08 forces merge-then-unmerge so we
 * don't drop below 1 table on a dine-in order. Returns a tagged result so
 * the caller decides what to render (inline error on merge failure, inline
 * Alert with retry on unmerge failure). See plan-007 DESIGN §"Change table
 * flow" + §"Decision 9".
 *
 * The inner merge/unmerge run SILENTLY (their onSuccess cache write is
 * overridden with a no-op) so the intermediate "order carries both tables"
 * snapshot never reaches the shared detail cache. Writing it caused a visible
 * glitch (#540): the ChangeTableDialog is gated on `tables.length === 1`, so
 * a 2-table intermediate state unmounted the open dialog, flickered the order
 * header (`Bàn A → Bàn A, Bàn B`), and refetched the table map twice. Instead
 * we write the cache ONCE — the final single-table order on success, or the
 * merged 2-table order on unmerge failure (which is the true server state and
 * drives the retry UI) — and batch the invalidations to the end.
 */
export function useChangeTable(shopSlug: string, orderId: string) {
  const qc = useQueryClient();
  const noop = () => {};
  const merge = useMergeTable(shopSlug, orderId, { onSuccess: noop });
  const unmerge = useUnmergeTable(shopSlug, orderId, { onSuccess: noop });

  const invalidateAfterSwap = () => {
    qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    qc.invalidateQueries({ queryKey: orderKeys.lists(shopSlug) });
  };

  return async (
    fromTableId: string,
    toTableId: string,
  ): Promise<ChangeTableResult> => {
    let mergeResponse: { data: CustomerOrder };
    try {
      mergeResponse = await merge.mutateAsync(toTableId);
    } catch (error) {
      // Server state unchanged (still the original single table) — leave the
      // cache as-is so the dialog stays put for a retry.
      return {
        ok: false,
        stage: "merge",
        error: error instanceof ApiError
          ? error
          : new ApiError(0, { message: String(error) }),
      };
    }

    let unmergeResponse: { data: CustomerOrder };
    try {
      unmergeResponse = await unmerge.mutateAsync(fromTableId);
    } catch (error) {
      // Unmerge failed → the order genuinely carries both tables now. Reflect
      // that real state in the cache (single write) so the retry UI renders
      // and the table map / open-orders list are correct.
      qc.setQueryData(orderKeys.detail(shopSlug, orderId), mergeResponse);
      invalidateAfterSwap();
      return {
        ok: false,
        stage: "unmerge",
        error: error instanceof ApiError
          ? error
          : new ApiError(0, { message: String(error) }),
      };
    }

    // Both steps done — one cache write with the final single-table order.
    qc.setQueryData(orderKeys.detail(shopSlug, orderId), unmergeResponse);
    invalidateAfterSwap();

    toast.success(getT()("pos.toast.table_changed"));
    return { ok: true };
  };
}
