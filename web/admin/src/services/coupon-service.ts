/**
 * Coupon Service — HQ CRUD + shop apply/release endpoints (plan-019).
 *
 * URL convention:
 *   HQ:       /api/v1/hq/{brandSlug}/coupons
 *   Shop:     /api/v1/shops/{shopSlug}/orders/{orderId}/apply-coupon
 *             /api/v1/shops/{shopSlug}/orders/{orderId}/coupon (DELETE)
 *   Customer: /api/v1/customer/coupons/preview
 *
 * Types extend the omnify-generated `Coupon` interface but stay flexible to
 * accommodate aggregate / computed fields surfaced by the controller resource
 * (`computed_status`, `times_used`, branch pivot summary).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { Coupon as CouponBase } from "@/types/models/Coupon";
import type { CouponRedemption } from "@/types/models/CouponRedemption";
import type { CouponDiscountType } from "@/types/models/enum/CouponDiscountType";
import type { CouponStatus } from "@/types/models/enum/CouponStatus";

// =========================================================================
//  Types
// =========================================================================

/** Status surfaced to the UI — combines stored status + derived states. */
export type CouponComputedStatus =
  | "draft"
  | "paused"
  | "scheduled"
  | "active"
  | "expired"
  | "exhausted";

export interface Coupon extends CouponBase {
  computed_status?: CouponComputedStatus;
  applicable_branch_ids?: string[];
}

export interface CouponFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: CouponComputedStatus | "all";
  branch_id?: string;
  discount_type?: CouponDiscountType;
  valid_at?: string;
  with_trashed?: boolean;
}

export interface CreateCouponInput {
  code: string;
  name: string;
  description?: string | null;
  discount_type: CouponDiscountType;
  discount_value: number;
  max_discount_cap?: number | null;
  min_order_subtotal?: number;
  usage_limit_total?: number | null;
  usage_limit_per_customer?: number;
  valid_from: string;
  valid_until: string;
  status?: CouponStatus;
  applicable_branch_ids?: string[];
  ja?: { name?: string; description?: string | null };
  en?: { name?: string; description?: string | null };
  vi?: { name?: string; description?: string | null };
}

export type UpdateCouponInput = Partial<CreateCouponInput>;

export interface RedemptionListItem extends CouponRedemption {
  customer_name?: string | null;
  order_code?: string | null;
}

export interface ApplyCouponInput {
  code: string;
  customer_id?: string | null;
}

export interface CouponPreviewInput {
  code: string;
  brand_id: string;
  branch_id: string;
  subtotal: number;
  customer_id?: string | null;
}

export interface CouponPreviewResponse {
  is_valid: boolean;
  discount_applied_amount?: number;
  error_code?: string;
  meta?: Record<string, unknown>;
}

// =========================================================================
//  URL / param helpers
// =========================================================================

function hqUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/coupons${path}`;
}

function toParams(filters: CouponFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.status && filters.status !== "all") params.set("status", filters.status);
  if (filters.branch_id) params.set("branch_id", filters.branch_id);
  if (filters.discount_type) params.set("discount_type", filters.discount_type);
  if (filters.valid_at) params.set("valid_at", filters.valid_at);
  if (filters.with_trashed) params.set("with_trashed", "1");
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const couponService = {
  // --- HQ scope ---

  list: (brandSlug: string, filters: CouponFilters = {}) =>
    apiFetch<PaginatedResponse<Coupon>>(
      `${hqUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Coupon }>(hqUrl(brandSlug, `/${id}`)),

  redemptions: (brandSlug: string, id: string, page = 1) =>
    apiFetch<PaginatedResponse<RedemptionListItem>>(
      `${hqUrl(brandSlug, `/${id}/redemptions`)}?page=${page}`
    ),

  create: (brandSlug: string, data: CreateCouponInput) =>
    apiFetch<{ data: Coupon }>(hqUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateCouponInput) =>
    apiFetch<{ data: Coupon }>(hqUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(hqUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Coupon }>(hqUrl(brandSlug, `/${id}/restore`), { method: "POST" }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      hqUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  pause: (brandSlug: string, id: string) =>
    apiFetch<{ data: Coupon }>(hqUrl(brandSlug, `/${id}/pause`), { method: "POST" }),

  resume: (brandSlug: string, id: string) =>
    apiFetch<{ data: Coupon }>(hqUrl(brandSlug, `/${id}/resume`), { method: "POST" }),

  // --- Shop scope (POS apply / release) ---

  apply: (shopSlug: string, orderId: string, input: ApplyCouponInput) =>
    apiFetch<{ data: unknown }>(`/api/v1/shops/${shopSlug}/orders/${orderId}/apply-coupon`, {
      method: "POST",
      body: JSON.stringify(input),
    }),

  release: (shopSlug: string, orderId: string) =>
    apiFetch<{ data: unknown }>(`/api/v1/shops/${shopSlug}/orders/${orderId}/coupon`, {
      method: "DELETE",
    }),

  // --- Customer scope (preview) ---

  preview: (input: CouponPreviewInput) =>
    apiFetch<CouponPreviewResponse>(`/api/v1/customer/coupons/preview`, {
      method: "POST",
      body: JSON.stringify(input),
    }),
};
