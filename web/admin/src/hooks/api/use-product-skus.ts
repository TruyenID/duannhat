/**
 * Product SKU Hooks — React-Query wrappers around productSkuService.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import {
  productSkuService,
  type CreateProductSkuInput,
  type ProductSkuFilters,
  type UpdateProductSkuInput,
} from "@/services/product-sku-service";
import { useTranslation } from "@/providers/app-provider";
import { productKeys, productSkuKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useProductSkusForProduct(
  brandSlug: string,
  productId: string,
  filters: ProductSkuFilters = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productSkuKeys.listForProduct(brandSlug, locale, productId, filters),
    queryFn: () => productSkuService.listForProduct(brandSlug, productId, filters),
    enabled: !!productId,
  });
}

export function useProductSku(brandSlug: string, skuId: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productSkuKeys.detail(brandSlug, locale, skuId),
    queryFn: () => productSkuService.getById(brandSlug, skuId),
    enabled: !!skuId,
  });
}

export function useProductSkuUsage(brandSlug: string, skuId: string, enabled = false) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productSkuKeys.usage(brandSlug, locale, skuId),
    queryFn: () => productSkuService.checkUsage(brandSlug, skuId),
    enabled: enabled && !!skuId,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

function invalidate(qc: ReturnType<typeof useQueryClient>, brandSlug: string, productId?: string) {
  qc.invalidateQueries({ queryKey: productSkuKeys.all(brandSlug) });
  if (productId) {
    qc.invalidateQueries({ queryKey: productKeys.detailAll(brandSlug, productId) });
  }
}

export function useCreateProductSku(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreateProductSkuInput) =>
      productSkuService.create(brandSlug, productId, data),
    onSuccess: () => {
      toast.success(t("toast.product.sku_created"));
      invalidate(qc, brandSlug, productId);
    },
    onError: (e: Error) => toast.error(e.message || t("toast.product.sku_create_failed")),
  });
}

export function useUpdateProductSku(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ skuId, data }: { skuId: string; data: UpdateProductSkuInput }) =>
      productSkuService.update(brandSlug, skuId, data),
    onSuccess: () => {
      toast.success("SKU updated.");
      invalidate(qc, brandSlug, productId);
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update SKU."),
  });
}

export function useDeleteProductSku(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (skuId: string) => productSkuService.delete(brandSlug, skuId),
    onSuccess: () => {
      toast.success("SKU deleted.");
      invalidate(qc, brandSlug, productId);
    },
    onError: (e: Error) => {
      // 409 SKU_IN_MENU is handled by the component with a blocking-menus dialog.
      if (e instanceof ApiError && e.status === 409 && e.body.error === "SKU_IN_MENU") {
        return;
      }
      toast.error(e.message || "Failed to delete SKU.");
    },
  });
}

export function useRestoreProductSku(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (skuId: string) => productSkuService.restore(brandSlug, skuId),
    onSuccess: () => {
      toast.success("SKU restored.");
      invalidate(qc, brandSlug, productId);
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore SKU."),
  });
}

export function useToggleProductSkuStatus(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (skuId: string) => productSkuService.toggleStatus(brandSlug, skuId),
    onSuccess: () => {
      toast.success("SKU status updated.");
      invalidate(qc, brandSlug, productId);
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle SKU status."),
  });
}

export function useGenerateSkuCombinations(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => productSkuService.generateCombinations(brandSlug, productId),
    onSuccess: (result) => {
      toast.success(
        `${result.created_count} SKU${result.created_count === 1 ? "" : "s"} generated.`
      );
      invalidate(qc, brandSlug, productId);
    },
    onError: (e: Error) => toast.error(e.message || "Failed to generate combinations."),
  });
}

export function useSyncSkuImages(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ skuId, fileIds }: { skuId: string; fileIds: string[] }) =>
      productSkuService.syncImages(brandSlug, skuId, fileIds),
    onSuccess: () => {
      toast.success(t("toast.product.gallery_synced"));
      invalidate(qc, brandSlug, productId);
    },
    onError: (e: Error) => toast.error(e.message || t("toast.product.gallery_sync_failed")),
  });
}
