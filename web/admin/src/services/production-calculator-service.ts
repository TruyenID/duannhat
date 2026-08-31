/**
 * Production Calculator Service — read-only capacity planner.
 *
 * Exposes two user-facing operations:
 *   - `calculate`: per-variant max producible + bottleneck + shared
 *     ingredient warnings given a warehouse and a set of recipe variants.
 *   - `shortage`: reverse flow — given a target output per variant, list
 *     which ingredients fall short and by how much.
 */

import { apiFetch } from "@/lib/api";

export interface VariantWithRecipe {
  id: string;
  name: string | null;
  sku: string | null;
  product_id: string | null;
  recipe_multiplier: number;
  recipe: {
    id: string;
    output_quantity: number;
    output_unit: string | null;
  };
}

export interface IngredientCalculation {
  name: string;
  type: "variant" | "material" | "raw" | string;
  required_per_batch: number;
  available_stock: number | null;
  max_batches_from_this: number | null;
  unit: string | null;
  is_bottleneck: boolean;
}

export interface VariantCalculation {
  variant: {
    id: string;
    name: string | null;
    sku: string | null;
    recipe_multiplier: number;
  };
  recipe: { output_quantity: number; output_unit: string | null };
  max_producible: number;
  bottleneck_ingredient: string | null;
  ingredients: IngredientCalculation[];
}

export interface ProductCalculation {
  product: {
    id: string | null;
    name: string | null;
    sku: string | null;
  };
  variants: VariantCalculation[];
}

export interface SharedIngredient {
  ingredient_name: string;
  track_key: string;
  used_by_products: string[];
  total_available: number | null;
}

export interface CalculateResult {
  warehouse: { id: string; name: string };
  products: ProductCalculation[];
  shared_ingredients: SharedIngredient[];
}

export interface ShortageIngredient {
  name: string;
  type: string;
  required_total: number;
  available_stock: number | null;
  shortage: number;
  unit: string | null;
  is_sufficient: boolean | null;
}

export interface ShortageResult {
  desired_quantity: number;
  is_feasible: boolean;
  ingredients: ShortageIngredient[];
}

function url(shopSlug: string, path: string) {
  return `/api/v1/shops/${shopSlug}/production-calculator${path}`;
}

export const productionCalculatorService = {
  variants: (shopSlug: string) =>
    apiFetch<{ data: VariantWithRecipe[] }>(url(shopSlug, "/variants")),

  calculate: (shopSlug: string, payload: { warehouse_id: string; variant_ids: string[] }) =>
    apiFetch<{ data: CalculateResult }>(url(shopSlug, "/calculate"), {
      method: "POST",
      body: JSON.stringify(payload),
    }),

  shortage: (
    shopSlug: string,
    payload: {
      warehouse_id: string;
      variant_id: string;
      desired_quantity: number;
    }
  ) =>
    apiFetch<{ data: ShortageResult }>(url(shopSlug, "/shortage"), {
      method: "POST",
      body: JSON.stringify(payload),
    }),
};
