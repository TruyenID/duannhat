/**
 * Stock Count Service — pure TypeScript, no React dependency.
 *
 * Wraps the shop-scoped stock-count endpoints at
 * `/api/v1/shops/{shopSlug}/stock-counts/...`.
 *
 * Workflow:
 *   draft ─start─▶ in_progress ─submit─▶ pending_approval ─approve─▶ approved
 *     │                │                        │
 *     └──cancel────────┴────────────────────────┘
 *
 * Scope determines whether the session counts everything in the warehouse
 * (`full`) or a hand-picked subset of SKUs / materials (`partial`). A
 * partial count exposes `add-items`; a full count snapshots on creation
 * and only `update-items` is available thereafter.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { StockCount } from "@/types/models/StockCount";
import type { StockCountItem } from "@/types/models/StockCountItem";
import { StockCountStatus } from "@/types/models/enum/StockCountStatus";
import { StockCountScope } from "@/types/models/enum/StockCountScope";

export type { StockCount, StockCountItem };
export { StockCountStatus, StockCountScope };

export interface StockCountFilters {
  page?: number;
  per_page?: number;
  warehouse_id?: string;
  status?: StockCountStatus | StockCountStatus[];
  scope?: StockCountScope;
  date_from?: string;
  date_to?: string;
  search?: string;
  sort?: string;
}

export interface StockCountCreateInput {
  warehouse_id: string;
  scope: StockCountScope;
  note?: string | null;
}

export interface StockCountItemInput {
  id?: string;
  product_sku_id?: string | null;
  material_id?: string | null;
  counted_quantity?: number | null;
  note?: string | null;
}

export interface StockCountUpdateItemsInput {
  items: StockCountItemInput[];
}

export interface StockCountAddItemsInput {
  items: Array<{
    product_sku_id?: string | null;
    material_id?: string | null;
  }>;
}

function countUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/stock-counts${path}`;
}

function toListParams(filters: StockCountFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
  if (filters.status) {
    const value = Array.isArray(filters.status) ? filters.status.join(",") : filters.status;
    if (value) params.set("status", value);
  }
  if (filters.scope) params.set("scope", filters.scope);
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

export const stockCountService = {
  list: (shopSlug: string, filters: StockCountFilters = {}) =>
    apiFetch<PaginatedResponse<StockCount>>(`${countUrl(shopSlug)}?${toListParams(filters)}`),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}`)),

  create: (shopSlug: string, data: StockCountCreateInput) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: { note?: string | null }) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(countUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  // --- Workflow ---

  start: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}/start`), {
      method: "POST",
    }),

  addItems: (shopSlug: string, id: string, data: StockCountAddItemsInput) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}/add-items`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  updateItems: (shopSlug: string, id: string, data: StockCountUpdateItemsInput) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}/update-items`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  submit: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}/submit`), {
      method: "POST",
    }),

  approve: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}/approve`), {
      method: "POST",
    }),

  cancel: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockCount }>(countUrl(shopSlug, `/${id}/cancel`), {
      method: "POST",
    }),
};
