/**
 * Stock Alert Service — pure TypeScript, no React dependency.
 *
 * Wraps `/api/v1/shops/{shopSlug}/stock-alerts/...`. The alert table is a
 * system-generated read-only log — there are no create/update/delete
 * endpoints. The list endpoint accepts a `search` filter (name or SKU of
 * the alerted item) in addition to warehouse / type / status filters.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { StockAlert } from "@/types/models/StockAlert";
import { StockAlertStatus } from "@/types/models/enum/StockAlertStatus";
import { StockAlertType } from "@/types/models/enum/StockAlertType";

export type { StockAlert };
export { StockAlertStatus, StockAlertType };

export interface StockAlertFilters {
  page?: number;
  per_page?: number;
  warehouse_id?: string;
  status?: StockAlertStatus;
  alert_type?: StockAlertType;
  search?: string;
  sort?: string;
}

export interface StockAlertSummary {
  total_active: number;
  low_stock_count: number;
  out_of_stock_count: number;
}

function alertUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/stock-alerts${path}`;
}

function toListParams(filters: StockAlertFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
  if (filters.status) params.set("status", filters.status);
  if (filters.alert_type) params.set("alert_type", filters.alert_type);
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

export const stockAlertService = {
  list: (shopSlug: string, filters: StockAlertFilters = {}) =>
    apiFetch<PaginatedResponse<StockAlert>>(`${alertUrl(shopSlug)}?${toListParams(filters)}`),

  summary: (shopSlug: string) =>
    apiFetch<{ data: StockAlertSummary }>(alertUrl(shopSlug, "/summary")),
};
