/**
 * Stock Level Service — pure TypeScript, no React dependency.
 *
 * Wraps the shop-scoped stock-level endpoints at
 * `/api/v1/shops/{shopSlug}/stock-levels/...`.
 *
 * Stock levels are read-only aggregates maintained by the backend — there is
 * no create/delete; the only mutation is PUT for alert settings
 * (min_stock, max_stock, alert_enabled). Movement history for a level is
 * available as a paginated list under `/{id}/movements`.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { StockLevel } from "@/types/models/StockLevel";
import type { StockMovement } from "@/types/models/StockMovement";

export type { StockLevel, StockMovement };

// Matches the `stock_status` enum accepted by the backend list endpoint.
// The value is computed server-side from `quantity` vs `min_stock`.
export type StockStatus = "normal" | "low" | "out";

export interface StockLevelFilters {
  page?: number;
  per_page?: number;
  warehouse_id?: string;
  search?: string;
  stock_status?: StockStatus;
  alert_enabled?: boolean;
  sort?: string;
}

export interface StockLevelUpdateInput {
  min_stock?: number | null;
  max_stock?: number | null;
  alert_enabled?: boolean;
}

export interface StockMovementFilters {
  page?: number;
  per_page?: number;
  type?: string;
  from?: string;
  to?: string;
}

function stockLevelUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/stock-levels${path}`;
}

function toListParams(filters: StockLevelFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
  if (filters.search) params.set("search", filters.search);
  if (filters.stock_status) params.set("stock_status", filters.stock_status);
  if (filters.alert_enabled !== undefined) {
    params.set("alert_enabled", filters.alert_enabled ? "1" : "0");
  }
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

function toMovementParams(filters: StockMovementFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.type) params.set("type", filters.type);
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  return params.toString();
}

export const stockLevelService = {
  list: (shopSlug: string, filters: StockLevelFilters = {}) =>
    apiFetch<PaginatedResponse<StockLevel>>(`${stockLevelUrl(shopSlug)}?${toListParams(filters)}`),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockLevel }>(stockLevelUrl(shopSlug, `/${id}`)),

  update: (shopSlug: string, id: string, data: StockLevelUpdateInput) =>
    apiFetch<{ data: StockLevel }>(stockLevelUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  movements: (shopSlug: string, id: string, filters: StockMovementFilters = {}) =>
    apiFetch<PaginatedResponse<StockMovement>>(
      `${stockLevelUrl(shopSlug, `/${id}/movements`)}?${toMovementParams(filters)}`
    ),
};
