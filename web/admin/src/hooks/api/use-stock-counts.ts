/**
 * Stock Count Hooks — React Query wrappers around stockCountService.
 *
 * Mutations use toast.success/toast.error and invalidate the shop-scoped
 * stockCountKeys.all(shopSlug) tree.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  stockCountService,
  type StockCountAddItemsInput,
  type StockCountCreateInput,
  type StockCountFilters,
  type StockCountUpdateItemsInput,
} from "@/services/stock-count-service";
import { useTranslation } from "@/providers/app-provider";
import { stockCountKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useStockCounts(shopSlug: string, filters: StockCountFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockCountKeys.list(shopSlug, locale, filters),
    queryFn: () => stockCountService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useStockCount(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockCountKeys.detail(shopSlug, locale, id),
    queryFn: () => stockCountService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateStockCount(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: StockCountCreateInput) => stockCountService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Stock count created.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create stock count."),
  });
}

export function useUpdateStockCount(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: { note?: string | null } }) =>
      stockCountService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Stock count updated.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update stock count."),
  });
}

export function useDeleteStockCount(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockCountService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Stock count deleted.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete stock count."),
  });
}

// =========================================================================
//  Mutations — Workflow
// =========================================================================

export function useStartStockCount(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockCountService.start(shopSlug, id),
    onSuccess: () => {
      toast.success("Counting started.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to start counting."),
  });
}

export function useAddStockCountItems(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: StockCountAddItemsInput }) =>
      stockCountService.addItems(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Items added to the count.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to add items."),
  });
}

export function useUpdateStockCountItems(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: StockCountUpdateItemsInput }) =>
      stockCountService.updateItems(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Counted quantities saved.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to save counted quantities."),
  });
}

export function useSubmitStockCount(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockCountService.submit(shopSlug, id),
    onSuccess: () => {
      toast.success("Count submitted for approval.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit count."),
  });
}

export function useApproveStockCount(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockCountService.approve(shopSlug, id),
    onSuccess: () => {
      toast.success("Count approved — variances applied.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to approve count."),
  });
}

export function useCancelStockCount(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockCountService.cancel(shopSlug, id),
    onSuccess: () => {
      toast.success("Count cancelled.");
      qc.invalidateQueries({ queryKey: stockCountKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel count."),
  });
}
