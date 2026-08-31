/**
 * Zone Template Hooks — React Query wrappers around zoneTemplateService
 * (issue #890, HQ brand scope).
 *
 * Every mutation shows toast.success or toast.error and invalidates
 * related queries (MANDATORY per frontend.md).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { zoneTemplateService } from "@/services/zone-template-service";
import type {
  CreateZoneTemplateInput,
  UpdateZoneTemplateInput,
  ZoneTemplateFilters,
} from "@/types/hq-tables";
import { useTranslation } from "@/providers/app-provider";
import { tableTemplateKeys, zoneTemplateKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useZoneTemplates(brandSlug: string, filters: ZoneTemplateFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: zoneTemplateKeys.list(brandSlug, locale, filters),
    queryFn: () => zoneTemplateService.list(brandSlug, filters),
    enabled: !!brandSlug,
  });
}

export function useZoneTemplateLookup(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: zoneTemplateKeys.lookup(brandSlug, locale),
    queryFn: () => zoneTemplateService.lookup(brandSlug),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateZoneTemplate(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateZoneTemplateInput) => zoneTemplateService.create(brandSlug, data),
    onSuccess: () => {
      toast.success(t("hq.tables.toast.zone_created"));
      qc.invalidateQueries({ queryKey: zoneTemplateKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.zone_create_failed")),
  });
}

export function useUpdateZoneTemplate(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateZoneTemplateInput }) =>
      zoneTemplateService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success(t("hq.tables.toast.zone_updated"));
      qc.invalidateQueries({ queryKey: zoneTemplateKeys.all(brandSlug) });
      // Table templates embed the zone summary → refresh them too.
      qc.invalidateQueries({ queryKey: tableTemplateKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.zone_update_failed")),
  });
}

export function useDeleteZoneTemplate(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => zoneTemplateService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success(t("hq.tables.toast.zone_deleted"));
      qc.invalidateQueries({ queryKey: zoneTemplateKeys.all(brandSlug) });
      // Zone template delete cascades to table templates on the server.
      qc.invalidateQueries({ queryKey: tableTemplateKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.zone_delete_failed")),
  });
}

export function useToggleZoneTemplateActive(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => zoneTemplateService.toggleActive(brandSlug, id),
    onSuccess: (result) => {
      toast.success(
        result.data.is_active
          ? t("hq.tables.toast.zone_activated")
          : t("hq.tables.toast.zone_deactivated")
      );
      qc.invalidateQueries({ queryKey: zoneTemplateKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.zone_toggle_failed")),
  });
}
