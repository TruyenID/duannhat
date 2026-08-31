/**
 * Report Hooks — React-Query wrappers around report-service.
 *
 * Plan-018 TD3.3 — Supplier Yield Variance report.
 */

import { useQuery } from "@tanstack/react-query";

import { reportService, type SupplierYieldFilters } from "@/services/report-service";
import { reportKeys } from "./query-keys";

export function useSupplierYieldReport(brandSlug: string, filters: SupplierYieldFilters) {
  return useQuery({
    queryKey: reportKeys.supplierYield(brandSlug, filters),
    queryFn: () => reportService.supplierYield(brandSlug, filters),
    enabled: !!brandSlug && !!filters.date_from && !!filters.date_to,
  });
}
