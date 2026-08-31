/**
 * Allergen Service — pure TypeScript, no React dependency.
 *
 * Mirrors the shape of category-service.ts. All HTTP goes through apiFetch.
 * Used by hooks in src/hooks/api/use-allergens.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { AllergenJurisdiction } from "@/types/models/enum/AllergenJurisdiction";
import type { AllergenSeverity } from "@/types/models/enum/AllergenSeverity";

// =========================================================================
//  Types
// =========================================================================

export interface Allergen {
  id: string;
  organization_id: string;
  code: string;
  name: string;
  translations?: {
    ja?: { name?: string | null };
    en?: { name?: string | null };
    vi?: { name?: string | null };
  };
  jurisdiction: AllergenJurisdiction;
  severity: AllergenSeverity;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface AllergenFilters {
  page?: number;
  per_page?: number;
  search?: string;
  jurisdiction?: AllergenJurisdiction | "";
  severity?: AllergenSeverity | "";
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

/**
 * Per-locale translation keys sent alongside the top-level scalar field.
 * Mirrors the pattern used by category/product — see docs/contributing/translatable-forms.md.
 */
export interface AllergenTranslationsInput {
  ja?: { name?: string | null };
  en?: { name?: string | null };
  vi?: { name?: string | null };
}

export interface CreateAllergenInput extends AllergenTranslationsInput {
  code: string;
  name: string;
  jurisdiction: AllergenJurisdiction;
  severity: AllergenSeverity;
  is_active?: boolean;
}

export interface UpdateAllergenInput extends AllergenTranslationsInput {
  code?: string;
  name?: string;
  jurisdiction?: AllergenJurisdiction;
  severity?: AllergenSeverity;
  is_active?: boolean;
}

/** Body shape the backend returns for a 409 ALLERGEN_IN_USE delete attempt. */
export interface AllergenInUseError {
  code: "ALLERGEN_IN_USE";
  message?: string;
  used_by: { id: string; name: string }[];
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/allergens${path}`;
}

function toParams(filters: AllergenFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.jurisdiction) params.set("jurisdiction", filters.jurisdiction);
  if (filters.severity) params.set("severity", filters.severity);
  if (filters.is_active !== undefined) {
    params.set("is_active", filters.is_active ? "1" : "0");
  }
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const allergenService = {
  // --- Query (read) ---

  list: (brandSlug: string, filters: AllergenFilters = {}) =>
    apiFetch<PaginatedResponse<Allergen>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 50 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Allergen }>(brandUrl(brandSlug, `/${id}`)),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateAllergenInput) =>
    apiFetch<{ data: Allergen }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateAllergenInput) =>
    apiFetch<{ data: Allergen }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Allergen }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleStatus: (brandSlug: string, id: string, currentIsActive: boolean) =>
    apiFetch<{ data: Allergen }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify({ is_active: !currentIsActive }),
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),
};
