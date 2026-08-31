/**
 * Table Service — list tables for POS TablePicker / BindTableDialog /
 * ChangeTableDialog.
 *
 *   GET /api/v1/pos/tables  → paginated { data: TableResource[] }
 *
 * The endpoint paginates (TableController::index), so we default per_page to
 * 100 — a typical full-service restaurant has far fewer tables. If a shop
 * eventually exceeds that, the dialog can paginate properly or this call can
 * be replaced with a dedicated lookup endpoint.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { TableResource, TableStatusValue } from "@/app/pos/types";

export interface TableFilters {
  status?: TableStatusValue;
  zone_id?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

function toParams(filters: TableFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.zone_id) params.set("zone_id", filters.zone_id);
  if (filters.search) params.set("search", filters.search);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

export const tableService = {
  list: (_shopSlug: string, filters: TableFilters = {}) => {
    // shopSlug now flows via X-Shop-Slug header (set by setCurrentShopSlug);
    // param retained for caller compatibility + TanStack Query cache keys.
    const merged: TableFilters = { per_page: 100, ...filters };
    return apiFetch<PaginatedResponse<TableResource>>(
      `/api/v1/pos/tables?${toParams(merged)}`,
    );
  },

  /**
   * Change a table's status. apiFetch routes LAN-first / cloud-fallback, so in
   * LAN mode this hits the workstation (which updates its mirror + immediately
   * syncs UP to Cloud) and in cloud mode it hits the backend directly. Both
   * expose POST /api/v1/pos/tables/{id}/status and return the updated table.
   */
  changeStatus: (
    _shopSlug: string,
    tableId: string,
    status: TableStatusValue,
  ) =>
    apiFetch<{ data: TableResource }>(
      `/api/v1/pos/tables/${encodeURIComponent(tableId)}/status`,
      { method: "POST", body: JSON.stringify({ status }) },
    ),
};
