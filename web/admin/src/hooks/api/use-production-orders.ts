/**
 * Production Order Hooks — React Query wrappers around
 * productionOrderService. Mirrors the same conventions as
 * use-material-batches (invalidate-all on success, surface errors via
 * sonner for non-workflow mutations, bubble errors for start/approve/
 * complete so a detail page can render a shortages/yield modal).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  productionOrderService,
  type ProductionOrderCompleteInput,
  type ProductionOrderCreateInput,
  type ProductionOrderFilters,
  type ProductionOrderUpdateInput,
} from "@/services/production-order-service";
import { useTranslation } from "@/providers/app-provider";
import { productionOrderKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useProductionOrders(shopSlug: string, filters: ProductionOrderFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productionOrderKeys.list(shopSlug, locale, filters),
    queryFn: () => productionOrderService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useProductionOrder(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productionOrderKeys.detail(shopSlug, locale, id),
    queryFn: () => productionOrderService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: ProductionOrderCreateInput) => productionOrderService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Production order created.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create production order."),
  });
}

export function useUpdateProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: ProductionOrderUpdateInput }) =>
      productionOrderService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Production order updated.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update production order."),
  });
}

export function useDeleteProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productionOrderService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Production order deleted.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete production order."),
  });
}

// =========================================================================
//  Mutations — Workflow
// =========================================================================

export function useSubmitProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productionOrderService.submit(shopSlug, id),
    onSuccess: () => {
      toast.success("Order submitted for approval.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit order."),
  });
}

export function useApproveProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productionOrderService.approve(shopSlug, id),
    onSuccess: () => {
      toast.success("Order approved.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to approve order."),
  });
}

export function useStartProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productionOrderService.start(shopSlug, id),
    onSuccess: () => {
      toast.success("Production started.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to start production."),
  });
}

export function useCompleteProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: ProductionOrderCompleteInput }) =>
      productionOrderService.complete(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Production completed — output added to stock.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to complete production."),
  });
}

export function useCancelProductionOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productionOrderService.cancel(shopSlug, id),
    onSuccess: () => {
      toast.success("Order cancelled.");
      qc.invalidateQueries({ queryKey: productionOrderKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel order."),
  });
}
