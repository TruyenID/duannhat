/**
 * Table Template Service — pure TypeScript, no React dependency.
 *
 * Contains all API calls for the HQ TableTemplate domain (issue #890 —
 * brand-scoped default tables a shop can pull down).
 * Used by hooks in src/hooks/api/use-table-templates.ts.
 *
 * URL convention: /api/v1/hq/{brandSlug}/table-templates/...
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  CreateTableTemplateInput,
  TableTemplateFilters,
  TableTemplateResource,
  UpdateTableTemplateInput,
} from "@/types/hq-tables";

// =========================================================================
//  Helpers
// =========================================================================

function tableTemplateUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/table-templates${path}`;
}

function toParams(filters: TableTemplateFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.zone_template_id) params.set("zone_template_id", filters.zone_template_id);
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

export const tableTemplateService = {
  // --- Query (read) ---

  list: (brandSlug: string, filters: TableTemplateFilters = {}) =>
    apiFetch<PaginatedResponse<TableTemplateResource>>(
      `${tableTemplateUrl(brandSlug)}?${toParams({ per_page: 100, ...filters })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: TableTemplateResource }>(tableTemplateUrl(brandSlug, `/${id}`)),

  // --- Mutation (write) ---

  create: (brandSlug: string, data: CreateTableTemplateInput) =>
    apiFetch<{ data: TableTemplateResource }>(tableTemplateUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateTableTemplateInput) =>
    apiFetch<{ data: TableTemplateResource }>(tableTemplateUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(tableTemplateUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: TableTemplateResource }>(tableTemplateUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleActive: (brandSlug: string, id: string) =>
    apiFetch<{ data: TableTemplateResource }>(tableTemplateUrl(brandSlug, `/${id}/toggle-active`), {
      method: "POST",
    }),
};
