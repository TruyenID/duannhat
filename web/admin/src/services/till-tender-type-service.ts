/**
 * Till Tender Type Service — per-shop admin CRUD for cashier-settlement
 * tender types.
 *
 *   GET    /api/v1/shops/{shopSlug}/tender-types
 *   POST   /api/v1/shops/{shopSlug}/tender-types
 *   PATCH  /api/v1/shops/{shopSlug}/tender-types/{id}
 *   DELETE /api/v1/shops/{shopSlug}/tender-types/{id}
 *
 * Read returns org-wide (branch_id=null, seeded by system, read-only)
 * PLUS shop-scoped (branch_id=current shop, editable) rows. Mutate only
 * affects shop-scoped rows; org-wide rows return 403 on mutation.
 *
 * Category is constrained to the 4 enum values pos-web reconciliation
 * groups by: cash / card / qr / emoney. Adding new categories requires
 * a backend change (the math depends on which category a tender lives in).
 */

import { apiFetch } from "@/lib/api";

/**
 * Category is now a CRUD entity (see till-tender-category-service). The
 * legacy literal union stays for the 4 system keys so call-sites that
 * branch on a known bucket keep type-safe; for shop-defined custom keys
 * (vouchers / crypto / …) we widen to `string` because we can't know them
 * at compile time.
 */
export type TenderCategory = "cash" | "card" | "qr" | "emoney" | (string & {});

export interface TillTenderType {
  id: string;
  organization_id: string;
  branch_id: string | null;
  tender_key: string;
  name: string;
  category: TenderCategory;
  parent_tender_key: string | null;
  currency_code: string;
  payment_method_code: string | null;
  is_expected_anchor: boolean;
  requires_terminal_total: boolean;
  sort_order: number;
  is_active: boolean;
}

export interface CreateTillTenderTypeInput {
  tender_key: string;
  name: string;
  category: TenderCategory;
  payment_method_code?: string | null;
  currency_code?: string;
  sort_order?: number;
  is_active?: boolean;
}

export type UpdateTillTenderTypeInput = Partial<
  Pick<
    TillTenderType,
    "name" | "category" | "payment_method_code" | "currency_code" | "sort_order" | "is_active"
  >
>;

const base = (shopSlug: string) => `/api/v1/shops/${shopSlug}/tender-types`;

export const tillTenderTypeService = {
  list: (shopSlug: string) => apiFetch<{ data: TillTenderType[] }>(base(shopSlug)),

  create: (shopSlug: string, data: CreateTillTenderTypeInput) =>
    apiFetch<{ data: TillTenderType }>(base(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateTillTenderTypeInput) =>
    apiFetch<{ data: TillTenderType }>(`${base(shopSlug)}/${id}`, {
      method: "PATCH",
      body: JSON.stringify(data),
    }),

  remove: (shopSlug: string, id: string) =>
    apiFetch<{ data: null }>(`${base(shopSlug)}/${id}`, {
      method: "DELETE",
    }),
};
