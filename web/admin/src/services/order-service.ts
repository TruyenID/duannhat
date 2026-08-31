/**
 * Customer Order Service — pure TypeScript, no React dependency.
 *
 * All API calls for the Customer Order lifecycle:
 *
 *   Shop: /api/v1/shops/{shopSlug}/orders
 *   HQ:   /api/v1/hq/{brandSlug}/orders
 *
 * The HQ list endpoint returns an extra `aggregate` block alongside the
 * standard paginated envelope — see `HqOrderListResponse` below.
 *
 * Types are defined inline here rather than imported from `@/types/models/`
 * because backend has not yet shipped the Omnify schema for CustomerOrder /
 * CustomerOrderItem. See plan-001-customer-order-ui/DESIGN.md §"Key
 * decisions" #1.
 *
 * BR-OI01 — `unit_price` on every item MUST be captured client-side from the
 * menu SKU at the moment the row is added to the cart, and passed through
 * this service verbatim. The backend validates the snapshot is within
 * tolerance but never re-derives it. See DESIGN.md §"Key decisions" #3.
 *
 * The React-Query layer lives in src/hooks/api/use-orders.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { Customer } from "./customer-service";
import { mockOrderList, mockOrderDetail, mockHqOrderList } from "@/mocks/customer-order-data";

// DEV MOCK: fallback to mock data when the backend returns an error.
// Remove this wrapper once the backend ships the real endpoints.
const DEV = process.env.NODE_ENV === "development";
async function withMock<T>(fn: () => Promise<T>, mock: () => T | null): Promise<T> {
  try {
    return await fn();
  } catch {
    if (DEV) {
      const m = mock();
      if (m !== null) {
        console.warn("[DEV MOCK] returning mock order data");
        return m as T;
      }
    }
    throw new Error("Not found");
  }
}

// =========================================================================
//  Enums
// =========================================================================

export type CustomerOrderStatus =
  | "pending"
  // plan-037 — takeaway counter-pay order parked until the customer confirms
  // (or the reaper voids it). Both are backend CustomerOrderStatusEnum cases
  // the union previously omitted, so status-driven gates couldn't match them.
  | "awaiting_confirmation"
  | "confirmed"
  | "open"
  | "dining"
  | "checkout"
  | "paying"
  | "closed"
  | "voided"
  // #512 — takeaway counter-pay order whose payment deadline lapsed.
  | "expired";

export type CustomerOrderType = "dine_in" | "takeaway";

export type PaymentMethod = "cash" | "card" | "transfer" | "other";

/**
 * One row from `customer_orders.payments` as serialized by
 * OrderPaymentResource. Cash + Stripe + e-wallet all flow through this
 * shape; admin renders one card-row per entry.
 */
export interface OrderPaymentRow {
  id: string;
  payment_code: string;
  amount: number | string;
  tip_amount: number | string;
  status: "pending" | "succeeded" | "failed" | "refunded";
  paid_at: string | null;
  reference_no?: string | null;
  note?: string | null;
  payment_method: {
    id: string;
    code: string;
    name: string;
  } | null;
}

export type CustomerOrderItemStatus = "pending" | "preparing" | "ready" | "served" | "voided";

// =========================================================================
//  Row types
// =========================================================================

/** Thin customer reference included in order list rows (no full detail). */
export interface CustomerRef {
  id: string;
  first_name: string;
  last_name: string | null;
  phone: string | null;
}

/** Base product the SKU belongs to (the "dish"). Only `name` is needed for display. */
export interface ProductRef {
  id: string;
  name: string | null;
}

/**
 * One chosen variant option value (e.g. Size = M). `option` carries the
 * parent option group name so the UI can render "Size: M". Surfaced by
 * `ProductSkuResource` as `option_value1/2/3` when eager-loaded.
 */
export interface ProductOptionValueRef {
  id: string;
  label: string | null;
  value?: string | null;
  option?: { id: string; name: string | null } | null;
}

export interface ProductSkuRef {
  id: string;
  name: string | null;
  sku: string | null;
  selling_price?: number | string | null;
  /** The base product ("dish"). Eager-loaded on order detail. */
  product?: ProductRef | null;
  /** Variant option values (size / sugar level / …). Up to 3 positions. */
  option_value1?: ProductOptionValueRef | null;
  option_value2?: ProductOptionValueRef | null;
  option_value3?: ProductOptionValueRef | null;
}

/**
 * One chosen topping / modifier on a line, as flattened by
 * `CustomerOrderItemResource` (Plan 015). `name` is the topping product name,
 * `topping_group_name` the group it came from, `modifier_type` the group's
 * behaviour (e.g. `single` / `multiple`).
 */
export interface OrderItemToppingRow {
  id: string;
  topping_group_id?: string | null;
  topping_group_name?: string | null;
  topping_group_item_id?: string | null;
  product_sku_id?: string | null;
  name: string | null;
  modifier_type?: string | null;
  quantity: number;
  unit_price: number | string;
  note?: string | null;
}

/**
 * Promotion snapshot captured at cart-add time (Plan 019). `name` is a
 * per-locale map; `discount_percent` is the headline percentage applied.
 */
export interface AppliedPromotionSnapshot {
  name?: Record<string, string> | null;
  discount_percent?: string | number | null;
  stacking_mode?: string | null;
}

export interface CustomerOrderItem {
  id: string;
  customer_order_id: string;
  product_sku_id: string;
  quantity: number;
  /** Snapshot at cart-add time. BR-OI01. Stored as integer minor units. */
  unit_price: number;
  /** Pre-promotion unit price (Plan 019). Null when no promotion applied. */
  original_unit_price?: number | string | null;
  /** Per-unit topping surcharge snapshot (Plan 015). */
  topping_subtotal?: number | string | null;
  /** Promotion snapshot when one was auto-applied to this line. */
  applied_promotion_snapshot?: AppliedPromotionSnapshot | null;
  subtotal: number;
  status: CustomerOrderItemStatus;
  note: string | null;
  product_sku?: ProductSkuRef | null;
  /** Chosen toppings / modifiers for this line. */
  toppings?: OrderItemToppingRow[];
  /**
   * plan-045 — when set, this line is a REFUND of the referenced original
   * line: its `quantity` and `subtotal` are negative. Refund lines are
   * appended (the original is never edited), so the UI must render them with
   * a "返金 / Refund" badge instead of as an ordinary positive line.
   */
  refund_of_item_id?: string | null;
  /**
   * plan-045 — how many units of THIS (original) line have been refunded so
   * far. Bumped on each partial refund; drives the "返金済 x/y" progress hint
   * shown under the original line.
   */
  refunded_quantity?: number | string | null;
}

export interface OrderTableRef {
  id: string;
  code: string;
  name: string | null;
  status: string;
  qr_token: string;
}

/**
 * One row of the per-rate tax breakdown (plan-043). `taxable` is the base the
 * rate was applied to; `tax` is the resulting tax amount at that rate.
 */
export interface OrderTaxBreakdownRow {
  rate: number | string;
  taxable: number | string;
  tax: number | string;
}

/**
 * plan-045 — one row of the order-level condition ledger (value-copied audit
 * snapshots). `type` is `tax` | `discount` | `refund`; `amount` is signed
 * (refunds are negative). This is an append-only accounting ledger — the UI
 * does NOT render the raw rows (they'd duplicate the already-shown tax
 * breakdown / discount / refund lines); it only reads the `refund` rows to
 * compute a "total refunded" figure that is mode-correct server-side.
 */
export interface OrderConditionRow {
  id: string;
  type: "tax" | "discount" | "refund" | (string & {});
  source: string | null;
  label: string | null;
  rate: number | null;
  amount: number | string;
  currency_code: string | null;
  meta?: Record<string, unknown> | null;
}

export interface CustomerOrder {
  id: string;
  order_code: string;
  order_type: CustomerOrderType;
  status: CustomerOrderStatus;
  /**
   * ISO 4217 currency of the order, resolved from its branch by
   * CustomerOrderResource (#431). Every money amount on the order renders in
   * this currency — the UI locale only controls number formatting, never the
   * currency symbol.
   */
  currency: string;
  subtotal: number;
  discount_amount: number;
  /** Coupon code captured at apply time (Plan coupon). Null when none. */
  coupon_code_snapshot?: string | null;
  /** Service fee charged on the order. */
  service_charge?: number | string | null;
  /** Tax charged on the order. */
  tax_amount?: number | string | null;
  /**
   * Per-rate tax breakdown (plan-043). Each entry is a distinct rate applied
   * across the order's lines. Absent on older orders — callers fall back to
   * the single `tax_amount` line when this is missing/empty.
   */
  tax_breakdown?: OrderTaxBreakdownRow[] | null;
  /**
   * plan-043 — the order-level service-charge tax residual (the part of
   * `tax_amount` that comes from the service charge, which owns no line).
   * Additive companion to `tax_breakdown`; contract:
   * Σ tax_breakdown[].tax + service_charge_tax == tax_amount.
   * `null` on legacy orders whose lines aren't fully stamped.
   */
  service_charge_tax?: number | string | null;
  /**
   * Whether prices already include tax (税込) vs tax added on top (税別).
   * Drives the "included/excluded" mode chip in the charge summary.
   */
  is_tax_included?: boolean | null;
  /**
   * plan-045 — the tax-rounding rule snapshotted onto the order at create
   * time (immutable). `half_up` | `round_up` | `round_down`; null on legacy
   * orders. Surfaced as a small note in the charge summary for reconciliation
   * transparency.
   */
  tax_rounding_mode?: string | null;
  /**
   * plan-045 — decimals the tax was rounded to (0–3). null = the engine
   * derived the step from `currency` (legacy behaviour). Paired with
   * `tax_rounding_mode`.
   */
  tax_rounding_decimals?: number | null;
  /** Tip total across all payments. */
  total_tip?: number | string | null;
  total_amount: number;
  paid_amount: number;
  /**
   * @deprecated Backend `CustomerOrderResource` unsets this field — the
   * source of truth is the `payments[]` array. Kept on the type only for
   * legacy callers; expect `null` in new code.
   */
  payment_method: PaymentMethod | null;
  /**
   * @deprecated Same as `payment_method` — read `payments[i].paid_at`
   * instead. Backend resource removes this top-level field.
   */
  paid_at: string | null;
  /** Loaded in detail responses; empty array on list responses. */
  payments?: OrderPaymentRow[];
  stock_out_transaction_id: string | null;
  note: string | null;
  created_by_id: string;
  customer_id: string | null;
  /** Guest name captured when takeaway is placed without login. */
  customer_takeaway_name: string | null;
  /** Guest phone captured when takeaway is placed without login. */
  customer_takeaway_phone: string | null;
  /** Guest email captured when takeaway is placed without login. */
  customer_takeaway_email: string | null;
  /**
   * Takeaway pickup schedule — when the customer asked to collect the order
   * (ISO 8601). Only set for `scheduled` pickups; `immediate`/legacy takeaway
   * rows leave it null and carry the ready-time in the two fields below.
   */
  scheduled_pickup_time?: string | null;
  /** Kitchen-estimated ready time (ISO 8601). Set for every takeaway order. */
  estimated_ready_time?: string | null;
  /** When staff actually marked the order ready (ISO 8601); null until then. */
  actual_ready_time?: string | null;
  table_id: string | null;
  branch_id: string;
  brand_id: string;
  organization_id: string;
  /** Thin ref in list responses; full `Customer` in detail responses. */
  customer?: CustomerRef | Customer | null;
  items?: CustomerOrderItem[];
  /**
   * plan-045 — the order-level condition ledger (tax / discount / refund
   * snapshots). Loaded on detail responses. The UI reads only the `refund`
   * rows to compute a "total refunded" figure — it never dumps the raw
   * ledger (that would duplicate the tax breakdown / discount / refund lines).
   */
  conditions?: OrderConditionRow[] | null;
  /** Tables assigned to this order (loaded in detail responses). */
  tables?: OrderTableRef[];
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

// =========================================================================
//  Input / filter types
// =========================================================================

export interface OrderFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: CustomerOrderStatus;
  order_type?: CustomerOrderType;
  date_from?: string;
  date_to?: string;
  customer_id?: string;
  /** HQ only. */
  branch_id?: string;
}

export interface CreateOrderItemInput {
  product_sku_id: string;
  quantity: number;
  /** BR-OI01 — snapshot from menu SKU at cart-add time. Never omit. */
  unit_price: number;
  note?: string | null;
}

export interface CreateOrderInput {
  order_type: CustomerOrderType;
  customer_id?: string | null;
  table_ids?: string[];
  note?: string | null;
}

export interface AddItemsInput {
  items: CreateOrderItemInput[];
}

export interface CompleteOrderInput {
  payment_method: PaymentMethod;
  discount_amount?: number;
}

// =========================================================================
//  Response envelopes
// =========================================================================

/** HQ list adds an aggregate block on top of the standard paginated shape. */
export interface HqOrderListResponse extends PaginatedResponse<CustomerOrder> {
  aggregate: {
    total_revenue: number;
    order_count: number;
    avg_order_value: number;
    /**
     * #1961 — the distinct currencies the two money figures above were summed
     * across, from `branches.currency`.
     *
     * More than one entry means `total_revenue` and `avg_order_value` added
     * amounts in different currencies and MUST NOT be rendered as a single
     * number. Optional because an older backend does not send it; treat a
     * missing value as "unknown", not as "one".
     */
    currencies?: string[];
  };
}

// =========================================================================
//  URL / param helpers
// =========================================================================

function shopUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/orders${path}`;
}

function hqUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/orders${path}`;
}

function toParams(filters: OrderFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.status) params.set("status", filters.status);
  if (filters.order_type) params.set("order_type", filters.order_type);
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  if (filters.customer_id) params.set("customer_id", filters.customer_id);
  if (filters.branch_id) params.set("branch_id", filters.branch_id);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const orderService = {
  // --- Shop scope: queries ---

  list: (shopSlug: string, filters: OrderFilters = {}) =>
    withMock(
      () =>
        apiFetch<PaginatedResponse<CustomerOrder>>(
          `${shopUrl(shopSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
        ),
      () => mockOrderList(filters.page)
    ),

  getById: (shopSlug: string, id: string) =>
    withMock(
      () => apiFetch<{ data: CustomerOrder }>(shopUrl(shopSlug, `/${id}`)),
      () => mockOrderDetail(id)
    ),

  // --- Shop scope: mutations ---

  create: (shopSlug: string, data: CreateOrderInput) =>
    apiFetch<{ data: CustomerOrder }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  // Takeaway/online orders land in `pending`. Staff confirms via this
  // endpoint to transition pending → open, after which the regular
  // checkout pipeline (open → checkout → paying → paid → closed) applies.
  confirm: (shopSlug: string, id: string) =>
    apiFetch<{ data: CustomerOrder }>(shopUrl(shopSlug, `/${id}/confirm`), {
      method: "POST",
    }),

  complete: (shopSlug: string, id: string, data: CompleteOrderInput) =>
    apiFetch<{ data: CustomerOrder }>(shopUrl(shopSlug, `/${id}/checkout`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  // Cancelling an order IS a void — the backend dropped the `/cancel` alias so
  // staff always record why. `void_reason` is required; it lands on the order
  // and every voided line for reporting. Refuses a closed order (409) or one
  // still holding collected payments (409 void_blocked_collected_payment —
  // refund first).
  voidOrder: (shopSlug: string, id: string, voidReason: string) =>
    apiFetch<{ data: CustomerOrder }>(shopUrl(shopSlug, `/${id}/void`), {
      method: "POST",
      body: JSON.stringify({ void_reason: voidReason }),
    }),

  addItems: (shopSlug: string, orderId: string, data: AddItemsInput) =>
    apiFetch<{ data: CustomerOrder }>(shopUrl(shopSlug, `/${orderId}/items`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  removeItem: (shopSlug: string, orderId: string, itemId: string) =>
    apiFetch<{ data: CustomerOrder }>(shopUrl(shopSlug, `/${orderId}/items/${itemId}`), {
      method: "DELETE",
    }),

  // --- HQ scope ---

  hqList: (brandSlug: string, filters: OrderFilters = {}) =>
    withMock(
      () =>
        apiFetch<HqOrderListResponse>(
          `${hqUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
        ),
      () => mockHqOrderList(filters.page)
    ),

  hqGetById: (brandSlug: string, id: string) =>
    withMock(
      () => apiFetch<{ data: CustomerOrder }>(hqUrl(brandSlug, `/${id}`)),
      () => mockOrderDetail(id)
    ),

  // Cross-branch void from headquarters. Same teardown and same guards as the
  // shop-side void: reason required, 409 on a settled bill or one still holding
  // collected payments. Not wrapped in withMock — a mocked write would report
  // success without voiding anything.
  hqVoidOrder: (brandSlug: string, id: string, voidReason: string) =>
    apiFetch<{ data: CustomerOrder }>(hqUrl(brandSlug, `/${id}/void`), {
      method: "POST",
      body: JSON.stringify({ void_reason: voidReason }),
    }),
};
