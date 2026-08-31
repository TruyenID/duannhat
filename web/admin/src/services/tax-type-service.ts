/**
 * TaxType Service — pure TypeScript, no React dependency.
 *
 * Wraps the HQ tax-types endpoints (plan-043):
 *   /api/v1/hq/{brandSlug}/tax-types/...
 *
 * Used by hooks in src/hooks/api/use-tax-types.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export interface TaxTypeTranslation {
  name: string | null;
}

/**
 * #1367 — trục phân loại thuế, TÁCH khỏi thuế suất.
 *
 * `rate` không nói được phân loại: 免税 và 非課税 đều 0% nhưng khác nhau ở quyền
 * khấu trừ thuế đầu vào và nằm ở hai chỗ khác nhau trên tờ khai.
 *
 * `null` = **chưa phân loại**, không phải giá trị thứ năm. Loại thuế tạo trước
 * #1367 mà có rate 0 thì không suy được là 非課税/不課税/免税 — chỉ người vận hành
 * mới biết, nên backend để trống thay vì đoán.
 */
export type TaxClassificationValue = "taxable" | "exempt" | "out_of_scope" | "zero_rated";

export interface TaxType {
  id: string;
  organization_id: string;
  brand_id: string;
  code: string;
  name: string;
  rate: number;
  classification: TaxClassificationValue | null;
  is_default: boolean;
  is_active: boolean;
  translations?: {
    ja?: TaxTypeTranslation;
    en?: TaxTypeTranslation;
    vi?: TaxTypeTranslation;
  };
  products_count?: number;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface TaxTypeLookupItem {
  id: string;
  code: string;
  name: string;
  rate: number;
  is_default: boolean;
}

export interface TaxTypeFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

/**
 * Per-locale translation keys sent alongside the top-level scalar fields.
 * Matches the pattern used by product-type/category/product —
 * see docs/contributing/translatable-forms.md.
 */
export interface TaxTypeTranslationsInput {
  ja?: { name?: string | null };
  en?: { name?: string | null };
  vi?: { name?: string | null };
}

export interface CreateTaxTypeInput extends TaxTypeTranslationsInput {
  code: string;
  name: string;
  rate: number;
  /** #1367 — `null` gửi lên là "chưa phân loại", hợp lệ. */
  classification: TaxClassificationValue | null;
  is_default: boolean;
  is_active: boolean;
}

export type UpdateTaxTypeInput = Partial<CreateTaxTypeInput>;

/** 409 payload shape returned when a delete hits a tax type still in use. */
/**
 * #1129 — returned on a rate change. An HQ rate edit is deliberately NOT
 * blocked (plan-043 Q6: per-line snapshots protect orders already created), but
 * it lands on EVERY branch of the brand at once, so a cashier shift can end up
 * spanning two rates. The backend names the branches that are mid-shift right
 * now, using the same predicate the shop-level 409 guards use.
 */
export interface TaxTypeUpdateMeta {
  rate_changed: boolean;
  open_shift_branches: Array<{ id: string; name: string | null }>;
}

export interface TaxTypeInUseMeta {
  products: number;
  menu_products: number;
  branch_defaults: number;
  /**
   * plan-043 audit fix 3.8 — present when the 409 comes from a DEACTIVATE
   * attempt on a type that is still the brand default (`is_default = 1`), not
   * from a delete. 1 = the type is the brand-level default; 0/absent otherwise.
   */
  brand_default?: number;
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/tax-types${path}`;
}

function toParams(filters: TaxTypeFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const taxTypeService = {
  // --- Query ---

  list: (brandSlug: string, filters: TaxTypeFilters = {}) =>
    apiFetch<PaginatedResponse<TaxType>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: TaxType }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: TaxTypeLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  // --- Mutation ---

  create: (brandSlug: string, data: CreateTaxTypeInput) =>
    apiFetch<{ data: TaxType }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateTaxTypeInput) =>
    apiFetch<{ data: TaxType; meta?: TaxTypeUpdateMeta }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: TaxType }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleStatus: (brandSlug: string, id: string) =>
    apiFetch<{ data: TaxType }>(brandUrl(brandSlug, `/${id}/toggle-status`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),
};
