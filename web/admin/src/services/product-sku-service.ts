/**
 * Product SKU Service — pure TypeScript, no React dependency.
 *
 * URL convention:
 *   Nested under product (list / create / generate-combinations):
 *     GET    /api/v1/hq/{brandSlug}/products/{productId}/skus
 *     POST   /api/v1/hq/{brandSlug}/products/{productId}/skus
 *     POST   /api/v1/hq/{brandSlug}/products/{productId}/skus/generate-combinations
 *
 *   Standalone under /skus:
 *     GET    /api/v1/hq/{brandSlug}/skus
 *     GET    /api/v1/hq/{brandSlug}/skus/{skuId}
 *     PUT    /api/v1/hq/{brandSlug}/skus/{skuId}
 *     DELETE /api/v1/hq/{brandSlug}/skus/{skuId}
 *     POST   /api/v1/hq/{brandSlug}/skus/{skuId}/restore
 *     POST   /api/v1/hq/{brandSlug}/skus/{skuId}/toggle-status
 *     GET    /api/v1/hq/{brandSlug}/skus/{skuId}/check-usage
 *
 * Hooks live in src/hooks/api/use-product-skus.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { ProductImageFile } from "./product-image-service";
import type { ProductSku } from "./product-service";

export type { ProductSku };

export interface ProductSkuFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  product_id?: string;
  with_trashed?: boolean;
  sort?: string;
}

/**
 * Create payload — option_value*_id are only honoured on POST. Once an SKU
 * exists they become immutable (the update endpoint silently drops them).
 */
export interface CreateProductSkuInput {
  name?: string | null;
  sku?: string | null;
  option_value1_id?: string | null;
  option_value2_id?: string | null;
  option_value3_id?: string | null;
  recipe_id?: string | null;
  recipe_multiplier?: number | string;
  cost_price?: number | string;
  selling_price?: number | string;
  is_cost_override?: boolean;
  is_active?: boolean;
}

export interface UpdateProductSkuInput {
  name?: string | null;
  sku?: string | null;
  recipe_id?: string | null;
  recipe_multiplier?: number | string;
  cost_price?: number | string;
  is_cost_override?: boolean;
  selling_price?: number | string;
  is_active?: boolean;
  /**
   * Plan-024 — controls order-close stock deduction policy for this SKU.
   * - `made_to_order` (default): no stock-out, no recipe-based deduction.
   * - `track_stock`: emit a `sales` stock_out for the SKU AND a
   *   `sales_material_consumption` stock_out aggregated from the linked
   *   Recipe at order close.
   */
  inventory_mode?: "made_to_order" | "track_stock";
}

export interface SkuUsageItem {
  id: string;
  name: string;
  type: "material" | "menu" | string;
}

export interface GenerateCombinationsResult {
  data: ProductSku[];
  created_count: number;
}

function nestedUrl(brandSlug: string, productId: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/products/${productId}/skus${path}`;
}

function flatUrl(brandSlug: string, skuId: string = "", path: string = ""): string {
  const tail = skuId ? `/${skuId}${path}` : path;
  return `/api/v1/hq/${brandSlug}/skus${tail}`;
}

function toParams(filters: ProductSkuFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.product_id) params.set("product_id", filters.product_id);
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

export const productSkuService = {
  // --- Query ---

  listForProduct: (brandSlug: string, productId: string, filters: ProductSkuFilters = {}) =>
    apiFetch<PaginatedResponse<ProductSku>>(
      `${nestedUrl(brandSlug, productId)}?${toParams({ ...filters, per_page: filters.per_page ?? 100 })}`
    ),

  listAll: (brandSlug: string, filters: ProductSkuFilters = {}) =>
    apiFetch<PaginatedResponse<ProductSku>>(
      `${flatUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, skuId: string) =>
    apiFetch<{ data: ProductSku }>(flatUrl(brandSlug, skuId)),

  // --- Mutation ---

  create: (brandSlug: string, productId: string, data: CreateProductSkuInput) =>
    apiFetch<{ data: ProductSku }>(nestedUrl(brandSlug, productId), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, skuId: string, data: UpdateProductSkuInput) =>
    apiFetch<{ data: ProductSku }>(flatUrl(brandSlug, skuId), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, skuId: string) =>
    apiFetch<null>(flatUrl(brandSlug, skuId), { method: "DELETE" }),

  restore: (brandSlug: string, skuId: string) =>
    apiFetch<{ data: ProductSku }>(flatUrl(brandSlug, skuId, "/restore"), {
      method: "POST",
    }),

  toggleStatus: (brandSlug: string, skuId: string) =>
    apiFetch<{ data: ProductSku }>(flatUrl(brandSlug, skuId, "/toggle-status"), {
      method: "POST",
    }),

  checkUsage: (brandSlug: string, skuId: string) =>
    apiFetch<{ data: SkuUsageItem[] }>(flatUrl(brandSlug, skuId, "/check-usage")),

  generateCombinations: (brandSlug: string, productId: string) =>
    apiFetch<GenerateCombinationsResult>(
      nestedUrl(brandSlug, productId, "/generate-combinations"),
      { method: "POST" }
    ),

  /**
   * Full-replace sync of the SKU variant gallery.
   *
   * Sends the complete ordered list of File UUIDs. Backend attaches temp
   * files (permanent), updates sort_order by array position, and reverts
   * removed files back to TEMPORARY with a 24h expiry — within that window
   * they can be re-attached by including the id in another sync call.
   */
  syncImages: (
    brandSlug: string,
    skuId: string,
    fileIds: string[]
  ): Promise<{ data: ProductImageFile[] }> =>
    apiFetch<{ data: ProductImageFile[] }>(flatUrl(brandSlug, skuId, "/images/sync"), {
      method: "POST",
      body: JSON.stringify({ file_ids: fileIds }),
    }),
};
