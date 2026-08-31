/**
 * Product Option Hooks — React-Query wrappers around productOptionService.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import {
  productOptionService,
  type CreateProductOptionInput,
  type ExpandProductOptionInput,
  type SyncOptionValuesInput,
  type UpdateProductOptionInput,
} from "@/services/product-option-service";
import { useTranslation } from "@/providers/app-provider";
import { productKeys, productOptionKeys } from "./query-keys";

export function useProductOptions(brandSlug: string, productId: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productOptionKeys.listForProduct(brandSlug, locale, productId),
    queryFn: () => productOptionService.list(brandSlug, productId),
    enabled: !!productId,
  });
}

export function useCreateProductOption(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateProductOptionInput) =>
      productOptionService.create(brandSlug, productId, data),
    onSuccess: () => {
      toast.success("Option added.");
      qc.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: productKeys.detailAll(brandSlug, productId) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to add option."),
  });
}

export function useUpdateProductOption(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ optionId, data }: { optionId: string; data: UpdateProductOptionInput }) =>
      productOptionService.update(brandSlug, optionId, data),
    onSuccess: () => {
      toast.success("Option updated.");
      qc.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: productKeys.detailAll(brandSlug, productId) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update option."),
  });
}

export function useDeleteProductOption(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (optionId: string) => productOptionService.delete(brandSlug, optionId),
    onSuccess: () => {
      toast.success("Option deleted.");
      qc.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: productKeys.detailAll(brandSlug, productId) });
    },
    onError: (e: Error) => {
      // 409 OPTION_IN_USE renders blocking SKU codes — surface the message verbatim
      toast.error(e.message || "Failed to delete option.");
    },
  });
}

/**
 * Apply a full diff to an option's values in one transaction. Replaces the
 * per-row PUT/POST/DELETE storm the form used to trigger on every blur.
 */
export function useSyncOptionValues(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ optionId, data }: { optionId: string; data: SyncOptionValuesInput }) =>
      productOptionService.syncValues(brandSlug, optionId, data),
    onSuccess: (result) => {
      const createdCount = result.data.created_skus.length;
      toast.success(
        createdCount > 0 ? `Đã lưu. ${createdCount} biến thể mới được tạo.` : "Đã lưu thay đổi."
      );
      qc.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: productKeys.detailAll(brandSlug, productId) });
    },
    onError: (e: Error) => {
      // 409 OPTION_VALUE_IN_USE is handled by the caller (it pops a force-delete
      // dialog). Suppress the generic toast so the dialog is the only feedback.
      if (e instanceof ApiError && e.status === 409 && e.body?.error === "OPTION_VALUE_IN_USE") {
        return;
      }
      toast.error(e.message || "Lưu thay đổi thất bại.");
    },
  });
}

export function useExpandProductOption(brandSlug: string, productId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: ExpandProductOptionInput) =>
      productOptionService.expand(brandSlug, productId, data),
    onSuccess: (result) => {
      const { updated_skus, created_skus } = result.data;
      toast.success(
        `Option added. ${updated_skus} SKU(s) updated, ${created_skus.length} new SKU(s) created.`
      );
      qc.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: productKeys.detailAll(brandSlug, productId) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to expand option."),
  });
}
