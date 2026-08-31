/**
 * Material Unit Hooks — React-Query wrappers around material-unit-service.
 *
 * Units are nested under a parent material (HQ scope). Mutations invalidate
 * the per-material list to keep the Units settings panel in sync.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { useTranslation } from "@/providers/app-provider";
import { materialUnitService, type MaterialUnitInput } from "@/services/material-unit-service";

import { materialUnitKeys } from "./query-keys";

export function useMaterialUnits(brandSlug: string, materialId: string | undefined) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialId
      ? materialUnitKeys.list(brandSlug, materialId, locale)
      : ["material-units", "list", "noop"],
    queryFn: () => materialUnitService.list(brandSlug, materialId!),
    enabled: !!brandSlug && !!materialId,
  });
}

export function useCreateMaterialUnit(brandSlug: string, materialId: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (input: MaterialUnitInput) =>
      materialUnitService.create(brandSlug, materialId, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialUnitKeys.all(brandSlug, materialId),
      });
      toast.success(t("material_unit.created"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useUpdateMaterialUnit(brandSlug: string, materialId: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: Partial<MaterialUnitInput> }) =>
      materialUnitService.update(brandSlug, materialId, id, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialUnitKeys.all(brandSlug, materialId),
      });
      toast.success(t("material_unit.updated"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useDeleteMaterialUnit(brandSlug: string, materialId: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (id: string) => materialUnitService.delete(brandSlug, materialId, id),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialUnitKeys.all(brandSlug, materialId),
      });
      toast.success(t("material_unit.deleted"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}
