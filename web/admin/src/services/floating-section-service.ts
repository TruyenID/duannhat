/**
 * Floating Section Service — pure TypeScript, no React dependency.
 *
 * All API calls for the FloatingSection domain.
 * URL convention: /api/v1/hq/{brandSlug}/floating-sections/...
 *
 * The React-Query layer lives in src/hooks/api/use-floating-sections.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  FloatingSection,
  FloatingSectionFilters,
  CreateFloatingSectionInput,
  UpdateFloatingSectionInput,
  AddFloatingSectionProductsInput,
  ReorderFloatingSectionProductsInput,
  CloneFloatingSectionToBranchInput,
} from "@/types/models/FloatingSection";
import type { FloatingSectionProduct } from "@/types/models/FloatingSectionProduct";
import type { FloatingSectionProductSku } from "@/types/models/FloatingSectionProductSku";

// =========================================================================
//  Helpers
// =========================================================================

function baseUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/floating-sections${path}`;
}

function toParams(filters: FloatingSectionFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const floatingSectionService = {
  // --- Query (read) ---

  list: (brandSlug: string, filters: FloatingSectionFilters = {}) =>
    apiFetch<PaginatedResponse<FloatingSection>>(
      `${baseUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(brandSlug, `/${id}`)),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateFloatingSectionInput) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateFloatingSectionInput) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(baseUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      baseUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  cloneToBranch: (brandSlug: string, id: string, data: CloneFloatingSectionToBranchInput) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(brandSlug, `/${id}/clone-to-branch`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  duplicate: (brandSlug: string, id: string) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(brandSlug, `/${id}/duplicate`), {
      method: "POST",
    }),

  // --- Products ---

  addProducts: (brandSlug: string, sectionId: string, data: AddFloatingSectionProductsInput) =>
    apiFetch<{ data: FloatingSectionProduct[] }>(baseUrl(brandSlug, `/${sectionId}/products`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  removeProduct: (brandSlug: string, sectionId: string, productId: string) =>
    apiFetch<null>(baseUrl(brandSlug, `/${sectionId}/products/${productId}`), {
      method: "DELETE",
    }),

  toggleProduct: (brandSlug: string, sectionId: string, productId: string) =>
    apiFetch<{ data: FloatingSectionProduct }>(
      baseUrl(brandSlug, `/${sectionId}/products/${productId}/toggle`),
      { method: "POST" }
    ),

  // Set (or clear) a floating-section item's tax-type override (null = inherit
  // from product). Mirrors menuService.updateProductTaxType.
  updateProductTaxType: (
    brandSlug: string,
    sectionId: string,
    productId: string,
    taxTypeId: string | null
  ) =>
    apiFetch<{ data: FloatingSectionProduct }>(
      baseUrl(brandSlug, `/${sectionId}/products/${productId}/tax-type`),
      { method: "PATCH", body: JSON.stringify({ tax_type_id: taxTypeId }) }
    ),

  toggleSku: (brandSlug: string, sectionId: string, productId: string, skuId: string) =>
    apiFetch<{ data: FloatingSectionProductSku }>(
      baseUrl(brandSlug, `/${sectionId}/products/${productId}/skus/${skuId}/toggle`),
      { method: "POST" }
    ),

  overrideSkuPrice: (
    brandSlug: string,
    sectionId: string,
    productId: string,
    skuId: string,
    sellingPrice: number
  ) =>
    apiFetch<{ data: FloatingSectionProductSku }>(
      baseUrl(brandSlug, `/${sectionId}/products/${productId}/skus/${skuId}/price`),
      { method: "PATCH", body: JSON.stringify({ selling_price: sellingPrice }) }
    ),

  resetSkuPrice: (brandSlug: string, sectionId: string, productId: string, skuId: string) =>
    apiFetch<{ data: FloatingSectionProductSku }>(
      baseUrl(brandSlug, `/${sectionId}/products/${productId}/skus/${skuId}/price/reset`),
      { method: "POST" }
    ),

  reorderProducts: (
    brandSlug: string,
    sectionId: string,
    data: ReorderFloatingSectionProductsInput
  ) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(brandSlug, `/${sectionId}/products/reorder`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),
};
