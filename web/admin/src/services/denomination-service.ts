/**
 * Denomination Service — per-shop cash denominations admin CRUD.
 *
 *   GET    /api/v1/shops/{shopSlug}/denominations?currency=JPY
 *   POST   /api/v1/shops/{shopSlug}/denominations
 *   PATCH  /api/v1/shops/{shopSlug}/denominations/{id}
 *   DELETE /api/v1/shops/{shopSlug}/denominations/{id}
 *
 * Read endpoint returns global rows (organization_id NULL, system-seeded,
 * read-only) PLUS org-scoped rows for this shop's org. Mutate endpoints
 * only touch org-scoped rows.
 */

import { apiFetch } from "@/lib/api";

export interface Denomination {
  id: string;
  organization_id: string | null;
  currency_code: string;
  value: number | string;
  kind: "note" | "coin";
  label: string | null;
  sort_order: number;
  is_active: boolean;
}

export interface CreateDenominationInput {
  currency_code: string;
  value: number;
  kind: "note" | "coin";
  label?: string | null;
  sort_order?: number;
  is_active?: boolean;
}

export type UpdateDenominationInput = Partial<
  Pick<Denomination, "value" | "kind" | "label" | "sort_order" | "is_active">
>;

const base = (shopSlug: string) => `/api/v1/shops/${shopSlug}/denominations`;

export const denominationService = {
  list: (shopSlug: string, currency?: string) => {
    const q = currency ? `?currency=${encodeURIComponent(currency)}` : "";
    return apiFetch<{ data: Denomination[] }>(`${base(shopSlug)}${q}`);
  },

  create: (shopSlug: string, data: CreateDenominationInput) =>
    apiFetch<{ data: Denomination }>(base(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateDenominationInput) =>
    apiFetch<{ data: Denomination }>(`${base(shopSlug)}/${id}`, {
      method: "PATCH",
      body: JSON.stringify(data),
    }),

  remove: (shopSlug: string, id: string) =>
    apiFetch<{ data: null }>(`${base(shopSlug)}/${id}`, {
      method: "DELETE",
    }),
};
