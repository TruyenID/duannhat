/**
 * Stock Transfer Service — pure TypeScript, no React dependency.
 *
 * Wraps the shop-scoped stock-transfer endpoints at
 * `/api/v1/shops/{shopSlug}/stock-transfers/...`.
 *
 * Workflow:
 *   draft ─submit─▶ pending ─approve─▶ approved ─(stock-out)─▶ in_transit
 *      │              │                   │                        │
 *      └──cancel──────┴───────────────────┴───(receive)──▶ completed
 *
 * `receive` finalises the transfer by writing the stock-in transaction at
 * the destination warehouse. `cancel` is available until `completed`.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { StockTransfer } from "@/types/models/StockTransfer";
import type { StockTransferItem } from "@/types/models/StockTransferItem";
import { StockTransferStatus } from "@/types/models/enum/StockTransferStatus";

export type { StockTransfer, StockTransferItem };
export { StockTransferStatus };

export interface StockTransferFilters {
  page?: number;
  per_page?: number;
  // Backend ANDs `source_warehouse_id` and `destination_warehouse_id`. The
  // list page therefore exposes them as two separate filters (source + dest)
  // rather than the single "warehouse" dropdown the legacy Inertia page used.
  source_warehouse_id?: string;
  destination_warehouse_id?: string;
  status?: StockTransferStatus | StockTransferStatus[];
  date_from?: string;
  date_to?: string;
  search?: string;
  sort?: string;
}

export interface StockTransferItemInput {
  product_sku_id?: string | null;
  material_id?: string | null;
  quantity: number;
  unit?: string | null;
  note?: string | null;
}

export interface StockTransferCreateInput {
  source_warehouse_id: string;
  destination_warehouse_id: string;
  reference?: string | null;
  note?: string | null;
  transfer_date?: string | null;
  items: StockTransferItemInput[];
}

export interface StockTransferUpdateInput {
  reference?: string | null;
  note?: string | null;
  items?: StockTransferItemInput[];
}

function toTransferItemPayload(item: StockTransferItemInput): Record<string, unknown> {
  const { quantity, ...rest } = item;
  return { ...rest, sent_quantity: quantity };
}

function transferUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/stock-transfers${path}`;
}

function toListParams(filters: StockTransferFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.source_warehouse_id) {
    params.set("source_warehouse_id", filters.source_warehouse_id);
  }
  if (filters.destination_warehouse_id) {
    params.set("destination_warehouse_id", filters.destination_warehouse_id);
  }
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

export const stockTransferService = {
  list: (shopSlug: string, filters: StockTransferFilters = {}) =>
    apiFetch<PaginatedResponse<StockTransfer>>(`${transferUrl(shopSlug)}?${toListParams(filters)}`),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransfer }>(transferUrl(shopSlug, `/${id}`)),

  create: (shopSlug: string, data: StockTransferCreateInput) =>
    apiFetch<{ data: StockTransfer }>(transferUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify({ ...data, items: data.items.map(toTransferItemPayload) }),
    }),

  update: (shopSlug: string, id: string, data: StockTransferUpdateInput) =>
    apiFetch<{ data: StockTransfer }>(transferUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify({
        ...data,
        items: data.items ? data.items.map(toTransferItemPayload) : undefined,
      }),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(transferUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  // --- Workflow ---

  submit: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransfer }>(transferUrl(shopSlug, `/${id}/submit`), {
      method: "POST",
    }),

  approve: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransfer }>(transferUrl(shopSlug, `/${id}/approve`), {
      method: "POST",
    }),

  receive: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransfer }>(transferUrl(shopSlug, `/${id}/receive`), {
      method: "POST",
    }),

  cancel: (shopSlug: string, id: string) =>
    apiFetch<{ data: StockTransfer }>(transferUrl(shopSlug, `/${id}/cancel`), {
      method: "POST",
    }),
};
