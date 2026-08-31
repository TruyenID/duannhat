/**
 * Recall Service — pure TypeScript, no React dependency.
 *
 * Plan-017 Tier 1.A backend lives at /api/v1/hq/{brandSlug}/recalls.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

export type RecallStatus = "draft" | "active" | "completed" | "cancelled";
export type RecallScopeType = "lot" | "supplier_lot" | "material";

export interface Recall {
  id: string;
  organization_id: string;
  brand_id: string;
  recall_code: string;
  root_lot_id: string;
  scope_type: RecallScopeType;
  status: RecallStatus;
  reason: string;
  affected_lots_count: number;
  affected_orders_count: number;
  initiated_by_id: string | null;
  initiated_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  created_at: string;
  updated_at: string;
  root_lot?: { id: string; lot_code: string };
  brand?: { id: string; name: string };
}

export interface RecallPreview {
  root_lot: { id: string; lot_code: string; supplier_name: string | null };
  affected_lots_count: number;
  affected_orders_count: number;
  affected_lot_ids: string[];
  affected_order_ids: string[];
}

export interface InitiateRecallInput {
  root_lot_id: string;
  reason: string;
  scope_type?: RecallScopeType;
}

export interface RecallReport {
  recall_code: string;
  status: RecallStatus;
  reason: string;
  initiated_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  root_lot: {
    id: string;
    lot_code: string;
    supplier_name: string | null;
    supplier_lot_code: string | null;
    received_at: string;
    expiry_date: string | null;
  } | null;
  affected_lots: Array<Record<string, unknown>>;
  affected_orders: Array<Record<string, unknown>>;
  counts: { lots: number; orders: number };
}

function url(brandSlug: string, suffix = ""): string {
  return `/api/v1/hq/${brandSlug}/recalls${suffix}`;
}

export const recallService = {
  list: (
    brandSlug: string,
    filters: { status?: RecallStatus; search?: string; page?: number; per_page?: number } = {}
  ) => {
    const params = new URLSearchParams();
    if (filters.status) params.set("status", filters.status);
    if (filters.search) params.set("search", filters.search);
    if (filters.page) params.set("page", String(filters.page));
    if (filters.per_page) params.set("per_page", String(filters.per_page));
    return apiFetch<PaginatedResponse<Recall>>(`${url(brandSlug)}?${params.toString()}`);
  },

  getById: (brandSlug: string, id: string) => apiFetch<{ data: Recall }>(url(brandSlug, `/${id}`)),

  preview: (brandSlug: string, rootLotId: string) =>
    apiFetch<RecallPreview>(url(brandSlug, "/preview"), {
      method: "POST",
      body: JSON.stringify({ root_lot_id: rootLotId }),
    }),

  initiate: (brandSlug: string, input: InitiateRecallInput) =>
    apiFetch<{ data: Recall }>(url(brandSlug), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  complete: (brandSlug: string, id: string) =>
    apiFetch<{ data: Recall }>(url(brandSlug, `/${id}/complete`), {
      method: "POST",
    }),

  cancel: (brandSlug: string, id: string, cancellationReason: string) =>
    apiFetch<{ data: Recall }>(url(brandSlug, `/${id}/cancel`), {
      method: "POST",
      body: JSON.stringify({ cancellation_reason: cancellationReason }),
    }),

  report: (brandSlug: string, id: string) =>
    apiFetch<{ data: RecallReport }>(url(brandSlug, `/${id}/report`)),
};
