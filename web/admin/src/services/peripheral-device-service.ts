/**
 * Peripheral Device Service — pure TypeScript, no React dependency.
 *
 * API calls for peripheral-device management (shop scope only — the backend
 * exposes CRUD under /api/v1/shops/{shopSlug}/peripheral-devices). Used by hooks
 * in src/hooks/api/use-peripheral-devices.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type {
  PeripheralDevice,
  PeripheralDeviceMetadata,
  PeripheralDeviceType,
} from "@/types/models/PeripheralDevice";

// =========================================================================
//  Types
// =========================================================================

export interface PeripheralDeviceFilters {
  page?: number;
  per_page?: number;
  search?: string;
  type?: PeripheralDeviceType;
  with_trashed?: boolean;
  sort?: string;
}

export interface CreatePeripheralDeviceInput {
  name: string;
  type: PeripheralDeviceType;
  metadata?: PeripheralDeviceMetadata | null;
  is_active?: boolean;
}

export interface UpdatePeripheralDeviceInput {
  name?: string;
  type?: PeripheralDeviceType;
  metadata?: PeripheralDeviceMetadata | null;
  is_active?: boolean;
}

// =========================================================================
//  Helpers
// =========================================================================

function shopUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/peripheral-devices${path}`;
}

function toParams(filters: PeripheralDeviceFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.type) params.set("type", filters.type);
  if (filters.with_trashed) params.set("with_trashed", "1");
  if (filters.sort) params.set("sort", filters.sort);
  return params.toString();
}

// =========================================================================
//  Shop Service (single branch)
// =========================================================================

export const peripheralDeviceShopService = {
  list: (shopSlug: string, filters: PeripheralDeviceFilters = {}) =>
    apiFetch<PaginatedResponse<PeripheralDevice>>(
      `${shopUrl(shopSlug)}?${toParams({ per_page: 100, ...filters })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: PeripheralDevice }>(shopUrl(shopSlug, `/${id}`)),

  create: (shopSlug: string, data: CreatePeripheralDeviceInput) =>
    apiFetch<{ data: PeripheralDevice }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdatePeripheralDeviceInput) =>
    apiFetch<{ data: PeripheralDevice }>(shopUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(shopUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: PeripheralDevice }>(shopUrl(shopSlug, `/${id}/restore`), {
      method: "POST",
    }),
};
