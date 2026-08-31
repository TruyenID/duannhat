import type {
  KdsOrder,
  KdsItem,
  KdsOrderTable,
  KdsItemTopping,
  OrderPriority,
  ItemStatus,
  ItemTransition,
} from "@/services/kds/orders";

export function makeTable(over: Partial<KdsOrderTable> = {}): KdsOrderTable {
  return { id: "t-1", code: "A3", zone: null, ...over };
}

export function makeTopping(over: Partial<KdsItemTopping> = {}): KdsItemTopping {
  return { name: null, quantity: 1, status: "pending", ...over };
}

const ALLOWED_BY_STATUS: Record<ItemStatus, ItemTransition[]> = {
  pending: ["mark-preparing"],
  preparing: ["mark-ready", "revert"],
  ready: ["mark-served", "revert"],
  served: ["revert"],
  voided: [],
};

export function makeItem(over: Partial<KdsItem> = {}): KdsItem {
  const status: ItemStatus = over.status ?? "pending";
  return {
    id: "i-1",
    menu_item_name: "Phở Bò",
    quantity: 1,
    status,
    note: null,
    aging_minutes: 0,
    time_in_current_status_seconds: 0,
    is_blocked_by_toppings: false,
    allowed_transitions: ALLOWED_BY_STATUS[status],
    started_preparing_at: null,
    ready_at: null,
    served_at: null,
    toppings: [],
    ...over,
  };
}

function priorityFromAging(aging: number): OrderPriority {
  if (aging >= 10) return "critical";
  if (aging >= 5) return "warning";
  return "normal";
}

/**
 * Builder for a server-shaped KdsOrder. Derived fields stay consistent with
 * the inputs you provide:
 *  - `opened_at` (default: now) drives `aging_minutes`, `is_late`, `priority`
 *  - `items[]` drives `pending/preparing/ready_items_count`, `oldest_pending_age_minutes`,
 *    `can_bump_all`
 * Any field you pass via `over` wins over the derivation.
 *
 * Cloud invariant: KdsOrderResource filters out voided items before
 * serialization, so `items[]` from the real API never contains them. Don't
 * pass voided items here unless you're explicitly testing pre-filter logic —
 * derived counts assume the cloud invariant.
 *
 * `can_bump_all` is computed from items only; cloud also factors order status
 * (voided/closed → false). For finalized-order tests, override `can_bump_all`
 * explicitly.
 */
export function makeOrder(over: Partial<KdsOrder> = {}): KdsOrder {
  const openedAt = over.opened_at !== undefined ? over.opened_at : new Date().toISOString();
  const computedAging =
    openedAt == null
      ? 0
      : Math.max(0, Math.floor((Date.now() - new Date(openedAt).getTime()) / 60_000));
  const aging = over.aging_minutes ?? computedAging;

  const items = over.items ?? [makeItem()];
  const pendingItems = items.filter((i) => i.status === "pending");
  const preparingItems = items.filter((i) => i.status === "preparing");
  const readyItems = items.filter((i) => i.status === "ready");
  const oldestPending = pendingItems.reduce(
    (max, i) => (i.aging_minutes > max ? i.aging_minutes : max),
    0,
  );
  const derivedCanBumpAll = pendingItems.length > 0 || preparingItems.length > 0;

  return {
    id: "o-1",
    order_code: "042",
    order_type: "dine_in",
    guest_count: null,
    note: null,
    opened_at: openedAt,
    aging_minutes: aging,
    is_late: over.is_late ?? aging > 10,
    priority: over.priority ?? priorityFromAging(aging),
    pending_items_count: over.pending_items_count ?? pendingItems.length,
    preparing_items_count: over.preparing_items_count ?? preparingItems.length,
    ready_items_count: over.ready_items_count ?? readyItems.length,
    oldest_pending_age_minutes: over.oldest_pending_age_minutes ?? oldestPending,
    can_bump_all: over.can_bump_all ?? derivedCanBumpAll,
    table: over.table !== undefined ? over.table : makeTable(),
    items,
    ...over,
  };
}
