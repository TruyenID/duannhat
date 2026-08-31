/**
 * Category Hooks — React wrappers around categoryService.
 *
 * Pattern mirrors use-products.ts:
 * - useCategories()       → useQuery  → categoryService.list()
 * - useCategoryLookup()   → useQuery  → categoryService.lookup()
 * - useCreateCategory()   → useMutation → service.create() + toast
 * - …
 *
 * Every mutation invalidates categoryKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  categoryService,
  type CategoryFilters,
  type CreateCategoryInput,
  type UpdateCategoryInput,
} from "@/services/category-service";
import { useTranslation } from "@/providers/app-provider";
import { categoryKeys, productKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useCategories(brandSlug: string, filters: CategoryFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: categoryKeys.list(brandSlug, locale, filters),
    queryFn: () => categoryService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useCategory(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: categoryKeys.detail(brandSlug, locale, id),
    queryFn: () => categoryService.getById(brandSlug, id),
    enabled: !!id,
  });
}

export function useCategoryLookup(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: categoryKeys.dropdown(brandSlug, locale),
    queryFn: () => categoryService.lookup(brandSlug),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateCategory(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateCategoryInput) => categoryService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Category created.");
      qc.invalidateQueries({ queryKey: categoryKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create category."),
  });
}

export function useUpdateCategory(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCategoryInput }) =>
      categoryService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Category updated.");
      qc.invalidateQueries({ queryKey: categoryKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update category."),
  });
}

export function useDeleteCategory(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => categoryService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Category deleted.");
      qc.invalidateQueries({ queryKey: categoryKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete category."),
  });
}

export function useRestoreCategory(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => categoryService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Category restored.");
      qc.invalidateQueries({ queryKey: categoryKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore category."),
  });
}

export function useBulkDeleteCategories(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => categoryService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.category.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.category.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.category.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: categoryKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useImportCategories(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ file, brand_id }: { file: File; brand_id: string }) =>
      categoryService.import(brandSlug, file, brand_id),
    onSuccess: (result) => {
      const imported = result.imported ?? result.created_count ?? result.success_count ?? 0;
      toast.success(`Imported ${imported} categor${imported === 1 ? "y" : "ies"}.`);
      qc.invalidateQueries({ queryKey: categoryKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Import failed."),
  });
}

/**
 * #1074 — bulk-assign a tax type to every product in a category. Invalidates
 * BOTH category and product caches: the mutation rewrites
 * products.tax_type_id, so stale product lists would show the old tax type.
 * Success toast is the CALLER's job (it knows the translated copy + count).
 */
export function useApplyCategoryTaxType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, taxTypeId }: { id: string; taxTypeId: string | null }) =>
      categoryService.applyTaxType(brandSlug, id, taxTypeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: categoryKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to apply tax type."),
  });
}
