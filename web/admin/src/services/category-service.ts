/**
 * Category Service — pure TypeScript, no React dependency.
 *
 * Contains all API calls for the Category domain.
 * Used by hooks in src/hooks/api/use-categories.ts.
 *
 * Pattern: each method maps 1:1 to a backend API endpoint.
 * URL convention: /api/v1/hq/{brandSlug}/categories/...
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

interface CategoryTranslation {
  name: string;
  description?: string | null;
}

export interface Category {
  id: string;
  organization_id: string;
  brand_id: string;
  sku: string | null;
  name: string;
  slug: string | null;
  description: string | null;
  image_url: string | null;
  is_active: boolean;
  parent_id: string | null;
  parent?: { id: string; name: string } | null;
  children_count?: number;
  products_count?: number;
  translations?: {
    ja?: CategoryTranslation;
    en?: CategoryTranslation;
    vi?: CategoryTranslation;
  };
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface CategoryLookupItem {
  id: string;
  name: string;
  sku: string | null;
  parent_id: string | null;
}

export interface CategoryFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  parent_id?: string | null;
  with_trashed?: boolean;
  sort?: string;
}

export interface CategoryTranslationsInput {
  ja?: { name?: string | null; description?: string | null };
  en?: { name?: string | null; description?: string | null };
  vi?: { name?: string | null; description?: string | null };
}

export interface CreateCategoryInput extends CategoryTranslationsInput {
  brand_id: string;
  name: string;
  sku?: string;
  slug?: string;
  description?: string;
  image_url?: string;
  is_active?: boolean;
  parent_id?: string | null;
}

export interface UpdateCategoryInput extends CategoryTranslationsInput {
  name?: string;
  sku?: string;
  slug?: string;
  description?: string;
  image_url?: string;
  is_active?: boolean;
  parent_id?: string | null;
}

export interface CategoryImportResult {
  success_count?: number;
  created_count?: number;
  imported?: number;
  skipped?: number;
  errors?: { row: number; message: string }[];
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/categories${path}`;
}

function toParams(filters: CategoryFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.parent_id !== undefined && filters.parent_id !== null) {
    params.set("parent_id", filters.parent_id);
  }
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const categoryService = {
  // --- Query (read) ---

  list: (brandSlug: string, filters: CategoryFilters = {}) =>
    apiFetch<PaginatedResponse<Category>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 100 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Category }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: CategoryLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateCategoryInput) =>
    apiFetch<{ data: Category }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateCategoryInput) =>
    apiFetch<{ data: Category }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Category }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  /**
   * #1074 — bulk-assign a tax type to every product in the category.
   * `taxTypeId = null` clears the per-product override so the products fall
   * back to inheritance (branch/brand default).
   */
  applyTaxType: (brandSlug: string, id: string, taxTypeId: string | null) =>
    apiFetch<{ data: { category_id: string; tax_type_id: string | null; updated: number } }>(
      brandUrl(brandSlug, `/${id}/apply-tax-type`),
      { method: "POST", body: JSON.stringify({ tax_type_id: taxTypeId }) }
    ),

  // --- Import / Export ---

  /**
   * Posts a CSV file using multipart/form-data. apiFetch detects FormData
   * and lets the browser set the multipart boundary automatically.
   */
  import: async (
    brandSlug: string,
    file: File,
    brand_id: string
  ): Promise<CategoryImportResult> => {
    const body = new FormData();
    body.append("file", file);
    body.append("brand_id", brand_id);

    const json = await apiFetch<{ data?: CategoryImportResult } | CategoryImportResult>(
      brandUrl(brandSlug, "/import"),
      { method: "POST", body }
    );
    return (json as { data?: CategoryImportResult }).data ?? (json as CategoryImportResult);
  },

  templateUrl: (brandSlug: string) => brandUrl(brandSlug, "/import/template"),

  /**
   * CSV export — fetches as blob via apiFetch (responseType: "blob") and
   * triggers a browser download. Returns the filename used.
   */
  exportCsv: async (brandSlug: string): Promise<string> => {
    const blob = await apiFetch(brandUrl(brandSlug, "/export"), {
      headers: { Accept: "text/csv" },
      responseType: "blob",
    });
    const filename = `categories-${brandSlug}-${new Date().toISOString().slice(0, 10)}.csv`;

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);

    return filename;
  },
};
