/**
 * Till Tender Category Service — per-shop admin CRUD for the buckets
 * (Thẻ / QR / Tiền điện tử / shop-defined) that group till_tender_types
 * on the shift-close screen.
 *
 *   GET    /api/v1/shops/{shopSlug}/tender-categories
 *   POST   /api/v1/shops/{shopSlug}/tender-categories
 *   PATCH  /api/v1/shops/{shopSlug}/tender-categories/{id}
 *   DELETE /api/v1/shops/{shopSlug}/tender-categories/{id}
 *
 * Read returns org-wide system rows (`is_system=true`, branch_id=null —
 * read-only) overlaid with shop-scoped rows. Mutate only affects
 * shop-scoped rows; system rows return 403.
 *
 * `key` is the slug stored on `till_tender_types.category`. Lowercase
 * [a-z0-9_] only. Backend rejects creating a tender-type whose
 * `category` doesn't exist in this list.
 */

import { apiFetch } from "@/lib/api";

export interface TillTenderCategory {
  id: string;
  organization_id: string;
  branch_id: string | null;
  key: string;
  name: string;
  sort_order: number;
  is_active: boolean;
  is_system: boolean;
}

export interface CreateTillTenderCategoryInput {
  key: string;
  name: string;
  sort_order?: number;
}

export type UpdateTillTenderCategoryInput = Partial<
  Pick<TillTenderCategory, "name" | "sort_order" | "is_active">
>;

const base = (shopSlug: string) => `/api/v1/shops/${shopSlug}/tender-categories`;

export const tillTenderCategoryService = {
  list: (shopSlug: string) => apiFetch<{ data: TillTenderCategory[] }>(base(shopSlug)),

  create: (shopSlug: string, data: CreateTillTenderCategoryInput) =>
    apiFetch<{ data: TillTenderCategory }>(base(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateTillTenderCategoryInput) =>
    apiFetch<{ data: TillTenderCategory }>(`${base(shopSlug)}/${id}`, {
      method: "PATCH",
      body: JSON.stringify(data),
    }),

  remove: (shopSlug: string, id: string) =>
    apiFetch<{ data: null }>(`${base(shopSlug)}/${id}`, {
      method: "DELETE",
    }),
};
