/**
 * Printer Service — pure TypeScript, no React dependency.
 *
 * API calls for physical ESC/POS printer configuration (Shop scope).
 * Used by hooks in src/hooks/api/use-printers.ts.
 *
 * Cloud owns the printer CONFIG (which device holds which role, at which LAN
 * address). The workstation pulls this list down, caches it in its own SQLite
 * and drives the printer socket itself — so a Cloud outage never stops a shop
 * from printing.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { Printer } from "@/types/models/Printer";
import type { PrinterConnectionType } from "@/types/models/enum/PrinterConnectionType";
import type { PrinterCutType } from "@/types/models/enum/PrinterCutType";
import type { PrinterRole } from "@/types/models/enum/PrinterRole";

// =========================================================================
//  Types
// =========================================================================

export interface PrinterFilters {
  page?: number;
  per_page?: number;
  search?: string;
  role?: PrinterRole;
  connection_type?: PrinterConnectionType;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

export interface CreatePrinterInput {
  name: string;
  /** One printer may serve SEVERAL roles at once — always an array. */
  roles: PrinterRole[];
  connection_type: PrinterConnectionType;
  address: string;
  paper_width?: number;
  cut_type?: PrinterCutType;
  encoding?: string;
  is_active?: boolean;
  notes?: string | null;
}

export interface UpdatePrinterInput {
  name?: string;
  roles?: PrinterRole[];
  connection_type?: PrinterConnectionType;
  address?: string;
  paper_width?: number;
  cut_type?: PrinterCutType;
  encoding?: string;
  is_active?: boolean;
  notes?: string | null;
}

/**
 * The index endpoint adds a `meta` block on top of the usual pagination.
 * `available_roles` mirrors the workstation's canonical `PrinterRoles` list,
 * so the UI never offers a role the workstation could not dispatch to.
 */
export interface PrinterListMeta {
  available_roles?: PrinterRole[];
}

export type PrinterListResponse = PaginatedResponse<Printer> & {
  meta?: PrinterListMeta;
};

// =========================================================================
//  Helpers
// =========================================================================

function shopUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/printers${path}`;
}

function toParams(filters: Record<string, unknown>): string {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      params.append(key, String(value));
    }
  });
  return params.toString();
}

// =========================================================================
//  Shop Scope
// =========================================================================

export const printerShopService = {
  list: (shopSlug: string, filters: PrinterFilters = {}) =>
    apiFetch<PrinterListResponse>(
      `${shopUrl(shopSlug)}?${toParams({ per_page: 100, ...filters })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: Printer }>(shopUrl(shopSlug, `/${id}`)),

  create: (shopSlug: string, data: CreatePrinterInput) =>
    apiFetch<{ data: Printer }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdatePrinterInput) =>
    apiFetch<{ data: Printer }>(shopUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(shopUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: Printer }>(shopUrl(shopSlug, `/${id}/restore`), {
      method: "POST",
    }),
};
