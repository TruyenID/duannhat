/**
 * Stock Transfer Hooks — React Query wrappers around stockTransferService.
 *
 * Mutations all use toast.success/toast.error and invalidate the shop-scoped
 * stockTransferKeys.all(shopSlug) tree (MANDATORY per frontend.md).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  stockTransferService,
  type StockTransferCreateInput,
  type StockTransferFilters,
  type StockTransferUpdateInput,
} from "@/services/stock-transfer-service";
import { useTranslation } from "@/providers/app-provider";
import { stockTransferKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useStockTransfers(shopSlug: string, filters: StockTransferFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockTransferKeys.list(shopSlug, locale, filters),
    queryFn: () => stockTransferService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useStockTransfer(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: stockTransferKeys.detail(shopSlug, locale, id),
    queryFn: () => stockTransferService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateStockTransfer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: StockTransferCreateInput) => stockTransferService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Stock transfer created.");
      qc.invalidateQueries({ queryKey: stockTransferKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create stock transfer."),
  });
}

export function useUpdateStockTransfer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: StockTransferUpdateInput }) =>
      stockTransferService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Stock transfer updated.");
      qc.invalidateQueries({ queryKey: stockTransferKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update stock transfer."),
  });
}

export function useDeleteStockTransfer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransferService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Stock transfer deleted.");
      qc.invalidateQueries({ queryKey: stockTransferKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete stock transfer."),
  });
}

// =========================================================================
//  Mutations — Workflow
// =========================================================================

export function useSubmitStockTransfer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransferService.submit(shopSlug, id),
    onSuccess: () => {
      toast.success("Transfer submitted for approval.");
      qc.invalidateQueries({ queryKey: stockTransferKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit transfer."),
  });
}

export function useApproveStockTransfer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransferService.approve(shopSlug, id),
    onSuccess: () => {
      toast.success("Transfer approved.");
      qc.invalidateQueries({ queryKey: stockTransferKeys.all(shopSlug) });
    },
    // Approve deducts source stock via a strict stock_out — it fails (422
    // INSUFFICIENT_STOCK) when the source warehouse can't cover the sent
    // quantities, and the whole transfer rolls back (stays Pending). Surface
    // the backend message so the user knows why nothing happened instead of
    // the previous silent no-op.
    onError: (e: Error) => toast.error(e.message || "Failed to approve transfer."),
  });
}

export function useReceiveStockTransfer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransferService.receive(shopSlug, id),
    onSuccess: () => {
      toast.success("Transfer received — destination stock updated.");
      qc.invalidateQueries({ queryKey: stockTransferKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to receive transfer."),
  });
}

export function useCancelStockTransfer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => stockTransferService.cancel(shopSlug, id),
    onSuccess: () => {
      toast.success("Transfer cancelled.");
      qc.invalidateQueries({ queryKey: stockTransferKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel transfer."),
  });
}
