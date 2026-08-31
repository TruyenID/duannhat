/**
 * Shop Floating Section Service — pure TypeScript, no React dependency.
 *
 * All API calls for the shop's own floating-section clones.
 * URL convention: /api/v1/shops/{shopSlug}/floating-sections/...
 *
 * The React-Query layer lives in src/hooks/api/use-shop-floating-sections.ts.
 * Mirrors floating-section-service.ts, minus create/delete/add-products/
 * reorder — the shop only manages the clone HQ made for it (toggle + price
 * override per SKU, view + toggle + time-override on its own schedule).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { FloatingSection, FloatingSectionFilters } from "@/types/models/FloatingSection";
import type { FloatingSectionProduct } from "@/types/models/FloatingSectionProduct";
import type { FloatingSectionProductSku } from "@/types/models/FloatingSectionProductSku";
import type { FloatingSectionSchedule } from "@/types/models/FloatingSectionSchedule";

// =========================================================================
//  Helpers
// =========================================================================

function baseUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/floating-sections${path}`;
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

export const shopFloatingSectionService = {
  list: (shopSlug: string, filters: FloatingSectionFilters = {}) =>
    apiFetch<PaginatedResponse<FloatingSection>>(
      `${baseUrl(shopSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(shopSlug, `/${id}`)),

  sync: (shopSlug: string, id: string) =>
    apiFetch<{ data: FloatingSection }>(baseUrl(shopSlug, `/${id}/sync`), { method: "POST" }),

  toggleProduct: (shopSlug: string, sectionId: string, productId: string) =>
    apiFetch<{ data: FloatingSectionProduct }>(
      baseUrl(shopSlug, `/${sectionId}/products/${productId}/toggle`),
      { method: "POST" }
    ),

  toggleSku: (shopSlug: string, sectionId: string, productId: string, skuId: string) =>
    apiFetch<{ data: FloatingSectionProductSku }>(
      baseUrl(shopSlug, `/${sectionId}/products/${productId}/skus/${skuId}/toggle`),
      { method: "POST" }
    ),

  overrideSkuPrice: (
    shopSlug: string,
    sectionId: string,
    productId: string,
    skuId: string,
    sellingPrice: number
  ) =>
    apiFetch<{ data: FloatingSectionProductSku }>(
      baseUrl(shopSlug, `/${sectionId}/products/${productId}/skus/${skuId}/price`),
      { method: "PATCH", body: JSON.stringify({ selling_price: sellingPrice }) }
    ),

  resetSkuPrice: (shopSlug: string, sectionId: string, productId: string, skuId: string) =>
    apiFetch<{ data: FloatingSectionProductSku }>(
      baseUrl(shopSlug, `/${sectionId}/products/${productId}/skus/${skuId}/price/reset`),
      { method: "POST" }
    ),

  // --- Schedules (view + toggle + time-override — HQ owns create/delete) ---

  listSchedules: (shopSlug: string, sectionId: string) =>
    apiFetch<{ data: FloatingSectionSchedule[] }>(baseUrl(shopSlug, `/${sectionId}/schedules`)),

  toggleSchedule: (shopSlug: string, sectionId: string, scheduleId: string) =>
    apiFetch<{ data: FloatingSectionSchedule }>(
      baseUrl(shopSlug, `/${sectionId}/schedules/${scheduleId}/toggle`),
      { method: "POST" }
    ),

  overrideScheduleTime: (
    shopSlug: string,
    sectionId: string,
    scheduleId: string,
    data: { start_time?: string; end_time?: string; days_of_week?: number }
  ) =>
    apiFetch<{ data: FloatingSectionSchedule }>(
      baseUrl(shopSlug, `/${sectionId}/schedules/${scheduleId}/override`),
      { method: "PUT", body: JSON.stringify(data) }
    ),

  resetScheduleTimeOverride: (shopSlug: string, sectionId: string, scheduleId: string) =>
    apiFetch<{ data: FloatingSectionSchedule }>(
      baseUrl(shopSlug, `/${sectionId}/schedules/${scheduleId}/override`),
      { method: "DELETE" }
    ),
};
