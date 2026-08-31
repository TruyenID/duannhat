/**
 * Order Service — full POS order lifecycle against the shop-scoped API.
 *
 * Endpoints consumed (plan-004 + plan-006 + plan-007):
 *
 *   GET    /api/v1/pos/orders
 *   POST   /api/v1/pos/orders
 *   GET    /api/v1/pos/orders/{id}
 *   PUT    /api/v1/pos/orders/{id}
 *   PUT    /api/v1/pos/orders/{id}/init
 *   DELETE /api/v1/pos/orders/{id}
 *   POST   /api/v1/pos/orders/{id}/items
 *   PATCH  /api/v1/pos/orders/{id}/items/{item}
 *   DELETE /api/v1/pos/orders/{id}/items/{item}
 *   POST   /api/v1/pos/orders/{id}/items/{item}/void
 *   POST   /api/v1/pos/orders/{id}/checkout
 *   POST   /api/v1/pos/orders/{id}/void
 *   POST   /api/v1/pos/orders/{id}/merge-table
 *   POST   /api/v1/pos/orders/{id}/unmerge-table
 *
 * Plan-007 Phase 1 change: the 4 item-mutation endpoints now return the full
 * CustomerOrder (with items + tables eager-loaded and recomputed totals), so
 * the service parses `data: CustomerOrder` for add/patch/void/delete-item.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  CustomerOrder,
  CustomerOrderType,
  OrderItemStatus,
  SplitBillResponse,
  ToppingSelection,
} from "@/app/pos/types";

// ---------------------------------------------------------------------------
//  List filters
// ---------------------------------------------------------------------------

export interface OrderListFilters {
  /** Comma-separated status list: "open,dining,checkout,paying". */
  status?: string;
  order_type?: CustomerOrderType;
  /** Per-table history — orders bound to this table (workstation: table_id +
   *  order_tables pivot; Cloud: the live table only). */
  table_id?: string;
  customer_id?: string;
  search?: string;
  date_from?: string;
  date_to?: string;
  sort?: string;
  page?: number;
  per_page?: number;
}

// ---------------------------------------------------------------------------
//  Input shapes (dynamic bodies — only send populated fields)
// ---------------------------------------------------------------------------

export interface OrderCreateInput {
  order_type?: CustomerOrderType;
  customer_id?: string;
  table_ids?: string[];
  guest_count?: number;
  note?: string;
}

export interface OrderInitInput {
  table_ids?: string[];
  guest_count?: number;
}

export interface OrderUpdateInput {
  guest_count?: number;
  note?: string;
  customer_id?: string;
  order_type?: CustomerOrderType;
}

export interface OrderItemInput {
  product_sku_id: string;
  /**
   * The MenuProductSku row the staff picked from. When provided, backend
   * uses THAT override's selling_price as the item's unit_price — so a
   * SKU that appears in multiple active menus with different prices
   * resolves deterministically to the one the staff actually clicked.
   * Omit for non-menu flows (direct cart add without menu context).
   */
  menu_product_sku_id?: string;
  /**
   * #1320 — the SPOTLIGHT membership ("this product, from this floating
   * section"), as handed out by the workstation on
   * `GET /api/v1/pos/floating-sections`. Set INSTEAD of `menu_product_sku_id`,
   * never alongside it: the two name rows in different tables, and the backend
   * prices the line off whichever surface is named (#1392).
   *
   * Workstation-only. Cloud ignores it and prices from the menu, which is the
   * correct degradation — a spotlight is a LAN-side surface.
   */
  floating_section_product_id?: string;
  quantity: number;
  note?: string;
  /**
   * Plan 015 — chosen topping selections for this line. Backend validates
   * against ProductToppingGroup attachments + effective bounds, snapshots
   * unit_price into order_item_toppings, and recomputes subtotal.
   * Omit when none.
   */
  toppings?: ToppingSelection[];
}

/**
 * #1320 — which surface field an add-item payload carries.
 *
 * Exactly ONE of the two is ever set, and getting it wrong is a pricing bug
 * rather than a type error: `menu_product_sku_id` names a row in
 * `menu_product_skus`, `floating_section_product_id` names one in
 * `pos_floating_section_products`. Feed a spotlight id to the first and the
 * backend looks up a menu SKU that does not exist, then prices the line off
 * whatever it falls back to.
 *
 * Pure on purpose: this is the one decision in the add-item path that must be
 * testable without mounting the 2,500-line POS page.
 */
export function addItemSurfaceFields(sku: {
  id: string;
  floating_section_product_id?: string;
}): { menu_product_sku_id: string } | { floating_section_product_id: string } {
  return sku.floating_section_product_id
    ? { floating_section_product_id: sku.floating_section_product_id }
    : { menu_product_sku_id: sku.id };
}

export interface OrderItemUpdateInput {
  // #1148 — product_sku_id / menu_product_sku_id are NOT accepted here:
  // a line's SKU is immutable server-side (Cloud + workstation 409 the
  // keys). A different variant = add the new line, void the old one.
  quantity?: number;
  /**
   * Customer kitchen note. `string` updates the value, `null` clears it,
   * `undefined` (or omitted) leaves it untouched.
   */
  note?: string | null;
  /** KDS state machine — out of plan-007 scope (no POS UI uses this yet). */
  status?: Exclude<OrderItemStatus, "voided">;
  /**
   * Plan 016 — atomic replace of OrderItemTopping rows on a `pending`
   * line. Server gates this on `status=pending`; rejected as 409 on
   * preparing+ (fall back to void+re-add). `null` / empty array means
   * "clear all toppings".
   */
  toppings?: ToppingSelection[] | null;
}

export interface OrderCheckoutInput {
  discount_amount?: number;
  // BR-SOS05: tax_amount + service_charge are NOT accepted at the API
  // anymore — the backend pulls tax_rate / service_charge_rate from the
  // branch's ShopOrderSetting and computes them server-side at checkout.
}

// ---------------------------------------------------------------------------
//  URL helpers
// ---------------------------------------------------------------------------

function ordersUrl(_shopSlug: string, path = ""): string {
  // shopSlug resolved server-side via X-Shop-Slug header; param retained
  // for caller compatibility + TanStack Query cache keys.
  return `/api/v1/pos/orders${path}`;
}

function toListParams(filters: OrderListFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.order_type) params.set("order_type", filters.order_type);
  if (filters.table_id) params.set("table_id", filters.table_id);
  if (filters.customer_id) params.set("customer_id", filters.customer_id);
  if (filters.search) params.set("search", filters.search);
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

// ---------------------------------------------------------------------------
//  Service
// ---------------------------------------------------------------------------

export const orderService = {
  list: (shopSlug: string, filters: OrderListFilters = {}) => {
    const query = toListParams(filters);
    const suffix = query ? `?${query}` : "";
    return apiFetch<PaginatedResponse<CustomerOrder>>(
      ordersUrl(shopSlug) + suffix,
    );
  },

  get: (shopSlug: string, orderId: string) =>
    apiFetch<{ data: CustomerOrder }>(ordersUrl(shopSlug, `/${orderId}`)),

  create: (shopSlug: string, body: OrderCreateInput) =>
    apiFetch<{ data: CustomerOrder }>(ordersUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(body),
    }),

  /** First-write-wins fill-in. Backend silently ignores already-set fields. */
  init: (shopSlug: string, orderId: string, body: OrderInitInput) =>
    apiFetch<{ data: CustomerOrder }>(ordersUrl(shopSlug, `/${orderId}/init`), {
      method: "PUT",
      body: JSON.stringify(body),
    }),

  /** Last-write-wins general update. Only works while order is open. */
  update: (shopSlug: string, orderId: string, body: OrderUpdateInput) =>
    apiFetch<{ data: CustomerOrder }>(ordersUrl(shopSlug, `/${orderId}`), {
      method: "PUT",
      body: JSON.stringify(body),
    }),

  delete: (shopSlug: string, orderId: string) =>
    apiFetch<null>(ordersUrl(shopSlug, `/${orderId}`), {
      method: "DELETE",
    }),

  // ------------------------------------------------------------------------
  //  Items — plan-007 Phase 1: all four return the full order
  // ------------------------------------------------------------------------

  addItems: (shopSlug: string, orderId: string, items: OrderItemInput[]) =>
    apiFetch<{ data: CustomerOrder }>(ordersUrl(shopSlug, `/${orderId}/items`), {
      method: "POST",
      body: JSON.stringify({ items }),
    }),

  updateItem: (
    shopSlug: string,
    orderId: string,
    itemId: string,
    body: OrderItemUpdateInput,
  ) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/items/${itemId}`),
      { method: "PATCH", body: JSON.stringify(body) },
    ),

  removeItem: (shopSlug: string, orderId: string, itemId: string) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/items/${itemId}`),
      { method: "DELETE" },
    ),

  /**
   * plan-051 (#1149) — void with a structured reason. `void_reason_id`
   * references the brand's VoidReason master (drives the stock effect);
   * `void_reason` is the free-text note (required when no id is sent, and
   * when the picked reason has requires_note). Keys absent from the payload
   * are omitted from the body so the backend's required_without validation
   * sees the true shape.
   */
  voidItem: (
    shopSlug: string,
    orderId: string,
    itemId: string,
    payload: { void_reason?: string; void_reason_id?: string },
  ) => {
    const body: Record<string, string> = {};
    if (payload.void_reason_id) body.void_reason_id = payload.void_reason_id;
    if (payload.void_reason) body.void_reason = payload.void_reason;
    return apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/items/${itemId}/void`),
      { method: "POST", body: JSON.stringify(body) },
    );
  },

  // ------------------------------------------------------------------------
  //  Workflow
  // ------------------------------------------------------------------------

  /**
   * Accept a customer-submitted takeaway (pending|confirmed → open) so it can
   * flow through the regular checkout pipeline — the "Tiếp nhận đơn" button.
   * Served locally by the workstation (200 idempotent when another terminal
   * already accepted) and by Cloud's POS namespace on fallback (409 there).
   */
  confirm: (shopSlug: string, orderId: string) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/confirm`),
      { method: "POST", body: JSON.stringify({}) },
    ),

  checkout: (shopSlug: string, orderId: string, body: OrderCheckoutInput = {}) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/checkout`),
      { method: "POST", body: JSON.stringify(body) },
    ),

  /**
   * #2479 — đường NGƯỢC của checkout: đưa bill về `open` để sửa được.
   *
   * Chỉ dùng cho cú chạm nhầm. Cloud trả **409** khi đơn không ở `checkout`
   * hoặc khi đơn còn giữ tiền — cùng mã lỗi mà checkout đã trả, nên chỗ gọi
   * không phải học thêm một đường xử lý mới.
   *
   * `reason` bắt buộc ở CẢ hai đầu: không có nó, mở lại thành cách sửa bill rẻ
   * hơn void mà không để dấu vết.
   */
  reopen: (shopSlug: string, orderId: string, reason: string) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/reopen`),
      { method: "POST", body: JSON.stringify({ reason }) },
    ),

  voidOrder: (shopSlug: string, orderId: string, voidReason: string) =>
    apiFetch<{ data: CustomerOrder }>(ordersUrl(shopSlug, `/${orderId}/void`), {
      method: "POST",
      body: JSON.stringify({ void_reason: voidReason }),
    }),

  // ------------------------------------------------------------------------
  //  Coupon apply / release (plan-019 endpoints #8 / #9)
  // ------------------------------------------------------------------------

  applyCoupon: (
    shopSlug: string,
    orderId: string,
    body: {
      code: string;
      customer_id?: string | null;
      /**
       * plan-019 — "Use coupon instead of promotion" opt-in. When true,
       * the backend reverts every cart line carrying an
       * `exclusive_with_coupons` HH promotion back to `original_unit_price`
       * BEFORE the coupon runs (per-line audit log preserves the change).
       * Default: false → backend rejects with 422
       * `coupon_excluded_by_active_promotion` if conflict.
       */
      downgrade_exclusive_promotions?: boolean;
    },
  ) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/apply-coupon`),
      { method: "POST", body: JSON.stringify(body) },
    ),

  releaseCoupon: (shopSlug: string, orderId: string) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/coupon`),
      { method: "DELETE" },
    ),

  // ------------------------------------------------------------------------
  //  Table management — used by ChangeTableDialog's 2-step swap
  // ------------------------------------------------------------------------

  mergeTable: (shopSlug: string, orderId: string, tableId: string) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/merge-table`),
      { method: "POST", body: JSON.stringify({ table_id: tableId }) },
    ),

  unmergeTable: (shopSlug: string, orderId: string, tableId: string) =>
    apiFetch<{ data: CustomerOrder }>(
      ordersUrl(shopSlug, `/${orderId}/unmerge-table`),
      { method: "POST", body: JSON.stringify({ table_id: tableId }) },
    ),

  // ------------------------------------------------------------------------
  //  Split bill — calculator only; payments still go through /payments.
  // ------------------------------------------------------------------------

  /**
   * Backend returns the JSON flat (no `data:` envelope) — see
   * tests/Feature/Shop/OrderSplitBillTest.php which asserts
   * `$response->json('split_count')` directly. Pass `splitCount` undefined
   * to fall back to `order.guest_count` server-side.
   */
  getSplitBill: (
    shopSlug: string,
    orderId: string,
    splitCount?: number,
  ) => {
    const qs =
      splitCount !== undefined ? `?split_count=${splitCount}` : "";
    return apiFetch<SplitBillResponse>(
      ordersUrl(shopSlug, `/${orderId}/split-bill${qs}`),
    );
  },
};
