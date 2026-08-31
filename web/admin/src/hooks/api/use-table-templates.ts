/**
 * Table Template Hooks — React Query wrappers around tableTemplateService
 * (issue #890, HQ brand scope).
 *
 * Every mutation shows toast.success or toast.error and invalidates
 * related queries (MANDATORY per frontend.md).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { tableTemplateService } from "@/services/table-template-service";
import type {
  CreateTableTemplateInput,
  TableTemplateFilters,
  UpdateTableTemplateInput,
} from "@/types/hq-tables";
import { useTranslation } from "@/providers/app-provider";
import { tableTemplateKeys, zoneTemplateKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useTableTemplates(brandSlug: string, filters: TableTemplateFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: tableTemplateKeys.list(brandSlug, locale, filters),
    queryFn: () => tableTemplateService.list(brandSlug, filters),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

/** Invalidate both template domains — zone cards show table counts. */
function invalidateTemplates(
  qc: ReturnType<typeof useQueryClient>,
  brandSlug: string
): void {
  qc.invalidateQueries({ queryKey: tableTemplateKeys.all(brandSlug) });
  qc.invalidateQueries({ queryKey: zoneTemplateKeys.all(brandSlug) });
}

export function useCreateTableTemplate(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateTableTemplateInput) => tableTemplateService.create(brandSlug, data),
    onSuccess: () => {
      toast.success(t("hq.tables.toast.table_created"));
      invalidateTemplates(qc, brandSlug);
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.table_create_failed")),
  });
}

export function useUpdateTableTemplate(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTableTemplateInput }) =>
      tableTemplateService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success(t("hq.tables.toast.table_updated"));
      invalidateTemplates(qc, brandSlug);
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.table_update_failed")),
  });
}

export function useDeleteTableTemplate(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => tableTemplateService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success(t("hq.tables.toast.table_deleted"));
      invalidateTemplates(qc, brandSlug);
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.table_delete_failed")),
  });
}

export function useToggleTableTemplateActive(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => tableTemplateService.toggleActive(brandSlug, id),
    onSuccess: (result) => {
      toast.success(
        result.data.is_active
          ? t("hq.tables.toast.table_activated")
          : t("hq.tables.toast.table_deactivated")
      );
      invalidateTemplates(qc, brandSlug);
    },
    onError: (e: Error) => toast.error(e.message || t("hq.tables.toast.table_toggle_failed")),
  });
}
