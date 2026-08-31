/**
 * Product Option Value Hooks — React-Query wrappers around productOptionValueService.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import {
  productOptionValueService,
  type CreateProductOptionValueInput,
  type UpdateProductOptionValueInput,
} from "@/services/product-option-value-service";
import { useTranslation } from "@/providers/app-provider";
import { productKeys, productOptionKeys, productOptionValueKeys } from "./query-keys";

export function useProductOptionValues(brandSlug: string, optionId: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productOptionValueKeys.listForOption(brandSlug, locale, optionId),
    queryFn: () => productOptionValueService.list(brandSlug, optionId),
    enabled: !!optionId,
  });
}

interface MutateContext {
  productId?: string;
}

function invalidateAfterMutation(
  qc: ReturnType<typeof useQueryClient>,
  brandSlug: string,
  ctx: MutateContext = {}
) {
  qc.invalidateQueries({ queryKey: productOptionValueKeys.all(brandSlug) });
  qc.invalidateQueries({ queryKey: productOptionKeys.all(brandSlug) });
  if (ctx.productId) {
    qc.invalidateQueries({ queryKey: productKeys.detailAll(brandSlug, ctx.productId) });
  }
}

export function useCreateProductOptionValue(
  brandSlug: string,
  optionId: string,
  productId?: string
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateProductOptionValueInput) =>
      productOptionValueService.create(brandSlug, optionId, data),
    onSuccess: () => {
      toast.success("Value added.");
      invalidateAfterMutation(qc, brandSlug, { productId });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to add value."),
  });
}

export function useUpdateProductOptionValue(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ valueId, data }: { valueId: string; data: UpdateProductOptionValueInput }) =>
      productOptionValueService.update(brandSlug, valueId, data),
    onSuccess: () => {
      toast.success("Value updated.");
      invalidateAfterMutation(qc, brandSlug, { productId });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update value."),
  });
}

export function useDeleteProductOptionValue(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (valueId: string) => productOptionValueService.delete(brandSlug, valueId),
    onSuccess: () => {
      toast.success("Value deleted.");
      invalidateAfterMutation(qc, brandSlug, { productId });
    },
    onError: (e: Error) => {
      // 409 OPTION_VALUE_IN_USE is handled by the component with a confirmation dialog.
      // Suppress the generic error toast so the dialog is the only feedback shown.
      if (e instanceof ApiError && e.status === 409 && e.body.error === "OPTION_VALUE_IN_USE") {
        return;
      }
      toast.error(e.message || "Failed to delete value.");
    },
  });
}

export function useForceDeleteProductOptionValue(brandSlug: string, productId?: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (valueId: string) => productOptionValueService.forceDelete(brandSlug, valueId),
    onSuccess: () => {
      toast.success("Value and linked SKUs deleted.");
      invalidateAfterMutation(qc, brandSlug, { productId });
    },
    onError: (e: Error) => {
      // 409 SKU_IN_MENU is handled by the component — suppress generic toast
      if (e instanceof ApiError && e.status === 409 && e.body.error === "SKU_IN_MENU") {
        return;
      }
      toast.error(e.message || "Failed to delete value.");
    },
  });
}
