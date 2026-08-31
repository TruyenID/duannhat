/**
 * Revenue Service — daily / monthly revenue aggregates for the POS
 * reports screen.
 *
 * Endpoint (identical shape served by Cloud and by the workstation LAN
 * handler — see backend/app/Http/Controllers/Api/V1/Pos/PosRevenueController
 * and workstation-app/internal/handler/local_pos_revenue.go):
 *
 *   GET /api/v1/pos/revenue/summary?granularity=day|month&from=&to=
 */

import { apiFetch } from "@/lib/api";

export type RevenueGranularity = "day" | "month" | "year";

export interface RevenueSummaryFilters {
  granularity?: RevenueGranularity;
  /** Inclusive YYYY-MM-DD (day) or YYYY-MM-01 (month) */
  from?: string;
  /** Inclusive YYYY-MM-DD */
  to?: string;
}

export interface RevenueKPIs {
  revenue: number;
  orders: number;
  guests: number;
  avg_per_guest: number;
  compare_revenue: number;
  delta_pct: number;
}

export interface RevenueSeriesPoint {
  period: string;
  revenue: number;
  orders: number;
  guests: number;
}

export interface RevenueWeekdayPoint {
  weekday: number;
  avg_revenue: number;
  total_revenue: number;
  sample_days: number;
}

export interface RevenuePaymentSplit {
  method_id: string | null;
  code: string | null;
  name: string | null;
  amount: number;
  share_pct: number;
}

export interface RevenueSummary {
  granularity: RevenueGranularity;
  from: string;
  to: string;
  kpis: RevenueKPIs;
  series: RevenueSeriesPoint[];
  by_weekday: RevenueWeekdayPoint[];
  by_payment_method: RevenuePaymentSplit[];
  generated_at: string;
  /** "workstation" when served by the LAN endpoint; absent otherwise. */
  source?: string;
}

function toQuery(filters: RevenueSummaryFilters): string {
  const params = new URLSearchParams();
  if (filters.granularity) params.set("granularity", filters.granularity);
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

export type RevenueProductLevel = "product" | "sku";
export type RevenueProductSort = "revenue" | "quantity" | "share";

export interface RevenueByProductFilters {
  from?: string;
  to?: string;
  level?: RevenueProductLevel;
  category_id?: string;
  sort?: RevenueProductSort;
  page?: number;
  per_page?: number;
}

export interface RevenueByProductRow {
  id: string;
  name: string;
  sku: string | null;
  category_id: string | null;
  category_name: string | null;
  quantity: number;
  revenue: number;
  share_pct: number;
}

export interface RevenueCategoryOption {
  id: string;
  name: string;
}

export interface RevenueByProductMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface RevenueByProduct {
  from: string;
  to: string;
  level: RevenueProductLevel;
  sort: RevenueProductSort;
  category_id: string | null;
  total_revenue: number;
  total_quantity: number;
  rows: RevenueByProductRow[];
  meta: RevenueByProductMeta;
  available_categories: RevenueCategoryOption[];
  generated_at: string;
  source?: string;
}

function byProductQuery(filters: RevenueByProductFilters): string {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.level) params.set("level", filters.level);
  if (filters.category_id) params.set("category_id", filters.category_id);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

// ---------------------------------------------------------------------------
//  Voids (cancellation analytics)
// ---------------------------------------------------------------------------

/** Same window contract as the summary endpoint. */
export type RevenueVoidsFilters = RevenueSummaryFilters;

export interface RevenueVoidKPIs {
  /** Whole-order cancellations. */
  order_voids: number;
  /** Their lost value (Σ cancelled orders' item subtotals — total is zeroed). */
  order_void_value: number;
  /** Per-item voids on orders that were NOT wholly voided (no double-count). */
  item_voids: number;
  item_void_value: number;
  /** order voids / (order voids + closed orders) in the window, %. */
  order_void_rate_pct: number;
}

export interface RevenueVoidSeriesPoint {
  period: string;
  order_voids: number;
  item_voids: number;
  void_value: number;
}

export interface RevenueVoidReason {
  /** "" when the cashier gave no reason. */
  reason: string;
  count: number;
  value: number;
}

export interface RevenueVoidTopItem {
  name: string;
  variant: string;
  count: number;
  value: number;
}

export interface RevenueVoids {
  granularity: RevenueGranularity;
  from: string;
  to: string;
  kpis: RevenueVoidKPIs;
  series: RevenueVoidSeriesPoint[];
  order_reasons: RevenueVoidReason[];
  item_reasons: RevenueVoidReason[];
  top_items: RevenueVoidTopItem[];
  generated_at: string;
  source?: string;
}

export type RevenueVoidEventType = "all" | "order" | "item";

export interface RevenueVoidEventsFilters {
  granularity?: RevenueGranularity;
  from?: string;
  to?: string;
  type?: RevenueVoidEventType;
  page?: number;
  per_page?: number;
}

/** One cancellation event: a whole-order void (kind=order) or a per-item void
 *  on a live order (kind=item). */
export interface RevenueVoidEvent {
  kind: "order" | "item";
  order_id: string;
  /** Canonical ORD-… code; may be empty / provisional (see isProvisionalCode). */
  order_code: string;
  /** ISO timestamp — COALESCE(voided_at, created_at). */
  voided_at: string;
  /** "" when the cashier gave no reason. */
  reason: string;
  /** Item name for kind=item; "" for whole-order rows. */
  item_name: string;
  variant: string;
  /** Voided quantity for kind=item; 0 for whole-order rows. */
  quantity: number;
  /** Line count of the voided order (kind=order); 1 for kind=item. */
  item_count: number;
  value: number;
}

export interface RevenueVoidEvents {
  from: string;
  to: string;
  type: RevenueVoidEventType;
  total: number;
  page: number;
  per_page: number;
  rows: RevenueVoidEvent[];
  generated_at: string;
  source?: string;
}

function voidEventsQuery(filters: RevenueVoidEventsFilters): string {
  const params = new URLSearchParams();
  if (filters.granularity) params.set("granularity", filters.granularity);
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.type) params.set("type", filters.type);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

export const revenueService = {
  summary: (_shopSlug: string, filters: RevenueSummaryFilters = {}) =>
    apiFetch<{ data: RevenueSummary }>(
      `/api/v1/pos/revenue/summary${toQuery(filters)}`,
    ),
  byProduct: (_shopSlug: string, filters: RevenueByProductFilters = {}) =>
    apiFetch<{ data: RevenueByProduct }>(
      `/api/v1/pos/revenue/by-product${byProductQuery(filters)}`,
    ),
  voids: (_shopSlug: string, filters: RevenueVoidsFilters = {}) =>
    apiFetch<{ data: RevenueVoids }>(
      `/api/v1/pos/revenue/voids${toQuery(filters)}`,
    ),
  voidEvents: (_shopSlug: string, filters: RevenueVoidEventsFilters = {}) =>
    apiFetch<{ data: RevenueVoidEvents }>(
      `/api/v1/pos/revenue/void-events${voidEventsQuery(filters)}`,
    ),
};
