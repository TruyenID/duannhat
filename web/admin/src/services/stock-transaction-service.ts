/**
 * Stock Transaction Service — pure TypeScript, no React dependency.
 *
 * Wraps the shop-scoped stock-transaction endpoints at
 * `/api/v1/shops/{shopSlug}/stock-transactions/...`.
 *
 * Workflow:
 *   draft  ──submit──▶ pending ──approve──▶ completed
 *      │                 │
 *      └──cancel─┐       └──cancel──▶ cancelled
 *                ▼
 *            cancelled
 *
 * Stock movements are only written when a transaction reaches `completed`.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { StockTransaction } from "@/types/models/StockTransaction";
import type { StockTransactionItem } from "@/types/models/StockTransactionItem";
import { StockTransactionType } from "@/types/models/enum/StockTransactionType";
import { StockTransactionSubType } from "@/types/models/enum/StockTransactionSubType";
import { StockTransactionStatus } from "@/types/models/enum/StockTransactionStatus";

export type { StockTransaction, StockTransactionItem };
export { StockTransactionType, StockTransactionSubType, StockTransactionStatus };

export interface StockTransactionFilters {
  page?: number;
  per_page?: number;
  warehouse_id?: string;
  type?: StockTransactionType;
  sub_type?: StockTransactionSubType;
  // Backend accepts: a single value, a comma-separated list, or the `status[]`
  // array form. We pass a single string with comma separation to keep the
  // query-string compact.
  status?: StockTransactionStatus | StockTransactionStatus[];
  date_from?: string;
  date_to?: string;
  search?: string;
  sort?: string;
}

export interface StockTransactionItemInput {
  product_sku_id?: string | null;
  material_id?: string | null;
  quantity: number;
  unit?: string | null;
  unit_price?: number | null;
  note?: string | null;
}

export interface StockTransactionCreateInput {
  warehouse_id: string;
  type: StockTransactionType;
  sub_type?: StockTransactionSubType;
  reference?: string | null;
  note?: string | null;
  transaction_date?: string | null;
  items: StockTransactionItemInput[];
}

export interface StockTransactionUpdateInput {
  sub_type?: StockTransactionSubType;
  reference?: string | null;
  note?: string | null;
  items?: StockTransactionItemInput[];
}

export interface StockTransactionApproveInput {
  allow_negative?: boolean;
}

function txUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/stock-transactions${path}`;
}

function toListParams(filters: StockTransactionFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
  if (filters.type) params.set("type", filters.type);
  if (filters.sub_type) params.set("sub_type", filters.sub_type);
  if (filters.status) {
    const value = Array.isArray(filters.status) ? filters.status.join(",") : filters.status;
    if (value) params.set("status", value);
  }
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

export const stockTransactionService = {
  // --- Query (read) ---

  list: (shopSlug: string, filters: StockTransactionFilters = {}) =>
    apiFetch<PaginatedResponse<StockTransaction>>(`${txUrl(shopSlug)}?${toListParams(filters)}`),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransaction }>(txUrl(shopSlug, `/${id}`)),

  // --- Mutation (write) ---

  create: (shopSlug: string, data: StockTransactionCreateInput) =>
    apiFetch<{ data: StockTransaction }>(txUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: StockTransactionUpdateInput) =>
    apiFetch<{ data: StockTransaction }>(txUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(txUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  // --- Workflow ---

  submit: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransaction }>(txUrl(shopSlug, `/${id}/submit`), {
      method: "POST",
    }),

  approve: (shopSlug: string, id: string, data: StockTransactionApproveInput = {}) =>
    apiFetch<{ data: StockTransaction }>(txUrl(shopSlug, `/${id}/approve`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  cancel: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransaction }>(txUrl(shopSlug, `/${id}/cancel`), {
      method: "POST",
    }),
};
