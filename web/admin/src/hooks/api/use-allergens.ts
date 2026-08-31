import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  allergenService,
  type AllergenFilters,
  type CreateAllergenInput,
  type UpdateAllergenInput,
} from "@/services/allergen-service";
import { useTranslation } from "@/providers/app-provider";
import { allergenKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useAllergens(brandSlug: string, filters: AllergenFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: allergenKeys.list(brandSlug, locale, filters),
    queryFn: () => allergenService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useAllergen(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: allergenKeys.detail(brandSlug, locale, id),
    queryFn: () => allergenService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateAllergen(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateAllergenInput) => allergenService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Allergen created.");
      qc.invalidateQueries({ queryKey: allergenKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create allergen."),
  });
}

export function useUpdateAllergen(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateAllergenInput }) =>
      allergenService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Allergen updated.");
      qc.invalidateQueries({ queryKey: allergenKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update allergen."),
  });
}

export function useDeleteAllergen(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => allergenService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Allergen deleted.");
      qc.invalidateQueries({ queryKey: allergenKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete allergen."),
  });
}

export function useRestoreAllergen(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => allergenService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Allergen restored.");
      qc.invalidateQueries({ queryKey: allergenKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore allergen."),
  });
}

export function useBulkDeleteAllergens(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => allergenService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.allergen.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.allergen.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.allergen.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: allergenKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete allergens."),
  });
}

export function useToggleAllergenStatus(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, currentIsActive }: { id: string; currentIsActive: boolean }) =>
      allergenService.toggleStatus(brandSlug, id, currentIsActive),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: allergenKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update allergen status."),
  });
}
