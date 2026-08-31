/**
 * Zone Service — pure TypeScript, no React dependency.
 *
 * Contains all API calls for the Shop Zone domain.
 * Used by hooks in src/hooks/api/use-zones.ts.
 *
 * URL convention: /api/v1/shops/{shopSlug}/zones/...
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  CreateZoneInput,
  UpdateZoneInput,
  ZoneFilters,
  ZoneLookupItem,
  ZoneResource,
} from "@/types/shop";

// =========================================================================
//  Helpers
// =========================================================================

function zoneUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/zones${path}`;
}

function toParams(filters: ZoneFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.is_active !== undefined) {
    params.set("is_active", filters.is_active ? "1" : "0");
  }
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const zoneService = {
  // --- Query (read) ---

  list: (shopSlug: string, filters: ZoneFilters = {}) =>
    apiFetch<PaginatedResponse<ZoneResource>>(
      `${zoneUrl(shopSlug)}?${toParams({ per_page: 50, ...filters })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: ZoneResource }>(zoneUrl(shopSlug, `/${id}`)),

  lookup: (shopSlug: string) => apiFetch<{ data: ZoneLookupItem[] }>(zoneUrl(shopSlug, "/lookup")),

  // --- Mutation (write) ---

  create: (shopSlug: string, data: CreateZoneInput) =>
    apiFetch<{ data: ZoneResource }>(zoneUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateZoneInput) =>
    apiFetch<{ data: ZoneResource }>(zoneUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(zoneUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: ZoneResource }>(zoneUrl(shopSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleActive: (shopSlug: string, id: string) =>
    apiFetch<{ data: ZoneResource }>(zoneUrl(shopSlug, `/${id}/toggle-active`), { method: "POST" }),
};
