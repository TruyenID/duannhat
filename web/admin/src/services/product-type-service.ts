/**
 * ProductType Service — pure TypeScript, no React dependency.
 *
 * Wraps the HQ product-types endpoints documented at
 *   /docs/hq → tag "ProductTypes"
 *   /api/v1/hq/{brandSlug}/product-types/...
 *
 * Used by hooks in src/hooks/api/use-product-types.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { ProductTypeProductForm } from "@/types/models/enum/ProductTypeProductForm";

// =========================================================================
//  Types
// =========================================================================

export interface ProductTypeTranslation {
  name: string | null;
  description: string | null;
}

export interface ProductType {
  id: string;
  organization_id: string;
  brand_id: string;
  code: string;
  name: string;
  description: string | null;
  product_form: ProductTypeProductForm;
  has_recipe: boolean;
  is_inventory_tracked: boolean;
  icon: string | null;
  is_active: boolean;
  translations?: {
    ja?: ProductTypeTranslation;
    en?: ProductTypeTranslation;
    vi?: ProductTypeTranslation;
  };
  products_count?: number;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface ProductTypeLookupItem {
  id: string;
  code: string;
  name: string;
  product_form: ProductTypeProductForm;
  has_recipe: boolean;
}

export interface ProductTypeFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  product_form?: ProductTypeProductForm;
  with_trashed?: boolean;
  sort?: string;
}

/**
 * Per-locale translation keys sent alongside the top-level scalar fields.
 * Matches the pattern used by allergen/category/product —
 * see docs/contributing/translatable-forms.md.
 */
export interface ProductTypeTranslationsInput {
  ja?: { name?: string | null; description?: string | null };
  en?: { name?: string | null; description?: string | null };
  vi?: { name?: string | null; description?: string | null };
}

export interface CreateProductTypeInput extends ProductTypeTranslationsInput {
  code: string;
  name: string;
  description?: string;
  product_form: ProductTypeProductForm;
  has_recipe: boolean;
  is_inventory_tracked: boolean;
  icon?: string;
  is_active: boolean;
}

export type UpdateProductTypeInput = Partial<CreateProductTypeInput>;

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/product-types${path}`;
}

function toParams(filters: ProductTypeFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.product_form) params.set("product_form", filters.product_form);
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const productTypeService = {
  // --- Query ---

  list: (brandSlug: string, filters: ProductTypeFilters = {}) =>
    apiFetch<PaginatedResponse<ProductType>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: ProductType }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: ProductTypeLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  // --- Mutation ---

  create: (brandSlug: string, data: CreateProductTypeInput) =>
    apiFetch<{ data: ProductType }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateProductTypeInput) =>
    apiFetch<{ data: ProductType }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: ProductType }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleStatus: (brandSlug: string, id: string) =>
    apiFetch<{ data: ProductType }>(brandUrl(brandSlug, `/${id}/toggle-status`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),
};
