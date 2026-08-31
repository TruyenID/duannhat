/**
 * Zone Template Service — pure TypeScript, no React dependency.
 *
 * Contains all API calls for the HQ ZoneTemplate domain (issue #890 —
 * brand-scoped default zones a shop can pull down).
 * Used by hooks in src/hooks/api/use-zone-templates.ts.
 *
 * URL convention: /api/v1/hq/{brandSlug}/zone-templates/...
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  CreateZoneTemplateInput,
  UpdateZoneTemplateInput,
  ZoneTemplateFilters,
  ZoneTemplateResource,
} from "@/types/hq-tables";

// =========================================================================
//  Helpers
// =========================================================================

function zoneTemplateUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/zone-templates${path}`;
}

function toParams(filters: ZoneTemplateFilters): string {
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

export const zoneTemplateService = {
  // --- Query (read) ---

  list: (brandSlug: string, filters: ZoneTemplateFilters = {}) =>
    apiFetch<PaginatedResponse<ZoneTemplateResource>>(
      `${zoneTemplateUrl(brandSlug)}?${toParams({ per_page: 50, ...filters })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: ZoneTemplateResource }>(zoneTemplateUrl(brandSlug, `/${id}`)),

  lookup: (brandSlug: string) =>
    apiFetch<{ data: Array<Pick<ZoneTemplateResource, "id" | "code" | "name" | "display_order">> }>(
      zoneTemplateUrl(brandSlug, "/lookup")
    ),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateZoneTemplateInput) =>
    apiFetch<{ data: ZoneTemplateResource }>(zoneTemplateUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateZoneTemplateInput) =>
    apiFetch<{ data: ZoneTemplateResource }>(zoneTemplateUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(zoneTemplateUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: ZoneTemplateResource }>(zoneTemplateUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleActive: (brandSlug: string, id: string) =>
    apiFetch<{ data: ZoneTemplateResource }>(zoneTemplateUrl(brandSlug, `/${id}/toggle-active`), {
      method: "POST",
    }),
};
