/**
 * Device Service — pure TypeScript, no React dependency.
 *
 * Contains all API calls for Device management (HQ + Shop scope).
 * Used by hooks in src/hooks/api/use-devices.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { Device } from "@/types/models/Device";
import type { DeviceStatus } from "@/types/models/enum/DeviceStatus";
import type { DeviceType } from "@/types/models/enum/DeviceType";

// =========================================================================
//  Types
// =========================================================================

export interface DeviceFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: DeviceStatus;
  type?: DeviceType;
  branch_id?: string;
  with_trashed?: boolean;
  sort?: string;
}

export interface CreateDeviceInput {
  name: string;
  type: DeviceType;
  branch_id?: string;
  notes?: string | null;
}

export interface UpdateDeviceInput {
  name?: string;
  type?: DeviceType;
  branch_id?: string;
  notes?: string | null;
}

// =========================================================================
//  Helpers
// =========================================================================

function hqUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/devices${path}`;
}

function shopUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/devices${path}`;
}

function toParams(filters: DeviceFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.status) params.set("status", filters.status);
  if (filters.type) params.set("type", filters.type);
  if (filters.branch_id) params.set("branch_id", filters.branch_id);
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  HQ Service (all branches)
// =========================================================================

export const deviceHqService = {
  list: (brandSlug: string, filters: DeviceFilters = {}) =>
    apiFetch<PaginatedResponse<Device>>(
      `${hqUrl(brandSlug)}?${toParams({ per_page: 100, ...filters })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: Device }>(hqUrl(brandSlug, `/${id}`)),

  create: (brandSlug: string, data: CreateDeviceInput) =>
    apiFetch<{ data: Device }>(hqUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateDeviceInput) =>
    apiFetch<{ data: Device }>(hqUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(hqUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  restore: (brandSlug: string, id: string) =>
    apiFetch<{ data: Device }>(hqUrl(brandSlug, `/${id}/restore`), {
      method: "POST",
    }),

  regeneratePairing: (brandSlug: string, id: string) =>
    apiFetch<{ data: Device }>(hqUrl(brandSlug, `/${id}/regenerate-pairing`), { method: "POST" }),

  revoke: (brandSlug: string, id: string) =>
    apiFetch<{ data: Device }>(hqUrl(brandSlug, `/${id}/revoke`), {
      method: "POST",
    }),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number }>(hqUrl(brandSlug, "/bulk-delete"), {
      method: "POST",
      body: JSON.stringify({ ids }),
    }),
};

// =========================================================================
//  Shop Service (single branch)
// =========================================================================

export const deviceShopService = {
  list: (shopSlug: string, filters: DeviceFilters = {}) =>
    apiFetch<PaginatedResponse<Device>>(
      `${shopUrl(shopSlug)}?${toParams({ per_page: 100, ...filters })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: Device }>(shopUrl(shopSlug, `/${id}`)),

  create: (shopSlug: string, data: CreateDeviceInput) =>
    apiFetch<{ data: Device }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateDeviceInput) =>
    apiFetch<{ data: Device }>(shopUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(shopUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: Device }>(shopUrl(shopSlug, `/${id}/restore`), {
      method: "POST",
    }),

  regeneratePairing: (shopSlug: string, id: string) =>
    apiFetch<{ data: Device }>(shopUrl(shopSlug, `/${id}/regenerate-pairing`), { method: "POST" }),

  revoke: (shopSlug: string, id: string) =>
    apiFetch<{ data: Device }>(shopUrl(shopSlug, `/${id}/revoke`), {
      method: "POST",
    }),
};
