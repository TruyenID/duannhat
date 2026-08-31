/**
 * Stock Alert Hooks — React Query wrappers around stockAlertService.
 * Read-only — no mutation hooks because the backend exposes no writes.
 */

import { useQuery } from "@tanstack/react-query";
import { stockAlertService, type StockAlertFilters } from "@/services/stock-alert-service";
import { useTranslation } from "@/providers/app-provider";
import { stockAlertKeys } from "./query-keys";

export function useStockAlerts(shopSlug: string, filters: StockAlertFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockAlertKeys.list(shopSlug, locale, filters),
    queryFn: () => stockAlertService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useStockAlertSummary(shopSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockAlertKeys.summary(shopSlug, locale),
    queryFn: () => stockAlertService.summary(shopSlug),
    enabled: !!shopSlug,
    staleTime: 30 * 1000,
  });
}
