/**
 * ProductType Hooks — React Query wrappers around productTypeService.
 *
 * Pattern mirrors use-categories.ts. Every mutation invalidates
 * productTypeKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  productTypeService,
  type CreateProductTypeInput,
  type ProductTypeFilters,
  type UpdateProductTypeInput,
} from "@/services/product-type-service";
import { useTranslation } from "@/providers/app-provider";
import { productTypeKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useProductTypes(brandSlug: string, filters: ProductTypeFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productTypeKeys.list(brandSlug, locale, filters),
    queryFn: () => productTypeService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useProductType(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productTypeKeys.detail(brandSlug, locale, id),
    queryFn: () => productTypeService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

export function useProductTypeLookup(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productTypeKeys.lookup(brandSlug, locale),
    queryFn: () => productTypeService.lookup(brandSlug),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateProductType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateProductTypeInput) => productTypeService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Product type created.");
      qc.invalidateQueries({ queryKey: productTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create product type."),
  });
}

export function useUpdateProductType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateProductTypeInput }) =>
      productTypeService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Product type updated.");
      qc.invalidateQueries({ queryKey: productTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update product type."),
  });
}

export function useDeleteProductType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productTypeService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Product type deleted.");
      qc.invalidateQueries({ queryKey: productTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete product type."),
  });
}

export function useRestoreProductType(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productTypeService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Product type restored.");
      qc.invalidateQueries({ queryKey: productTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore product type."),
  });
}

export function useToggleProductTypeStatus(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productTypeService.toggleStatus(brandSlug, id),
    onSuccess: () => {
      toast.success("Status updated.");
      qc.invalidateQueries({ queryKey: productTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle status."),
  });
}

export function useBulkDeleteProductTypes(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => productTypeService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.product_type.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.product_type.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.product_type.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: productTypeKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}
