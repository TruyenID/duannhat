/**
 * Menu Promotion Service — Shop CRUD + HQ read-only cross-shop listing (plan-019).
 *
 * URL convention:
 *   Shop: /api/v1/shops/{shopSlug}/promotions
 *   HQ:   /api/v1/hq/{brandSlug}/promotions  (read-only)
 *
 * The shop scope is authoritative — shop-managers create / edit / toggle /
 * delete from their own shop. HQ surfaces an aggregate view across all
 * shops in the brand for reporting.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { MenuPromotion as MenuPromotionBase } from "@/types/models/MenuPromotion";
import type { MenuPromotionAppliesTo } from "@/types/models/enum/MenuPromotionAppliesTo";
import type { MenuPromotionStackingMode } from "@/types/models/enum/MenuPromotionStackingMode";

// =========================================================================
//  Types
// =========================================================================

export interface MenuPromotionReport {
  items_with_promotion_count: number;
  total_discount_applied: number;
  first_redeemed_at: string | null;
  last_redeemed_at: string | null;
}

export interface MenuPromotion extends MenuPromotionBase {
  computed_status?: "scheduled" | "active" | "inactive" | "expired";
  currently_active?: boolean;
  applicable_category_ids?: string[];
  applicable_product_ids?: string[];
  report?: MenuPromotionReport;
  branch_slug?: string;
  branch_name?: string;
}

export interface MenuPromotionFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  currently_active?: boolean;
  applies_to?: MenuPromotionAppliesTo;
  branch_id?: string;
  sort?: "total_discount_applied" | "created_at" | "name";
  sort_dir?: "asc" | "desc";
  with_trashed?: boolean;
}

export interface CreateMenuPromotionInput {
  name: string;
  description?: string | null;
  discount_percent: number;
  applies_to: MenuPromotionAppliesTo;
  applicable_category_ids?: string[];
  applicable_product_ids?: string[];
  daily_time_from?: string | null;
  daily_time_to?: string | null;
  weekdays?: number[] | null;
  valid_from: string;
  valid_until: string;
  stacking_mode: MenuPromotionStackingMode;
  is_active?: boolean;
  ja?: { name?: string; description?: string | null };
  en?: { name?: string; description?: string | null };
  vi?: { name?: string; description?: string | null };
}

export type UpdateMenuPromotionInput = Partial<CreateMenuPromotionInput>;

export interface OrderItemSummary {
  id: string;
  customer_order_id: string;
  order_code: string | null;
  product_name: string | null;
  original_unit_price: number | null;
  unit_price: number;
  applied_at: string;
}

// =========================================================================
//  URL / param helpers
// =========================================================================

function shopUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/promotions${path}`;
}

function hqUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/promotions${path}`;
}

function toParams(filters: MenuPromotionFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) params.set("is_active", filters.is_active ? "1" : "0");
  if (filters.currently_active) params.set("currently_active", "1");
  if (filters.applies_to) params.set("applies_to", filters.applies_to);
  if (filters.branch_id) params.set("branch_id", filters.branch_id);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.sort_dir) params.set("sort_dir", filters.sort_dir);
  if (filters.with_trashed) params.set("with_trashed", "1");
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const menuPromotionService = {
  // --- Shop scope ---

  list: (shopSlug: string, filters: MenuPromotionFilters = {}) =>
    apiFetch<PaginatedResponse<MenuPromotion>>(
      `${shopUrl(shopSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: MenuPromotion }>(shopUrl(shopSlug, `/${id}`)),

  recentItems: (shopSlug: string, id: string, limit = 50) =>
    apiFetch<{ data: OrderItemSummary[] }>(shopUrl(shopSlug, `/${id}/recent-items?limit=${limit}`)),

  create: (shopSlug: string, data: CreateMenuPromotionInput) =>
    apiFetch<{ data: MenuPromotion }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateMenuPromotionInput) =>
    apiFetch<{ data: MenuPromotion }>(shopUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(shopUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  toggle: (shopSlug: string, id: string) =>
    apiFetch<{ data: MenuPromotion }>(shopUrl(shopSlug, `/${id}/toggle`), { method: "POST" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: MenuPromotion }>(shopUrl(shopSlug, `/${id}/restore`), { method: "POST" }),

  bulkDelete: (shopSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      shopUrl(shopSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  // --- HQ scope (read-only cross-shop) ---

  hqList: (brandSlug: string, filters: MenuPromotionFilters = {}) =>
    apiFetch<PaginatedResponse<MenuPromotion>>(
      `${hqUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),
};
