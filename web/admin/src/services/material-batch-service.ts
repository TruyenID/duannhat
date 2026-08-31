/**
 * Material Batch Service — pure TypeScript, no React dependency.
 *
 * Wraps `/api/v1/shops/{shopSlug}/material-batches/...`. Surfaced on the
 * admin-web sidebar under "Production → Batches" even though the URL
 * slug remains `material-batches` on the backend.
 *
 * Workflow:
 *   draft ─submit─▶ pending ─approve─▶ approved ─start─▶ in_progress ─complete─▶ completed
 *     │               │                  │                   │
 *     └──cancel───────┴──────────────────┴───────────────────┘
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { MaterialBatch } from "@/types/models/MaterialBatch";
import type { MaterialBatchItem } from "@/types/models/MaterialBatchItem";
import { MaterialBatchStatus } from "@/types/models/enum/MaterialBatchStatus";

export type { MaterialBatch, MaterialBatchItem };
export { MaterialBatchStatus };

export interface MaterialBatchFilters {
  page?: number;
  per_page?: number;
  warehouse_id?: string;
  material_id?: string;
  status?: MaterialBatchStatus;
  date_from?: string;
  date_to?: string;
  search?: string;
  sort?: string;
}

export interface MaterialBatchCreateInput {
  warehouse_id: string;
  material_id: string;
  /**
   * Plan-022 T3.5 — explicit Recipe snapshot. Optional on the wire (backend
   * falls back to the latest active+approved recipe for the material), but
   * the form sends it so the chosen revision is auditable.
   */
  recipe_id?: string;
  multiplier: number;
  /** Computed client-side as `material.yield_quantity * multiplier`. */
  planned_yield: number;
  /** Inherited from `recipe.output_unit` (fallback `material.yield_unit`). */
  yield_unit: string;
  note?: string | null;
}

export interface MaterialBatchUpdateInput {
  multiplier?: number;
  note?: string | null;
}

export interface MaterialBatchCompleteInput {
  actual_yield: number;
  expiry_date?: string | null;
  /**
   * Required (BE-enforced) when |actual - planned| / planned * 100 exceeds
   * the recipe's yield_variance_tolerance_pct. NULL within tolerance — BE
   * also clears it server-side to keep the audit column meaningful.
   */
  yield_variance_reason?: string | null;
}

function batchUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/material-batches${path}`;
}

function toListParams(filters: MaterialBatchFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
  if (filters.material_id) params.set("material_id", filters.material_id);
  if (filters.status) params.set("status", filters.status);
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

export const materialBatchService = {
  // --- Query ---

  list: (shopSlug: string, filters: MaterialBatchFilters = {}) =>
    apiFetch<PaginatedResponse<MaterialBatch>>(`${batchUrl(shopSlug)}?${toListParams(filters)}`),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug, `/${id}`)),

  // --- Mutation ---

  create: (shopSlug: string, data: MaterialBatchCreateInput) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: MaterialBatchUpdateInput) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(batchUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  // --- Workflow ---

  submit: (shopSlug: string, id: string) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug, `/${id}/submit`), {
      method: "POST",
    }),

  approve: (shopSlug: string, id: string) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug, `/${id}/approve`), {
      method: "POST",
    }),

  start: (shopSlug: string, id: string) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug, `/${id}/start`), {
      method: "POST",
    }),

  complete: (shopSlug: string, id: string, data: MaterialBatchCompleteInput) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug, `/${id}/complete`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  cancel: (shopSlug: string, id: string) =>
    apiFetch<{ data: MaterialBatch }>(batchUrl(shopSlug, `/${id}/cancel`), {
      method: "POST",
    }),

  costBreakdown: (shopSlug: string, id: string) =>
    apiFetch<{ data: BatchCostBreakdown | null }>(batchUrl(shopSlug, `/${id}/cost-breakdown`)),
};

// Plan-017 Tier 1.B — production batch cost decomposition.
export interface BatchCostBreakdownLine {
  lot_id: string;
  lot_code: string | null;
  supplier_name: string | null;
  supplier_lot_code: string | null;
  qty_consumed: number;
  unit: string | null;
  unit_cost: number | null;
  line_total: number | null;
}

export interface BatchCostBreakdown {
  currency: string | null;
  total_consumed_cost: number;
  yield_qty: number;
  output_unit_cost: number | null;
  lines: BatchCostBreakdownLine[];
}
