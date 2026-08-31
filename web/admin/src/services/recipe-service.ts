/**
 * Recipe Service — pure TypeScript, no React dependency.
 *
 * All API calls for the Recipe domain.
 * URL convention: /api/v1/hq/{brandSlug}/recipes/...
 *
 * Mirrors the shape of material-service.ts; the React-Query layer lives in
 * src/hooks/api/use-recipes.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { ApprovalStatus } from "@/types/models/enum/ApprovalStatus";

// =========================================================================
//  Types
// =========================================================================

export type IngredientType = "variant" | "material";

/** Minimal shape of the actor returned in approval metadata. */
export interface RecipeApprovalActor {
  id: string;
  name?: string | null;
  email?: string | null;
}

export interface RecipeIngredient {
  type: IngredientType;
  /** Present when type === "variant" — references a product SKU id. */
  variant_id?: string | null;
  /** Present when type === "material" — references a material id. */
  material_id?: string | null;
  /** Optional unit id (only meaningful for variants with multiple units). */
  unit_id?: string | null;
  /**
   * Free-text unit string (e.g. `g`, `ml`, `pcs`). Plan-024 UX-3 — the
   * recipe stores the literal unit alongside `unit_id` so downstream
   * material-consumption transactions emit a sensible default instead of
   * the historic hard-coded `"piece"` fallback. Falls back to the linked
   * material's `yield_unit` when blank.
   */
  unit?: string | null;
  quantity: number | string;
}

export interface Recipe {
  id: string;
  organization_id: string;
  brand_id: string;
  sku: string | null;
  name: string;
  description: string | null;
  material_id: string | null;
  output_quantity: number | string;
  output_unit: string | null;
  ingredients: RecipeIngredient[] | null;
  preparation_time: number | null;
  instructions: string | null;
  is_active: boolean;
  /** Plan-022 (yield variance) — % drift allowed at batch complete time. */
  yield_variance_tolerance_pct?: number | string | null;
  translations?: {
    ja?: { name?: string | null; description?: string | null; instructions?: string | null };
    en?: { name?: string | null; description?: string | null; instructions?: string | null };
    vi?: { name?: string | null; description?: string | null; instructions?: string | null };
  };
  skus?: {
    id: string;
    sku: string | null;
    name: string | null;
    product_id?: string | null;
    product?: { id: string; name: string } | null;
  }[];
  // Approval workflow (Phase 5 / plan-003).
  approval_status?: ApprovalStatus;
  approved_by?: RecipeApprovalActor | null;
  approved_by_id?: string | null;
  approved_at?: string | null;
  rejected_by?: RecipeApprovalActor | null;
  rejected_by_id?: string | null;
  rejected_at?: string | null;
  rejection_reason?: string | null;
  /** Denormalized allergen-id rollup computed from material ingredients. */
  allergen_rollup?: string[] | null;
  allergen_rollup_updated_at?: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface RecipeLookupItem {
  id: string;
  name: string;
  sku: string | null;
}

export interface RecipeFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  /** Filter to recipes whose output material is this id (material detail link). */
  material_id?: string | null;
  with_trashed?: boolean;
  sort?: string;
}

// Per-locale overrides for the translatable `name`, `description` and
// `instructions` fields. Mirrors the shape that omnify-generated zod schemas
// expect (see #53). Backend support tracked in godx-jp/godx-tempo-backend#7 —
// until that ships these keys are sent but ignored.
export interface RecipeTranslationsInput {
  ja?: { name?: string; description?: string; instructions?: string };
  en?: { name?: string; description?: string; instructions?: string };
  vi?: { name?: string; description?: string; instructions?: string };
}

export interface CreateRecipeInput extends RecipeTranslationsInput {
  brand_id: string;
  name: string;
  description?: string | null;
  /**
   * Plan-022 T18 — output material the recipe produces. Required by BE for
   * approval; nullable on draft so a user can save a work-in-progress recipe
   * before picking the output. When the recipe is first approved BE will
   * auto-promote a raw output material to produced (sets yield_unit).
   */
  material_id?: string | null;
  output_quantity: number | string;
  output_unit?: string | null;
  ingredients: RecipeIngredient[];
  instructions?: string | null;
  is_active?: boolean;
  /** Plan-022 (yield variance) — % drift allowed at batch complete time. */
  yield_variance_tolerance_pct?: number | string;
  /**
   * Optional Product-SKU output side. When present, RecipeService sets
   * ProductSku.recipe_id for each id (and clears any SKU that previously
   * pointed at this recipe but isn't in the list). Empty array = detach all.
   * Omit the key entirely to leave existing SKU links alone.
   */
  sku_ids?: string[];
}

export interface UpdateRecipeInput extends RecipeTranslationsInput {
  name?: string;
  description?: string | null;
  material_id?: string | null;
  output_quantity?: number | string;
  output_unit?: string | null;
  ingredients?: RecipeIngredient[];
  instructions?: string | null;
  is_active?: boolean;
  yield_variance_tolerance_pct?: number | string;
  sku_ids?: string[];
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/recipes${path}`;
}

function toParams(filters: RecipeFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.material_id) params.set("material_id", filters.material_id);
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const recipeService = {
  // --- Query ---

  list: (brandSlug: string, filters: RecipeFilters = {}) =>
    apiFetch<PaginatedResponse<Recipe>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Recipe }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: RecipeLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  // --- Mutation ---

  create: (brandSlug: string, data: CreateRecipeInput) =>
    apiFetch<{ data: Recipe }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateRecipeInput) =>
    apiFetch<{ data: Recipe }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Recipe }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  // --- Approval workflow (Phase 5 / plan-003) ---

  submitForApproval: (brandSlug: string, id: string) =>
    apiFetch<{ data: Recipe }>(brandUrl(brandSlug, `/${id}/submit-for-approval`), {
      method: "POST",
    }),

  approve: (brandSlug: string, id: string) =>
    apiFetch<{ data: Recipe }>(brandUrl(brandSlug, `/${id}/approve`), {
      method: "POST",
    }),

  reject: (brandSlug: string, id: string, rejection_reason: string) =>
    apiFetch<{ data: Recipe }>(brandUrl(brandSlug, `/${id}/reject`), {
      method: "POST",
      body: JSON.stringify({ rejection_reason }),
    }),
};
