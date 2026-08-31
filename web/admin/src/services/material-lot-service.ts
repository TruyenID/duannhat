/**
 * Material Lot Service — pure TypeScript, no React dependency.
 *
 * Spans both the HQ surface (/api/v1/hq/{brandSlug}/material-lots/...) for
 * brand-level inventory views and the Shop surface (/api/v1/shops/{shopSlug}/
 * material-lots/...) for warehouse-level receive + day-to-day operations.
 *
 * The React-Query layer lives in src/hooks/api/use-material-lots.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export type MaterialLotStatus = "active" | "quarantined" | "depleted" | "expired" | "disposed";

export type MaterialLotSource = "inbound" | "production";

export interface MaterialLot {
  id: string;
  organization_id: string;
  brand_id: string;
  lot_code: string;
  material_id: string;
  warehouse_id: string;
  source: MaterialLotSource;
  status: MaterialLotStatus;
  supplier_name: string | null;
  supplier_lot_code: string | null;
  received_at: string;
  expiry_date: string | null;
  received_qty: number | string;
  qty_on_hand: number | string;
  unit: string;
  cost_per_unit: number | string | null;
  unit_cost: number | string | null;
  total_cost: number | string | null;
  currency: string | null;
  cost_basis: string | null;
  /** @deprecated Use coa_urls instead */
  coa_url?: string | null;
  coa_urls: Array<{ language: string; url: string }> | null;
  quarantine_reason: string | null;
  effective_allergens: string[] | null;
  produced_by_batch_id: string | null;
  received_temperature: number | string | null;
  temperature_override_reason: string | null;
  /**
   * Tier 1.D — true when received_temperature is within material's
   * [temperature_min, temperature_max], false when out-of-range
   * (override_reason was required), null when no temperature was
   * recorded.
   */
  is_temperature_compliant: boolean | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  // Nested relations (whenLoaded)
  material?: { id: string; sku: string | null };
  warehouse?: { id: string; name: string };
  produced_by_batch?: {
    id: string;
    batch_code: string;
    started_at: string | null;
    completed_at: string | null;
  };
}

export interface MaterialLotListFilters {
  page?: number;
  per_page?: number;
  brand_id?: string;
  warehouse_id?: string;
  material_id?: string;
  status?: MaterialLotStatus;
  source?: MaterialLotSource;
  expiring_within_days?: number;
  search?: string;
  sort?: string;
  with_trashed?: boolean;
}

export interface ReceiveLotInput {
  material_id: string;
  warehouse_id: string;
  supplier_name?: string | null;
  supplier_lot_code?: string | null;
  received_at?: string | null;
  expiry_date?: string | null;
  received_qty: number;
  unit?: string | null;
  cost_per_unit?: number | null;
  currency?: string | null;
  coa_url?: string | null;
  received_temperature?: number | null;
  temperature_override_reason?: string | null;
}

export type TimelineEntryType = "audit" | "movement" | "genealogy" | "expiry_alert" | "recall";

export interface TimelineEntry {
  type: TimelineEntryType;
  occurred_at: string;
  [key: string]: unknown;
}

export interface TimelineFilters {
  page?: number;
  per_page?: number;
  types?: string[];
}

export interface QuarantineInput {
  reason: string;
}

export interface ReleaseInput {
  note?: string;
}

export interface DisposeInput {
  force?: boolean;
  reason?: string;
}

export interface SplitLotInput {
  parts: Array<{ qty: number; target_warehouse_id?: string | null }>;
  reason?: string | null;
}

// =========================================================================
//  Helpers
// =========================================================================

function hqUrl(brandSlug: string, path = ""): string {
  return `/api/v1/hq/${brandSlug}/material-lots${path}`;
}

function shopUrl(shopSlug: string, path = ""): string {
  return `/api/v1/shops/${shopSlug}/material-lots${path}`;
}

function toParams(filters: MaterialLotListFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.brand_id) params.set("brand_id", filters.brand_id);
  if (filters.warehouse_id) params.set("warehouse_id", filters.warehouse_id);
  if (filters.material_id) params.set("material_id", filters.material_id);
  if (filters.status) params.set("status", filters.status);
  if (filters.source) params.set("source", filters.source);
  if (filters.expiring_within_days !== undefined) {
    params.set("expiring_within_days", String(filters.expiring_within_days));
  }
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.with_trashed) params.set("with_trashed", "1");
  return params.toString();
}

// =========================================================================
//  HQ surface
// =========================================================================

export const materialLotHqService = {
  list: (brandSlug: string, filters: MaterialLotListFilters = {}) =>
    apiFetch<PaginatedResponse<MaterialLot>>(
      `${hqUrl(brandSlug)}?${toParams({ per_page: 25, ...filters })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: MaterialLot }>(hqUrl(brandSlug, `/${id}`)),

  bulkDelete: (brandSlug: string, ids: string[]) =>
    apiFetch<{ deleted: number; errors: { id: string; name?: string | null; message: string }[] }>(
      hqUrl(brandSlug, "/bulk-delete"),
      { method: "POST", body: JSON.stringify({ ids }) }
    ),

  quarantine: (brandSlug: string, id: string, input: QuarantineInput) =>
    apiFetch<{ data: MaterialLot }>(hqUrl(brandSlug, `/${id}/quarantine`), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  release: (brandSlug: string, id: string, input: ReleaseInput = {}) =>
    apiFetch<{ data: MaterialLot }>(hqUrl(brandSlug, `/${id}/release`), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  dispose: (brandSlug: string, id: string, input: DisposeInput = {}) =>
    apiFetch<{ data: MaterialLot }>(hqUrl(brandSlug, `/${id}/dispose`), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  timeline: (brandSlug: string, id: string, filters: TimelineFilters = {}) => {
    const params = new URLSearchParams();
    if (filters.page) params.set("page", String(filters.page));
    if (filters.per_page) params.set("per_page", String(filters.per_page));
    if (filters.types) filters.types.forEach((t) => params.append("types[]", t));
    return apiFetch<PaginatedResponse<TimelineEntry>>(
      hqUrl(brandSlug, `/${id}/timeline?${params.toString()}`)
    );
  },
};

// =========================================================================
//  Shop surface
// =========================================================================

export interface ReceiveLotResponse {
  data: MaterialLot;
  stock_transaction_id: string;
}

export const materialLotShopService = {
  list: (shopSlug: string, filters: MaterialLotListFilters = {}) =>
    apiFetch<PaginatedResponse<MaterialLot>>(
      `${shopUrl(shopSlug)}?${toParams({ per_page: 25, ...filters })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: MaterialLot }>(shopUrl(shopSlug, `/${id}`)),

  expiring: (shopSlug: string, days = 7, warehouseId?: string) => {
    const params = new URLSearchParams({ days: String(days) });
    if (warehouseId) params.set("warehouse_id", warehouseId);
    return apiFetch<PaginatedResponse<MaterialLot>>(
      `${shopUrl(shopSlug, "/expiring")}?${params.toString()}`
    );
  },

  receive: (shopSlug: string, input: ReceiveLotInput) =>
    apiFetch<ReceiveLotResponse>(shopUrl(shopSlug, "/receive"), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  split: (shopSlug: string, lotId: string, input: SplitLotInput) =>
    apiFetch<{ data: { parent: MaterialLot; children: MaterialLot[] } }>(
      shopUrl(shopSlug, `/${lotId}/split`),
      {
        method: "POST",
        body: JSON.stringify(input),
      }
    ),
};
