/**
 * Warehouse Hooks — React Query wrappers around warehouseService.
 *
 * `useWarehouseLookup` is the one pages should call for <Select> dropdowns
 * (stock levels filter, transaction form, etc.) — it returns every active
 * warehouse in the shop with a long stale time so picking the same value
 * twice in a row does not re-fetch.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  warehouseService,
  type WarehouseCreateInput,
  type WarehouseFilters,
  type WarehouseSettingsInput,
  type WarehouseUpdateInput,
} from "@/services/warehouse-service";
import { useTranslation } from "@/providers/app-provider";
import { warehouseKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useWarehouses(shopSlug: string, filters: WarehouseFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: warehouseKeys.list(shopSlug, locale, filters),
    queryFn: () => warehouseService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useWarehouseLookup(shopSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: [...warehouseKeys.all(shopSlug), "lookup", locale] as const,
    queryFn: () => warehouseService.lookup(shopSlug),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}

export function useWarehouse(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: warehouseKeys.detail(shopSlug, locale, id),
    queryFn: () => warehouseService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateWarehouse(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: WarehouseCreateInput) => warehouseService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Warehouse created.");
      qc.invalidateQueries({ queryKey: warehouseKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create warehouse."),
  });
}

export function useUpdateWarehouse(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: WarehouseUpdateInput }) =>
      warehouseService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Warehouse updated.");
      qc.invalidateQueries({ queryKey: warehouseKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update warehouse."),
  });
}

export function useDeleteWarehouse(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => warehouseService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Warehouse deleted.");
      qc.invalidateQueries({ queryKey: warehouseKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete warehouse."),
  });
}

export function useRestoreWarehouse(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => warehouseService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success("Warehouse restored.");
      qc.invalidateQueries({ queryKey: warehouseKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore warehouse."),
  });
}

export function useToggleWarehouseActive(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => warehouseService.toggleActive(shopSlug, id),
    onSuccess: (result) => {
      toast.success(result.data.is_active ? "Warehouse activated." : "Warehouse deactivated.");
      qc.invalidateQueries({ queryKey: warehouseKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle warehouse."),
  });
}

export function useUpdateWarehouseSettings(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: WarehouseSettingsInput }) =>
      warehouseService.updateSettings(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Warehouse settings saved.");
      qc.invalidateQueries({ queryKey: warehouseKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to save settings."),
  });
}
