/**
 * Zone Hooks — React Query wrappers around zoneService.
 *
 * Every mutation shows toast.success or toast.error and invalidates
 * related queries (MANDATORY per frontend.md).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { zoneService } from "@/services/zone-service";
import type { CreateZoneInput, UpdateZoneInput, ZoneFilters } from "@/types/shop";
import { useTranslation } from "@/providers/app-provider";
import { tableKeys, zoneKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useZones(shopSlug: string, filters: ZoneFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: zoneKeys.list(shopSlug, locale, filters),
    queryFn: () => zoneService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useZone(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: zoneKeys.detail(shopSlug, locale, id),
    queryFn: () => zoneService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

export function useZoneLookup(shopSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: zoneKeys.lookup(shopSlug, locale),
    queryFn: () => zoneService.lookup(shopSlug),
    enabled: !!shopSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateZone(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateZoneInput) => zoneService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Zone created.");
      qc.invalidateQueries({ queryKey: zoneKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create zone."),
  });
}

export function useUpdateZone(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateZoneInput }) =>
      zoneService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Zone updated.");
      qc.invalidateQueries({ queryKey: zoneKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update zone."),
  });
}

export function useDeleteZone(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => zoneService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Zone deleted.");
      qc.invalidateQueries({ queryKey: zoneKeys.all(shopSlug) });
      // Zone delete cascades to tables on the server.
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete zone."),
  });
}

export function useRestoreZone(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => zoneService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success("Zone restored.");
      qc.invalidateQueries({ queryKey: zoneKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore zone."),
  });
}

export function useToggleZoneActive(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => zoneService.toggleActive(shopSlug, id),
    onSuccess: (result) => {
      toast.success(result.data.is_active ? "Zone activated." : "Zone deactivated.");
      qc.invalidateQueries({ queryKey: zoneKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle zone."),
  });
}
