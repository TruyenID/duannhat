/**
 * Material Hooks — React wrappers around materialService.
 *
 * Pattern mirrors use-categories.ts:
 * - useMaterials()       → useQuery  → materialService.list()
 * - useMaterialLookup()  → useQuery  → materialService.lookup()
 * - useCreateMaterial()  → useMutation → service.create() + toast
 * - …
 *
 * Every mutation invalidates materialKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  materialService,
  type CreateMaterialInput,
  type MaterialFilters,
  type UpdateMaterialInput,
} from "@/services/material-service";
import { useTranslation } from "@/providers/app-provider";
import { materialKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useMaterials(brandSlug: string, filters: MaterialFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialKeys.list(brandSlug, locale, filters),
    queryFn: () => materialService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useMaterial(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialKeys.detail(brandSlug, locale, id),
    queryFn: () => materialService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

export function useMaterialLookup(brandSlug: string, enabled = true) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialKeys.dropdown(brandSlug, locale),
    queryFn: () => materialService.lookup(brandSlug),
    enabled: !!brandSlug && enabled,
  });
}

export function useVariantLookup(brandSlug: string, enabled = true) {
  return useQuery({
    queryKey: ["variants", brandSlug, "lookup"] as const,
    queryFn: () => materialService.variants(brandSlug),
    enabled: !!brandSlug && enabled,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateMaterial(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateMaterialInput) => materialService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Material created.");
      qc.invalidateQueries({ queryKey: materialKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create material."),
  });
}

export function useUpdateMaterial(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMaterialInput }) =>
      materialService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Material updated.");
      qc.invalidateQueries({ queryKey: materialKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update material."),
  });
}

export function useDeleteMaterial(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Material deleted.");
      qc.invalidateQueries({ queryKey: materialKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete material."),
  });
}

export function useRestoreMaterial(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Material restored.");
      qc.invalidateQueries({ queryKey: materialKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore material."),
  });
}

export function useBulkDeleteMaterials(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => materialService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.material.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.material.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.material.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: materialKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
