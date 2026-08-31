/**
 * Material Batch Hooks — React Query wrappers around materialBatchService.
 *
 * All mutations invalidate the materialBatchKeys.all(shopSlug) tree on
 * success and surface errors via sonner. `approve` and `complete` bubble
 * errors so the detail page can render detailed modals when shortages or
 * yield mismatches are reported.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  materialBatchService,
  type MaterialBatchCompleteInput,
  type MaterialBatchCreateInput,
  type MaterialBatchFilters,
  type MaterialBatchUpdateInput,
} from "@/services/material-batch-service";
import { useTranslation } from "@/providers/app-provider";
import { materialBatchKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useMaterialBatches(shopSlug: string, filters: MaterialBatchFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialBatchKeys.list(shopSlug, locale, filters),
    queryFn: () => materialBatchService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useMaterialBatch(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialBatchKeys.detail(shopSlug, locale, id),
    queryFn: () => materialBatchService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: MaterialBatchCreateInput) => materialBatchService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Batch created.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create batch."),
  });
}

export function useUpdateMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: MaterialBatchUpdateInput }) =>
      materialBatchService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Batch updated.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update batch."),
  });
}

export function useDeleteMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialBatchService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Batch deleted.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete batch."),
  });
}

// =========================================================================
//  Mutations — Workflow
// =========================================================================

export function useSubmitMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialBatchService.submit(shopSlug, id),
    onSuccess: () => {
      toast.success("Batch submitted for approval.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to submit batch."),
  });
}

export function useApproveMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialBatchService.approve(shopSlug, id),
    onSuccess: () => {
      toast.success("Batch approved.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to approve batch."),
  });
}

export function useStartMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialBatchService.start(shopSlug, id),
    onSuccess: () => {
      toast.success("Batch started — materials drawn from stock.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to start batch."),
  });
}

export function useCompleteMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: MaterialBatchCompleteInput }) =>
      materialBatchService.complete(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Batch completed — yield added to stock.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to complete batch."),
  });
}

export function useCancelMaterialBatch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialBatchService.cancel(shopSlug, id),
    onSuccess: () => {
      toast.success("Batch cancelled.");
      qc.invalidateQueries({ queryKey: materialBatchKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel batch."),
  });
}

export function useMaterialBatchCostBreakdown(shopSlug: string, id: string | undefined) {
  return useQuery({
    queryKey: id
      ? (["material-batches", shopSlug, "cost-breakdown", id] as const)
      : (["material-batches", "cost-breakdown", "noop"] as const),
    queryFn: () => materialBatchService.costBreakdown(shopSlug, id!),
    enabled: !!shopSlug && !!id,
    staleTime: 30 * 1000,
  });
}
