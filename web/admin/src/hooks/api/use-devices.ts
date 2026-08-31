/**
 * Device Hooks — React Query wrappers for HQ + Shop device management.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  deviceHqService,
  deviceShopService,
  type CreateDeviceInput,
  type DeviceFilters,
  type UpdateDeviceInput,
} from "@/services/device-service";
import { deviceHqKeys, deviceShopKeys } from "./query-keys";

// =========================================================================
//  HQ Queries
// =========================================================================

export function useHqDevices(brandSlug: string, filters: DeviceFilters = {}) {
  return useQuery({
    queryKey: deviceHqKeys.list(brandSlug, filters),
    queryFn: () => deviceHqService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useHqDevice(brandSlug: string, id: string) {
  return useQuery({
    queryKey: deviceHqKeys.detail(brandSlug, id),
    queryFn: () => deviceHqService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

// =========================================================================
//  HQ Mutations
// =========================================================================

export function useCreateHqDevice(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateDeviceInput) => deviceHqService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Device created.");
      qc.invalidateQueries({ queryKey: deviceHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create device."),
  });
}

export function useUpdateHqDevice(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateDeviceInput }) =>
      deviceHqService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Device updated.");
      qc.invalidateQueries({ queryKey: deviceHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update device."),
  });
}

export function useDeleteHqDevice(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceHqService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Device deleted.");
      qc.invalidateQueries({ queryKey: deviceHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete device."),
  });
}

export function useRestoreHqDevice(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceHqService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Device restored.");
      qc.invalidateQueries({ queryKey: deviceHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore device."),
  });
}

export function useRegeneratePairingHq(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceHqService.regeneratePairing(brandSlug, id),
    onSuccess: () => {
      toast.success("Pairing code regenerated.");
      qc.invalidateQueries({ queryKey: deviceHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to regenerate pairing code."),
  });
}

export function useRevokeHqDevice(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceHqService.revoke(brandSlug, id),
    onSuccess: () => {
      toast.success("Device revoked.");
      qc.invalidateQueries({ queryKey: deviceHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to revoke device."),
  });
}

export function useBulkDeleteHqDevices(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (ids: string[]) => deviceHqService.bulkDelete(brandSlug, ids),
    onSuccess: (result) => {
      toast.success(`${result.deleted} device(s) deleted.`);
      qc.invalidateQueries({ queryKey: deviceHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to bulk delete."),
  });
}

// =========================================================================
//  Shop Queries
// =========================================================================

export function useShopDevices(shopSlug: string, filters: DeviceFilters = {}) {
  return useQuery({
    queryKey: deviceShopKeys.list(shopSlug, filters),
    queryFn: () => deviceShopService.list(shopSlug, filters),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

// =========================================================================
//  Shop Mutations
// =========================================================================

export function useCreateShopDevice(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateDeviceInput) => deviceShopService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Device created.");
      qc.invalidateQueries({ queryKey: deviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create device."),
  });
}

export function useUpdateShopDevice(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateDeviceInput }) =>
      deviceShopService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Device updated.");
      qc.invalidateQueries({ queryKey: deviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update device."),
  });
}

export function useDeleteShopDevice(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceShopService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Device deleted.");
      qc.invalidateQueries({ queryKey: deviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete device."),
  });
}

export function useRestoreShopDevice(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceShopService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success("Device restored.");
      qc.invalidateQueries({ queryKey: deviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore device."),
  });
}

export function useRegeneratePairingShop(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceShopService.regeneratePairing(shopSlug, id),
    onSuccess: () => {
      toast.success("Pairing code regenerated.");
      qc.invalidateQueries({ queryKey: deviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to regenerate pairing code."),
  });
}

export function useRevokeShopDevice(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => deviceShopService.revoke(shopSlug, id),
    onSuccess: () => {
      toast.success("Device revoked.");
      qc.invalidateQueries({ queryKey: deviceShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to revoke device."),
  });
}
