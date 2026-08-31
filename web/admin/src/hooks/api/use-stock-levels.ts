/**
 * Stock Level Hooks — React Query wrappers around stockLevelService.
 *
 * Every mutation shows toast.success or toast.error and invalidates the
 * shop-scoped stock-level cache (MANDATORY per frontend.md). Movement
 * history is cached separately because its params diverge from the list.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  stockLevelService,
  type StockLevelFilters,
  type StockLevelUpdateInput,
  type StockMovementFilters,
} from "@/services/stock-level-service";
import { useTranslation } from "@/providers/app-provider";
import { stockLevelKeys } from "./query-keys";

export function useStockLevels(shopSlug: string, filters: StockLevelFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockLevelKeys.list(shopSlug, locale, filters),
    queryFn: () => stockLevelService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useStockLevel(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: [...stockLevelKeys.all(shopSlug), "detail", id, locale] as const,
    queryFn: () => stockLevelService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

export function useUpdateStockLevel(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: StockLevelUpdateInput }) =>
      stockLevelService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Stock level updated.");
      qc.invalidateQueries({ queryKey: stockLevelKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update stock level."),
  });
}

export function useStockMovements(
  shopSlug: string,
  id: string,
  filters: StockMovementFilters = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: [...stockLevelKeys.all(shopSlug), "movements", id, filters, locale] as const,
    queryFn: () => stockLevelService.movements(shopSlug, id, filters),
    enabled: !!shopSlug && !!id,
  });
}
