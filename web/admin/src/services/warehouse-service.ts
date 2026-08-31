/**
 * Warehouse Service — pure TypeScript, no React dependency.
 *
 * Wraps the shop-scoped warehouse endpoints at
 * `/api/v1/shops/{shopSlug}/warehouses/...`. Full CRUD + toggle-active,
 * restore, and auto-approval settings patch. Member management routes
 * exist on the backend but are not yet surfaced here — add them when the
 * warehouse detail page is ported in a follow-up phase.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { Warehouse } from "@/types/models/Warehouse";
import { WarehouseType } from "@/types/models/enum/WarehouseType";

export type { Warehouse };
export { WarehouseType };

export interface WarehouseFilters {
  page?: number;
  per_page?: number;
  search?: string;
  type?: WarehouseType;
  branch_id?: string;
  is_active?: boolean;
  trashed?: "with" | "only";
  sort?: string;
}

export interface AllergenPolicy {
  forbidden: string[];
  required_separate: string[];
}

export interface WarehouseCreateInput {
  code: string;
  name: string;
  type: WarehouseType;
  branch_id?: string | null;
  address?: string | null;
  allergen_policy?: AllergenPolicy | null;
  allow_negative_sales?: boolean;
  auto_approve_stock_in?: boolean;
  auto_approve_stock_out?: boolean;
  auto_approve_batch?: boolean;
  auto_approve_disposal?: boolean;
  disposal_approval_threshold?: number | null;
}

export interface WarehouseUpdateInput {
  code?: string;
  name?: string;
  type?: WarehouseType;
  branch_id?: string | null;
  address?: string | null;
  allergen_policy?: AllergenPolicy | null;
}

export interface WarehouseSettingsInput {
  auto_approve_stock_in?: boolean;
  auto_approve_stock_out?: boolean;
  auto_approve_batch?: boolean;
  auto_approve_disposal?: boolean;
  disposal_approval_threshold?: number | null;
  /**
   * Plan-024 — when true, sales-flow stock_out is allowed to drive
   * StockLevel.quantity below zero. Out-of-stock alert fires
   * immediately. Manual stock_out / disposal / adjustment_out paths
   * remain strict. Default false.
   */
  allow_negative_sales?: boolean;
}

function warehouseUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/warehouses${path}`;
}

function toParams(filters: WarehouseFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.type) params.set("type", filters.type);
  if (filters.branch_id) params.set("branch_id", filters.branch_id);
  if (filters.is_active !== undefined) {
    params.set("is_active", filters.is_active ? "1" : "0");
  }
  if (filters.trashed) params.set("trashed", filters.trashed);
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

export const warehouseService = {
  // --- Query ---

  list: (shopSlug: string, filters: WarehouseFilters = {}) =>
    apiFetch<PaginatedResponse<Warehouse>>(
      `${warehouseUrl(shopSlug)}?${toParams({ per_page: 25, ...filters })}`
    ),

  // Lightweight endpoint for dropdowns: returns active warehouses in a flat
  // array (no pagination envelope). Use this instead of `list` whenever you
  // just need id+name pairs for a select.
  lookup: (shopSlug: string) => apiFetch<{ data: Warehouse[] }>(warehouseUrl(shopSlug, "/lookup")),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: Warehouse }>(warehouseUrl(shopSlug, `/${id}`)),

  // --- Mutation ---

  create: (shopSlug: string, data: WarehouseCreateInput) =>
    apiFetch<{ data: Warehouse }>(warehouseUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: WarehouseUpdateInput) =>
    apiFetch<{ data: Warehouse }>(warehouseUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(warehouseUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: Warehouse }>(warehouseUrl(shopSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleActive: (shopSlug: string, id: string) =>
    apiFetch<{ data: Warehouse }>(warehouseUrl(shopSlug, `/${id}/toggle-active`), {
      method: "POST",
    }),

  updateSettings: (shopSlug: string, id: string, data: WarehouseSettingsInput) =>
    apiFetch<{ data: Warehouse }>(warehouseUrl(shopSlug, `/${id}/settings`), {
      method: "PATCH",
      body: JSON.stringify(data),
    }),
};
