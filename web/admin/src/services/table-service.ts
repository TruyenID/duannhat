/**
 * Table Service — pure TypeScript, no React dependency.
 *
 * Contains all API calls for the Shop Table domain.
 * Used by hooks in src/hooks/api/use-tables.ts.
 *
 * URL convention: /api/v1/shops/{shopSlug}/tables/...
 *
 * Note: there is NO backend QR image endpoint. The frontend renders the QR
 * client-side from `qr_token` (see components/shop/table-qr.tsx).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { TableDefaultsApplyResult, TableDefaultsPreview } from "@/types/hq-tables";
import type {
  ChangeTableStatusInput,
  CreateTableInput,
  TableFilters,
  TableResource,
  TableStatusChangeResource,
  UpdateTableInput,
} from "@/types/shop";

// =========================================================================
//  Helpers
// =========================================================================

function tableUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/tables${path}`;
}

function toParams(filters: TableFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.zone_id) params.set("zone_id", filters.zone_id);
  if (filters.status) params.set("status", filters.status);
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

export const tableService = {
  // --- Query (read) ---

  list: (shopSlug: string, filters: TableFilters = {}) =>
    apiFetch<PaginatedResponse<TableResource>>(
      `${tableUrl(shopSlug)}?${toParams({ per_page: 200, ...filters })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: TableResource }>(tableUrl(shopSlug, `/${id}`)),

  statusHistory: (shopSlug: string, id: string) =>
    apiFetch<{ data: TableStatusChangeResource[] }>(tableUrl(shopSlug, `/${id}/status-history`)),

  // --- Mutation (write) ---

  create: (shopSlug: string, data: CreateTableInput) =>
    apiFetch<{ data: TableResource }>(tableUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateTableInput) =>
    apiFetch<{ data: TableResource }>(tableUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(tableUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: TableResource }>(tableUrl(shopSlug, `/${id}/restore`), {
      method: "POST",
    }),

  toggleActive: (shopSlug: string, id: string) =>
    apiFetch<{ data: TableResource }>(tableUrl(shopSlug, `/${id}/toggle-active`), {
      method: "POST",
    }),

  // --- Workflow ---

  changeStatus: (shopSlug: string, id: string, data: ChangeTableStatusInput) =>
    apiFetch<{ data: TableResource }>(tableUrl(shopSlug, `/${id}/status`), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  regenerateQr: (shopSlug: string, id: string) =>
    apiFetch<{ data: TableResource }>(tableUrl(shopSlug, `/${id}/regenerate-qr`), {
      method: "POST",
    }),

  // --- HQ defaults (issue #890) ---

  defaultsPreview: (shopSlug: string) =>
    apiFetch<TableDefaultsPreview>(tableUrl(shopSlug, "/defaults/preview")),

  applyDefaults: (shopSlug: string) =>
    apiFetch<TableDefaultsApplyResult>(tableUrl(shopSlug, "/defaults/apply"), {
      method: "POST",
    }),
};
