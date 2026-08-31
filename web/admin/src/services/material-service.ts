/**
 * Material Service — pure TypeScript, no React dependency.
 *
 * All API calls for the Material domain.
 * URL convention: /api/v1/hq/{brandSlug}/materials/...
 *
 * Mirrors the shape of category-service.ts; the React-Query layer lives in
 * src/hooks/api/use-materials.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { Allergen } from "@/services/allergen-service";

// =========================================================================
//  Types
// =========================================================================

/**
 * @deprecated Plan-022 T4 — Material.components has been retired. The BOM
 *   lives on Recipe.ingredients. These types are kept temporarily so any
 *   in-flight branch that imports them still compiles; new code must use
 *   {@link RecipeIngredient} from `@/services/recipe-service` instead.
 */
export type MaterialComponentType = "variant" | "material";

/** @deprecated see MaterialComponentType. */
export interface MaterialComponent {
  type: MaterialComponentType;
  variant_id?: string | null;
  material_id?: string | null;
  unit_id?: string | null;
  qty: number | string;
}

export interface MaterialTranslations {
  ja?: { name?: string; description?: string };
  en?: { name?: string; description?: string };
  vi?: { name?: string; description?: string };
}

export interface Material {
  id: string;
  organization_id: string;
  brand_id: string;
  sku: string | null;
  name: string;
  description: string | null;
  /**
   * Per-locale name/description, serialized by Astrotomic on show(). Absent
   * on list endpoints (for perf) so edit pages should fall back to the
   * top-level `name`/`description` when this is missing.
   */
  translations?: MaterialTranslations;
  yield_quantity: number | string;
  yield_unit: string | null;
  calculated_cost: number | string;
  is_active: boolean;
  /** Plan-022 T5 — production lot expiry shelf-life policy (days). */
  shelf_life_days: number | null;
  /** Plan-017 Tier 1.C — per-material override of [7, 3, 1] day windows. */
  expiry_alert_thresholds: number[] | null;
  /** Plan-017 Tier 1.D — temperature CCP config. */
  requires_temperature_check: boolean;
  temperature_min: number | string | null;
  temperature_max: number | string | null;
  /** Plan-017 MOD-1 — withCount aggregates (only set on list responses). */
  active_lots_count?: number | null;
  expiring_soon_lots_count?: number | null;
  /** Plan-022 T4.4 — withCount of active recipes; replaces legacy components_count. */
  active_recipes_count?: number | null;
  /**
   * Plan-022 — computed flag from the BE. 'raw' = no yield_unit, no BOM
   * (purchased from supplier); 'produced' = has yield_unit + BOM (output
   * of a production batch). Persisted truth lives in `yield_unit`, this is
   * just the friendly label.
   */
  kind?: "raw" | "produced";
  /** Allergens linked via material_allergens pivot (Phase 8 / plan-003). */
  allergens?: Allergen[];
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

/** A material's registered measurement unit (base first in lookup payloads). */
export interface MaterialUnitOption {
  unit: string;
  ratio: number | string;
  is_base: boolean;
}

export interface MaterialLookupItem {
  id: string;
  name: string;
  sku: string | null;
  yield_quantity: number | string | null;
  yield_unit: string | null;
  calculated_cost?: number | string;
  /** Registered units (base first) — backs unit pickers on recipe/lot forms. */
  units?: MaterialUnitOption[];
}

export interface VariantUnit {
  id: string;
  unit: string;
  ratio: number | string;
}

/**
 * Dropdown entry for product SKUs (variants) used when building material
 * components. Shape intentionally minimal — only what the form needs.
 */
export interface VariantLookupItem {
  id: string;
  name: string;
  sku: string | null;
  cost_price: number | string;
  product?: { id: string; name: string } | null;
  units?: VariantUnit[];
}

export interface MaterialFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
  /** Plan-022 — filter by raw (NVL nhập từ supplier) or produced (BTP từ batch). */
  kind?: "raw" | "produced";
}

// Per-locale overrides for the translatable `name` and `description` fields.
// Mirrors the shape that omnify-generated zod schemas expect (see #53).
// Backend support tracked in godx-jp/godx-tempo-backend issue for material
// translations table — until that ships these keys are sent but ignored.
export interface MaterialTranslationsInput {
  ja?: { name?: string; description?: string };
  en?: { name?: string; description?: string };
  vi?: { name?: string; description?: string };
}

export interface CreateMaterialInput extends MaterialTranslationsInput {
  brand_id: string;
  name: string;
  description?: string | null;
  yield_quantity: number | string;
  yield_unit?: string | null;
  calculated_cost: number | string;
  is_active?: boolean;
  /** Plan-022 T5 — production-lot shelf-life policy. */
  shelf_life_days?: number | null;
  /** Allergen ids to sync on the material_allergens pivot. */
  allergen_ids?: string[];
  /** Plan-017 Tier 1.D — temperature CCP config. */
  requires_temperature_check?: boolean;
  temperature_min?: number | null;
  temperature_max?: number | null;
}

export interface UpdateMaterialInput extends MaterialTranslationsInput {
  name?: string;
  description?: string | null;
  yield_quantity?: number | string;
  yield_unit?: string | null;
  calculated_cost?: number | string;
  is_active?: boolean;
  /** Plan-022 T5 — production-lot shelf-life policy. */
  shelf_life_days?: number | null;
  /** Allergen ids to sync on the material_allergens pivot. */
  allergen_ids?: string[];
  /** Plan-017 Tier 1.C — JSON array of days. NULL = use default [7, 3, 1]. */
  expiry_alert_thresholds?: number[] | null;
  /** Plan-017 Tier 1.D — temperature CCP config. */
  requires_temperature_check?: boolean;
  temperature_min?: number | null;
  temperature_max?: number | null;
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/materials${path}`;
}

function toParams(filters: MaterialFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.kind) params.set("kind", filters.kind);
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const materialService = {
  // --- Query ---

  list: (brandSlug: string, filters: MaterialFilters = {}) =>
    apiFetch<PaginatedResponse<Material>>(
      `${brandUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Material }>(brandUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: MaterialLookupItem[] }>(brandUrl(brandSlug, "/lookup")),

  /**
   * Variant (product SKU) dropdown — used by the components builder in the
   * create/edit form. The backend returns the minimal shape needed to compute
   * cost (cost_price + unit ratios).
   */
  variants: (brandSlug: string) =>
    apiFetch<{ data: VariantLookupItem[] }>(`/api/v1/hq/${brandSlug}/skus/lookup`),

  // --- Mutation ---

  create: (brandSlug: string, data: CreateMaterialInput) =>
    apiFetch<{ data: Material }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateMaterialInput) =>
    apiFetch<{ data: Material }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Material }>(brandUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      brandUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),
};
