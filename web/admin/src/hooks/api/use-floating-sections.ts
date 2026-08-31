/**
 * Floating Section Hooks — React wrappers around floatingSectionService.
 *
 * Pattern mirrors use-menus.ts. Every mutation invalidates
 * floatingSectionKeys.all(brandSlug).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { floatingSectionService } from "@/services/floating-section-service";
import type {
  FloatingSectionFilters,
  CreateFloatingSectionInput,
  UpdateFloatingSectionInput,
  AddFloatingSectionProductsInput,
  ReorderFloatingSectionProductsInput,
  CloneFloatingSectionToBranchInput,
} from "@/types/models/FloatingSection";
import { useTranslation } from "@/providers/app-provider";
import { floatingSectionKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useFloatingSections(brandSlug: string, filters: FloatingSectionFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: floatingSectionKeys.list(brandSlug, locale, filters),
    queryFn: () => floatingSectionService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useFloatingSection(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: floatingSectionKeys.detail(brandSlug, locale, id),
    queryFn: () => floatingSectionService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

// =========================================================================
//  Mutations — CRUD
// =========================================================================

export function useCreateFloatingSection(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateFloatingSectionInput) =>
      floatingSectionService.create(brandSlug, data),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.toast_created"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.toast_error")),
  });
}

export function useUpdateFloatingSection(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateFloatingSectionInput }) =>
      floatingSectionService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.toast_updated"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.toast_error")),
  });
}

export function useDeleteFloatingSection(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => floatingSectionService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.toast_deleted"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.toast_error")),
  });
}

export function useBulkDeleteFloatingSections(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (ids: string[]) => floatingSectionService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.floating_section.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.floating_section.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.floating_section.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useCloneFloatingSectionToBranch(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: CloneFloatingSectionToBranchInput }) =>
      floatingSectionService.cloneToBranch(brandSlug, id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to clone floating section."),
  });
}

export function useDuplicateFloatingSection(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => floatingSectionService.duplicate(brandSlug, id),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.toast_duplicated"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to duplicate floating section."),
  });
}

// =========================================================================
//  Mutations — Products
// =========================================================================

export function useAddFloatingSectionProducts(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      sectionId,
      data,
    }: {
      sectionId: string;
      data: AddFloatingSectionProductsInput;
    }) => floatingSectionService.addProducts(brandSlug, sectionId, data),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.products.toast_added"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.products.toast_error")),
  });
}

export function useRemoveFloatingSectionProduct(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ sectionId, productId }: { sectionId: string; productId: string }) =>
      floatingSectionService.removeProduct(brandSlug, sectionId, productId),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.products.toast_removed"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.products.toast_error")),
  });
}

export function useToggleFloatingSectionProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ sectionId, productId }: { sectionId: string; productId: string }) =>
      floatingSectionService.toggleProduct(brandSlug, sectionId, productId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle product."),
  });
}

export function useUpdateFloatingSectionProductTaxType(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      sectionId,
      productId,
      taxTypeId,
    }: {
      sectionId: string;
      productId: string;
      taxTypeId: string | null;
    }) => floatingSectionService.updateProductTaxType(brandSlug, sectionId, productId, taxTypeId),
    onSuccess: (_data, variables) => {
      toast.success(
        variables.taxTypeId === null
          ? t("hq.menus.items.tax.toast_cleared")
          : t("hq.menus.items.tax.toast_saved")
      );
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.items.tax.toast_error")),
  });
}

export function useToggleFloatingSectionProductSku(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      sectionId,
      productId,
      skuId,
    }: {
      sectionId: string;
      productId: string;
      skuId: string;
    }) => floatingSectionService.toggleSku(brandSlug, sectionId, productId, skuId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle SKU."),
  });
}

export function useOverrideFloatingSectionSkuPrice(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      sectionId,
      productId,
      skuId,
      sellingPrice,
    }: {
      sectionId: string;
      productId: string;
      skuId: string;
      sellingPrice: number;
    }) =>
      floatingSectionService.overrideSkuPrice(brandSlug, sectionId, productId, skuId, sellingPrice),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.products.toast_price_saved"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.products.toast_error")),
  });
}

export function useResetFloatingSectionSkuPrice(brandSlug: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      sectionId,
      productId,
      skuId,
    }: {
      sectionId: string;
      productId: string;
      skuId: string;
    }) => floatingSectionService.resetSkuPrice(brandSlug, sectionId, productId, skuId),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.products.toast_price_reset"));
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.products.toast_error")),
  });
}

export function useReorderFloatingSectionProducts(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      sectionId,
      data,
    }: {
      sectionId: string;
      data: ReorderFloatingSectionProductsInput;
    }) => floatingSectionService.reorderProducts(brandSlug, sectionId, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to reorder products."),
  });
}
