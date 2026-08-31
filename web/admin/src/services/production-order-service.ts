/**
 * Production Order Service — pure TypeScript, no React dependency.
 *
 * Wraps `/api/v1/shops/{shopSlug}/production-orders/...`.
 *
 * Workflow:
 *   draft ─submit─▶ pending ─approve─▶ approved ─start─▶ in_progress ─complete─▶ completed
 *     │                │                  │                   │
 *     └──cancel────────┴──────────────────┴───────────────────┘
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { ProductionOrder } from "@/types/models/ProductionOrder";
import type { ProductionOrderItem } from "@/types/models/ProductionOrderItem";
import { ProductionOrderStatus } from "@/types/models/enum/ProductionOrderStatus";

export type { ProductionOrder, ProductionOrderItem };
export { ProductionOrderStatus };

export interface ProductionOrderFilters {
  page?: number;
  per_page?: number;
  warehouse_id?: string;
  output_variant_id?: string;
  status?: ProductionOrderStatus;
  date_from?: string;
  date_to?: string;
  search?: string;
  sort?: string;
}

export interface ProductionOrderCreateInput {
  warehouse_id: string;
  output_variant_id: string;
  planned_quantity: number;
  /**
   * Output unit for the produced SKU. Required by the BE store request.
   * Forms typically seed this from the SKU's recipe.output_unit, falling
   * back to free-text entry when the SKU has no linked recipe.
   */
  output_unit: string;
  note?: string | null;
}

export interface ProductionOrderUpdateInput {
  planned_quantity?: number;
  note?: string | null;
}

export interface ProductionOrderCompleteInput {
  actual_quantity: number;
}

function orderUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/production-orders${path}`;
}

function toListParams(filters: ProductionOrderFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
  if (filters.output_variant_id) {
    params.set("output_variant_id", filters.output_variant_id);
  }
  if (filters.status) params.set("status", filters.status);
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

export const productionOrderService = {
  // --- Query ---

  list: (shopSlug: string, filters: ProductionOrderFilters = {}) =>
    apiFetch<PaginatedResponse<ProductionOrder>>(`${orderUrl(shopSlug)}?${toListParams(filters)}`),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug, `/${id}`)),

  // --- Mutation ---

  create: (shopSlug: string, data: ProductionOrderCreateInput) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: ProductionOrderUpdateInput) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(orderUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  // --- Workflow ---

  submit: (shopSlug: string, id: string) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug, `/${id}/submit`), {
      method: "POST",
    }),

  approve: (shopSlug: string, id: string) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug, `/${id}/approve`), {
      method: "POST",
    }),

  start: (shopSlug: string, id: string) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug, `/${id}/start`), {
      method: "POST",
    }),

  complete: (shopSlug: string, id: string, data: ProductionOrderCompleteInput) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug, `/${id}/complete`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  cancel: (shopSlug: string, id: string) =>
    apiFetch<{ data: ProductionOrder }>(orderUrl(shopSlug, `/${id}/cancel`), {
      method: "POST",
    }),
};
