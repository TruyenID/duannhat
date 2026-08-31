/**
 * Catalog Lookup Service — shop-scoped lightweight lists of SKUs and
 * materials for <Combobox>/<Select> usage. Filtered by the shop's brand
 * server-side, so callers never need to pass brand_id.
 *
 * Endpoints:
 *   GET /api/v1/shops/{shopSlug}/product-skus/lookup → { data: ProductSkuLookup[] }
 *   GET /api/v1/shops/{shopSlug}/materials/lookup   → { data: MaterialLookup[] }
 */

import { apiFetch } from "@/lib/api";
import type { MaterialUnitOption } from "@/services/material-service";

export type { MaterialUnitOption };

export interface ProductSkuLookup {
  id: string;
  name: string | null;
  sku: string | null;
  product_id: string;
  /**
   * Parent product summary, returned by the shop lookup endpoint so that
   * pickers can show "Phở Bò — Large (PHO-BO-L)" instead of just the
   * variant label "Large". Optional because some consumers / mocks may
   * not include it.
   */
  product?: { id: string; name: string | null } | null;
  /**
   * Recipe summary — `output_unit` seeds the ProductionOrder form's
   * output_unit field, and `output_quantity` shows the operator how many
   * units a single batch run yields.
   */
  recipe?: {
    id: string;
    output_quantity: number | string | null;
    output_unit: string | null;
  } | null;
  recipe_multiplier?: number | string | null;
  inventory_mode?: string | null;
}

export interface MaterialLookup {
  id: string;
  name: string;
  sku: string | null;
  /** Recipe yield per single batch run (multiplier = 1). */
  yield_quantity: number | string;
  /** Unit of the yield (e.g. "L", "kg"). NULL if material has no recipe. */
  yield_unit: string | null;
  /** Number of recipe components (0 = no recipe; cannot be batched). */
  components_count: number;
  /**
   * Registered units (base first). The lot-receive form offers these as a
   * picker; the entered unit must be one of them (backend-enforced).
   */
  units: MaterialUnitOption[];
}

export interface RecipeLookup {
  id: string;
  name: string;
  sku: string | null;
  material_id: string;
  output_quantity: number | string;
  output_unit: string | null;
  updated_at: string | null;
}

export const catalogLookupService = {
  productSkus: (shopSlug: string) =>
    apiFetch<{ data: ProductSkuLookup[] }>(`/api/v1/shops/${shopSlug}/product-skus/lookup`),

  materials: (shopSlug: string) =>
    apiFetch<{ data: MaterialLookup[] }>(`/api/v1/shops/${shopSlug}/materials/lookup`),

  recipes: (shopSlug: string, materialId?: string) => {
    const qs = materialId ? `?material_id=${encodeURIComponent(materialId)}` : "";
    return apiFetch<{ data: RecipeLookup[] }>(`/api/v1/shops/${shopSlug}/recipes/lookup${qs}`);
  },
};
