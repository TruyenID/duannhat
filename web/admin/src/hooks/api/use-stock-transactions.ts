/**
 * Stock Transaction Hooks — React Query wrappers around
 * stockTransactionService.
 *
 * Mutations all use toast.success/toast.error and invalidate the shop-scoped
 * stockTransactionKeys.all(shopSlug) tree (MANDATORY per frontend.md).
 * `approve` bubbles up the raw error so callers can inspect a shortages
 * response body and render an out-of-stock modal.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  stockTransactionService,
  type StockTransactionApproveInput,
  type StockTransactionCreateInput,
  type StockTransactionFilters,
  type StockTransactionUpdateInput,
} from "@/services/stock-transaction-service";
import { useTranslation } from "@/providers/app-provider";
import { stockTransactionKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useStockTransactions(shopSlug: string, filters: StockTransactionFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockTransactionKeys.list(shopSlug, locale, filters),
    queryFn: () => stockTransactionService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useStockTransaction(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockTransactionKeys.detail(shopSlug, locale, id),
    queryFn: () => stockTransactionService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateStockTransaction(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: StockTransactionCreateInput) =>
      stockTransactionService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Stock transaction created.");
      qc.invalidateQueries({
        queryKey: stockTransactionKeys.all(shopSlug),
      });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create stock transaction."),
  });
}

export function useUpdateStockTransaction(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: StockTransactionUpdateInput }) =>
      stockTransactionService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Stock transaction updated.");
      qc.invalidateQueries({
        queryKey: stockTransactionKeys.all(shopSlug),
      });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update stock transaction."),
  });
}

export function useDeleteStockTransaction(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransactionService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Stock transaction deleted.");
      qc.invalidateQueries({
        queryKey: stockTransactionKeys.all(shopSlug),
      });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete stock transaction."),
  });
}

// =========================================================================
//  Mutations — Workflow
// =========================================================================

export function useSubmitStockTransaction(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransactionService.submit(shopSlug, id),
    onSuccess: () => {
      toast.success("Transaction submitted for approval.");
      qc.invalidateQueries({
        queryKey: stockTransactionKeys.all(shopSlug),
      });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit transaction."),
  });
}

export function useApproveStockTransaction(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data?: StockTransactionApproveInput }) =>
      stockTransactionService.approve(shopSlug, id, data ?? {}),
    onSuccess: () => {
      toast.success("Transaction approved.");
      qc.invalidateQueries({
        queryKey: stockTransactionKeys.all(shopSlug),
      });
    },
    // Approve failures can carry a `shortages[]` payload. Do not toast here —
    // callers inspect `mutation.error` and render an insufficient-stock
    // modal. The default toast would steal the spotlight.
  });
}

export function useCancelStockTransaction(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransactionService.cancel(shopSlug, id),
    onSuccess: () => {
      toast.success("Transaction cancelled.");
      qc.invalidateQueries({
        queryKey: stockTransactionKeys.all(shopSlug),
      });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel transaction."),
  });
}
