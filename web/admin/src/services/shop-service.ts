/**
 * Shop Service — pure TypeScript, no React dependency.
 *
 * "Shop" in this app == Branch in the backend domain (a brand can have many
 * branches; provisioning is owned by the SSO Console). HQ does NOT have a
 * dedicated /hq/{brandSlug}/shops endpoint yet, so this service composes the
 * existing endpoints:
 *
 *   list   → GET /api/v1/me/shops?brand_slug={brandSlug}
 *   detail → GET /api/v1/shops/{shopSlug}        (resolved by slug)
 *
 * The React-Query layer lives in src/hooks/api/use-shops.ts.
 */

import { apiFetch } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

/**
 * #936 — image slots the shop upload endpoint accepts. `banner` is the legacy
 * single banner (`img_branches`), kept as the fallback for viewports whose
 * per-breakpoint banner is unset.
 */
export type ShopImageType =
  | "logo"
  | "banner"
  | "banner_desktop"
  | "banner_tablet"
  | "banner_mobile";

/** Row shape returned by /api/v1/me/shops (paginated). */
export interface ShopListItem {
  id: string;
  name: string;
  slug: string;
  is_active: boolean;
  deleted_at: string | null;
  updated_at: string | null;
  brand_name: string | null;
}

/** Per-day entry for weekly_hours map. */
export interface WeeklyHoursDay {
  open?: string;
  close?: string;
  closed?: boolean;
}

export type WeeklyHoursMap = Partial<
  Record<"mon" | "tue" | "wed" | "thu" | "fri" | "sat" | "sun", WeeklyHoursDay>
>;

/** Detail shape returned by /api/v1/shops/{shopSlug}. */
export interface ShopDetail {
  id: string;
  slug: string;
  name: string;
  code: string | null;
  timezone: string | null;
  is_headquarters: boolean;
  console_brand_id: string | null;
  address: string | null;
  phone: string | null;
  /** Storefront logo URL (rendered by customer-web). */
  logo: string | null;
  /** Storefront banner URL — `img_branches` column (rendered by customer-web). */
  img_branches: string | null;
  /** #936 — per-breakpoint banners. Null = fall back down the chain. */
  banner_desktop: string | null;
  banner_tablet: string | null;
  banner_mobile: string | null;
  seat_capacity: number | null;
  business_hours: string | null;
  weekly_hours: WeeklyHoursMap | null;
  /** #890 — HQ brand switch: may this shop edit HQ-origin tables? */
  allow_shop_edit_hq_tables?: boolean;
}

export interface ShopFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  with_trashed?: boolean;
}

export interface CreateShopInput {
  name: string;
  slug: string;
  timezone?: string;
  currency?: string;
  locale?: string;
  address?: string | null;
  phone?: string | null;
  logo?: string | null;
  img_branches?: string | null;
  /** #936 — per-breakpoint banners (desktop ≥1024 / tablet ≥768 / mobile <768). */
  banner_desktop?: string | null;
  banner_tablet?: string | null;
  banner_mobile?: string | null;
  seat_capacity?: number | null;
  business_hours?: string | null;
  weekly_hours?: WeeklyHoursMap | null;
}

export interface UpdateShopInput {
  name?: string;
  slug?: string;
  timezone?: string | null;
  currency?: string | null;
  locale?: string | null;
  address?: string | null;
  phone?: string | null;
  logo?: string | null;
  img_branches?: string | null;
  /** #936 — per-breakpoint banners (desktop ≥1024 / tablet ≥768 / mobile <768). */
  banner_desktop?: string | null;
  banner_tablet?: string | null;
  banner_mobile?: string | null;
  seat_capacity?: number | null;
  business_hours?: string | null;
  weekly_hours?: WeeklyHoursMap | null;
}

interface PaginatedShopResponse {
  data: ShopListItem[];
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
  };
}

interface ShopDetailResponse {
  data: ShopDetail;
}

// =========================================================================
//  Service
// =========================================================================

function buildQueryString(params: Record<string, unknown>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === "") continue;
    search.set(key, String(value));
  }
  const qs = search.toString();
  return qs ? `?${qs}` : "";
}

export const shopService = {
  /**
   * List shops belonging to the brand. Calls /me/shops with brand_slug filter
   * so results are scoped to the active brand AND to what the current user
   * can actually access.
   */
  async list(brandSlug: string, filters: ShopFilters = {}): Promise<PaginatedShopResponse> {
    const qs = buildQueryString({
      brand_slug: brandSlug,
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search,
      is_active: filters.is_active,
      with_trashed: filters.with_trashed ? true : undefined,
    });
    return apiFetch<PaginatedShopResponse>(`/api/v1/me/shops${qs}`);
  },

  /**
   * Fetch a single shop by slug. The backend middleware enforces
   * org-membership, so a 404 means "not found" and a 403 means "wrong org".
   */
  async getBySlug(shopSlug: string): Promise<ShopDetail> {
    const response = await apiFetch<ShopDetailResponse>(`/api/v1/shops/${shopSlug}`);
    return response.data;
  },

  /**
   * Soft-delete a single shop. Console-first via the existing destroy() endpoint.
   * DELETE /api/v1/hq/{brandSlug}/shops/{shopId}
   */
  async delete(brandSlug: string, shopId: string): Promise<void> {
    await apiFetch<void>(`/api/v1/hq/${brandSlug}/shops/${shopId}`, { method: "DELETE" });
  },

  /**
   * Bulk soft-delete shops (local-only, no Console call).
   * POST /api/v1/hq/{brandSlug}/shops/bulk-delete
   */
  async bulkDelete(
    brandSlug: string,
    ids: string[]
  ): Promise<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }> {
    return apiFetch(`/api/v1/hq/${brandSlug}/shops/bulk-delete`, {
      method: "POST",
      body: JSON.stringify({ ids }),
    });
  },

  /**
   * Toggle is_active flag for a shop.
   * POST /api/v1/hq/{brandSlug}/shops/{shopId}/toggle-status
   */
  async toggleStatus(
    brandSlug: string,
    shopId: string
  ): Promise<{ id: string; is_active: boolean }> {
    const response = await apiFetch<{ data: { id: string; is_active: boolean } }>(
      `/api/v1/hq/${brandSlug}/shops/${shopId}/toggle-status`,
      { method: "POST" }
    );
    return response.data;
  },

  /**
   * Restore a soft-deleted shop.
   * POST /api/v1/hq/{brandSlug}/shops/{shopId}/restore
   */
  async restore(brandSlug: string, shopId: string): Promise<{ id: string; is_active: boolean }> {
    const response = await apiFetch<{ data: { id: string; is_active: boolean } }>(
      `/api/v1/hq/${brandSlug}/shops/${shopId}/restore`,
      { method: "POST" }
    );
    return response.data;
  },

  /**
   * Upload a shop logo or banner image. Returns the stored public URL, which
   * is then persisted onto the shop via create()/update() as `logo` /
   * `img_branches`. Decoupled from a specific shop so it works in both the
   * create dialog (no shop id yet) and the edit dialog.
   * POST /api/v1/hq/{brandSlug}/shops/upload-image
   */
  async uploadImage(
    brandSlug: string,
    type: ShopImageType,
    file: File
  ): Promise<string> {
    const body = new FormData();
    body.append("type", type);
    body.append("file", file);
    const response = await apiFetch<{ data: { url: string } }>(
      `/api/v1/hq/${brandSlug}/shops/upload-image`,
      { method: "POST", body }
    );
    return response.data.url;
  },

  /**
   * Create a new shop (branch) under the given brand.
   * POST /api/v1/hq/{brandSlug}/shops
   */
  async create(brandSlug: string, data: CreateShopInput): Promise<ShopListItem> {
    const response = await apiFetch<{ data: ShopListItem }>(`/api/v1/hq/${brandSlug}/shops`, {
      method: "POST",
      body: JSON.stringify(data),
    });
    return response.data;
  },

  /**
   * Update a shop (branch) under the given brand.
   * PUT /api/v1/hq/{brandSlug}/shops/{shopId}
   */
  async update(brandSlug: string, shopId: string, data: UpdateShopInput): Promise<ShopDetail> {
    const response = await apiFetch<{ data: ShopDetail }>(
      `/api/v1/hq/${brandSlug}/shops/${shopId}`,
      {
        method: "PUT",
        body: JSON.stringify(data),
      }
    );
    return response.data;
  },
};
