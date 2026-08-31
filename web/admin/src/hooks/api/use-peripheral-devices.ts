/**
 * Peripheral Device Hooks — React Query wrappers for shop peripheral management.
 *
 * Every mutation toasts on success AND on failure (localized), per the app's
 * mutation-feedback contract.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  peripheralDeviceShopService,
  type CreatePeripheralDeviceInput,
  type PeripheralDeviceFilters,
  type UpdatePeripheralDeviceInput,
} from "@/services/peripheral-device-service";
import { useTranslation } from "@/providers/app-provider";
import { peripheralDeviceShopKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useShopPeripheralDevices(shopSlug: string, filters: PeripheralDeviceFilters = {}) {
  return useQuery({
    queryKey: peripheralDeviceShopKeys.list(shopSlug, filters),
    queryFn: () => peripheralDeviceShopService.list(shopSlug, filters),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateShopPeripheralDevice(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreatePeripheralDeviceInput) =>
      peripheralDeviceShopService.create(shopSlug, data),
    onSuccess: () => {
      toast.success(t("shop.peripherals.toast.created"));
      qc.invalidateQueries({ queryKey: peripheralDeviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.peripherals.toast.create_failed")),
  });
}

export function useUpdateShopPeripheralDevice(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdatePeripheralDeviceInput }) =>
      peripheralDeviceShopService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success(t("shop.peripherals.toast.updated"));
      qc.invalidateQueries({ queryKey: peripheralDeviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.peripherals.toast.update_failed")),
  });
}

export function useDeleteShopPeripheralDevice(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => peripheralDeviceShopService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success(t("shop.peripherals.toast.deleted"));
      qc.invalidateQueries({ queryKey: peripheralDeviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.peripherals.toast.delete_failed")),
  });
}

export function useRestoreShopPeripheralDevice(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => peripheralDeviceShopService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success(t("shop.peripherals.toast.restored"));
      qc.invalidateQueries({ queryKey: peripheralDeviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.peripherals.toast.restore_failed")),
  });
}
