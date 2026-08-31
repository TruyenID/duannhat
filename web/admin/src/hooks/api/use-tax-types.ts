/**
 * TaxType Hooks — React Query wrappers around taxTypeService.
 *
 * Pattern mirrors use-product-types.ts. Every mutation invalidates
 * taxTypeKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  taxTypeService,
  type CreateTaxTypeInput,
  type TaxTypeFilters,
  type UpdateTaxTypeInput,
} from "@/services/tax-type-service";
import { useTranslation } from "@/providers/app-provider";
import { taxTypeKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useTaxTypes(brandSlug: string, filters: TaxTypeFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: taxTypeKeys.list(brandSlug, locale, filters),
    queryFn: () => taxTypeService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useTaxType(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: taxTypeKeys.detail(brandSlug, locale, id),
    queryFn: () => taxTypeService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

export function useTaxTypeLookup(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: taxTypeKeys.lookup(brandSlug, locale),
    queryFn: () => taxTypeService.lookup(brandSlug),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateTaxType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateTaxTypeInput) => taxTypeService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Tax type created.");
      qc.invalidateQueries({ queryKey: taxTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create tax type."),
  });
}

export function useUpdateTaxType(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateTaxTypeInput }) =>
      taxTypeService.update(brandSlug, id, data),
    onSuccess: (result) => {
      toast.success("Tax type updated.");

      // #1129 — the edit is allowed (plan-043 Q6), but an HQ rate change hits
      // EVERY branch of the brand at once. When one is mid-shift, that shift
      // now spans two rates and its Z-report will not reconcile, so say so
      // with the branch names rather than leaving it to a static hint.
      const affected = result.meta?.open_shift_branches ?? [];
      if (affected.length > 0) {
        toast.warning(
          t("toast.tax_type.rate_changed_open_shift", {
            count: affected.length,
            names: affected.map((b) => b.name ?? b.id).join(", "),
          }),
          { duration: 12000 }
        );
      }

      qc.invalidateQueries({ queryKey: taxTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update tax type."),
  });
}

export function useDeleteTaxType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => taxTypeService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Tax type deleted.");
      qc.invalidateQueries({ queryKey: taxTypeKeys.all(brandSlug) });
    },
    // Note: the 409 TAX_TYPE_IN_USE case is caught in the caller so it can
    // surface the usage breakdown + "deactivate instead" action. No toast here.
    onError: (e: Error) => toast.error(e.message || "Failed to delete tax type."),
  });
}

export function useRestoreTaxType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => taxTypeService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Tax type restored.");
      qc.invalidateQueries({ queryKey: taxTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore tax type."),
  });
}

export function useToggleTaxTypeStatus(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => taxTypeService.toggleStatus(brandSlug, id),
    onSuccess: () => {
      toast.success("Status updated.");
      qc.invalidateQueries({ queryKey: taxTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle status."),
  });
}

export function useBulkDeleteTaxTypes(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => taxTypeService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.tax_type.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.tax_type.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.tax_type.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: taxTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
