/**
 * Void Reason Service — plan-051 (#1149). Pure TypeScript, no React
 * dependency; all HTTP goes through apiFetch. Used by hooks in
 * src/hooks/api/use-void-reasons.ts.
 *
 * Brand-scoped master of the reasons staff pick when voiding an order item.
 * `stock_effect` drives the inventory compensation for lines that were
 * already deducted (restock = adjustment back in, waste = consumed for real,
 * none = the dish was still served). index/store/update only — deactivation
 * is `update {is_active: false}`; historical order lines reference reasons
 * by id, so there is NO delete endpoint.
 */

import { apiFetch } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export type VoidStockEffect = "waste" | "restock" | "none";

export const VoidStockEffectValues: VoidStockEffect[] = ["restock", "waste", "none"];

export interface VoidReason {
  id: string;
  organization_id: string;
  brand_id: string;
  /** Localized label (Accept-Language resolved server-side). */
  label: string;
  translations?: {
    ja?: { label?: string | null };
    en?: { label?: string | null };
    vi?: { label?: string | null };
  };
  stock_effect: VoidStockEffect;
  requires_note: boolean;
  is_active: boolean;
  sort_order: number;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

/**
 * Built-in starter reason the backend suggests while the brand has ZERO
 * rows. Creation hints only — never persisted until the admin clicks
 * "create from suggestion".
 */
export interface VoidReasonSuggestion {
  label: { ja: string; en: string; vi: string };
  stock_effect: VoidStockEffect;
  requires_note: boolean;
  is_builtin_suggestion: true;
}

export interface VoidReasonListResponse {
  data: VoidReason[];
  /** Present only when the brand has zero rows. */
  suggestions?: VoidReasonSuggestion[];
}

/** Per-locale translation keys sent alongside the top-level scalar label. */
export interface VoidReasonTranslationsInput {
  ja?: { label?: string | null };
  en?: { label?: string | null };
  vi?: { label?: string | null };
}

export interface CreateVoidReasonInput extends VoidReasonTranslationsInput {
  label?: string;
  stock_effect: VoidStockEffect;
  requires_note?: boolean;
  is_active?: boolean;
  sort_order?: number;
}

export interface UpdateVoidReasonInput extends VoidReasonTranslationsInput {
  label?: string;
  stock_effect?: VoidStockEffect;
  requires_note?: boolean;
  is_active?: boolean;
  sort_order?: number;
}

// =========================================================================
//  Service
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/void-reasons${path}`;
}

export const voidReasonService = {
  // --- Query (read) ---

  list: (brandSlug: string) => apiFetch<VoidReasonListResponse>(brandUrl(brandSlug)),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateVoidReasonInput) =>
    apiFetch<{ data: VoidReason }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateVoidReasonInput) =>
    apiFetch<{ data: VoidReason }>(brandUrl(brandSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify(data),
    }),

  /** Soft deactivate/reactivate — there is no delete endpoint by design. */
  toggleStatus: (brandSlug: string, id: string, currentIsActive: boolean) =>
    apiFetch<{ data: VoidReason }>(brandUrl(brandSlug, `/${id}`), {
      method: "PATCH",
      body: JSON.stringify({ is_active: !currentIsActive }),
    }),
};
