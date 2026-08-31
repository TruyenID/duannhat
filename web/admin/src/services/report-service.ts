/**
 * Report Service — pure TypeScript, no React dependency.
 *
 * Plan-018 TD3.3 — Supplier Yield Variance report.
 */

import { apiFetch } from "@/lib/api";

export interface SupplierYieldRow {
  supplier_name: string;
  lots_count: number;
  total_qty_consumed: string;
  total_actual_yield: string;
  yield_percent: number;
  variance_from_planned: number;
}

export interface SupplierYieldFilters {
  date_from: string;
  date_to: string;
  supplier_name?: string;
}

export const reportService = {
  supplierYield: (brandSlug: string, filters: SupplierYieldFilters) => {
    const params = new URLSearchParams({
      date_from: filters.date_from,
      date_to: filters.date_to,
    });
    if (filters.supplier_name) params.set("supplier_name", filters.supplier_name);
    return apiFetch<{ suppliers: SupplierYieldRow[] }>(
      `/api/v1/hq/${brandSlug}/reports/supplier-yield?${params.toString()}`
    );
  },
};
