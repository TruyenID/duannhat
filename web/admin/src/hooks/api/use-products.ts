/**
 * Product Hooks — React-Query wrappers around productService.
 *
 * Mirrors use-categories.ts: every mutation toasts on success/failure and
 * invalidates productKeys.all(brandSlug). Workflow mutations
 * (submit/approve/reject/activate/deactivate) hit the dedicated transition
 * endpoints so the BE state machine (ProductService::assertStatus) stays
 * authoritative — status changes do NOT go through PUT /products/{id}.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import {
  productService,
  type CreateProductInput,
  type CreateProductWithOptionsInput,
  type ProductFilters,
  type UpdateProductInput,
} from "@/services/product-service";
import { useTranslation } from "@/providers/app-provider";
import { productKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useProducts(brandSlug: string, filters: ProductFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productKeys.list(brandSlug, locale, filters),
    queryFn: () => productService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

/**
 * Fetch the brand's ENTIRE product catalog matching `filters` (all pages).
 *
 * Use this instead of `useProducts({ per_page: N })` whenever the UI needs to
 * search/filter over the full set client-side (e.g. the menu-builder picker) —
 * the backend caps `per_page` at 100, so a fixed page size silently drops
 * products once a brand exceeds it.
 */
export function useAllProducts(
  brandSlug: string,
  filters: Omit<ProductFilters, "page" | "per_page"> = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productKeys.list(brandSlug, locale, { ...filters, __all: true }),
    queryFn: () => productService.listAll(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useProduct(brandSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productKeys.detail(brandSlug, locale, id),
    queryFn: () => productService.getById(brandSlug, id),
    enabled: !!id,
  });
}

export function useProductLookup(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: productKeys.lookup(brandSlug, locale),
    queryFn: () => productService.lookup(brandSlug),
    enabled: !!brandSlug,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateProductInput) => productService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Product created.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create product."),
  });
}

/**
 * Shopify-style nested create. Submits the product, its options, its
 * option values, and its SKUs in a single backend transaction. Used by
 * the full create page at `/hq/[brandSlug]/products/new`. The
 * quick-add modal continues to call `useCreateProduct` above.
 */
export function useCreateProductWithOptions(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateProductWithOptionsInput) =>
      productService.createWithOptions(brandSlug, data),
    onSuccess: () => {
      toast.success("Product created with options.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create product."),
  });
}

export function useUpdateProduct(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateProductInput }) =>
      productService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Product updated.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => {
      // The BE state machine rejects any illegal lifecycle move on the update
      // path with a structured 422 code (godx-jp/godx-tempo#124). Status is
      // never sent from the edit form, but translate the code defensively so
      // the failure is legible rather than a raw backend message.
      if (e instanceof ApiError && e.status === 422) {
        const code = (e.body as { code?: string })?.code;
        if (code === "INVALID_STATUS_TRANSITION") {
          toast.error(t("toast.product.invalid_status_transition"));
          return;
        }
      }
      toast.error(e.message || "Failed to update product.");
    },
  });
}

export function useDeleteProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Product deleted.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete product."),
  });
}

export function useRestoreProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success("Product restored.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore product."),
  });
}

export function useBulkDeleteProducts(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => productService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.product.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(t("toast.product.bulk_skipped", { deleted: data.deleted, skipped, names }));
        }
      } else {
        toast.success(t("toast.product.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

// =========================================================================
//  Workflow mutations
// =========================================================================

export function useSubmitProductForApproval(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productService.submitForApproval(brandSlug, id),
    onSuccess: () => {
      toast.success("Đã gửi duyệt.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Gửi duyệt thất bại."),
  });
}

export function useApproveProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productService.approve(brandSlug, id),
    onSuccess: () => {
      toast.success("Đã duyệt sản phẩm.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Duyệt thất bại."),
  });
}

export function useRejectProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      productService.reject(brandSlug, id, reason),
    onSuccess: () => {
      toast.success("Đã từ chối sản phẩm.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Từ chối thất bại."),
  });
}

export function useActivateProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productService.activate(brandSlug, id),
    onSuccess: () => {
      toast.success("Sản phẩm đã được kích hoạt.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Kích hoạt thất bại."),
  });
}

export function useDeactivateProduct(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productService.deactivate(brandSlug, id),
    onSuccess: () => {
      toast.success("Sản phẩm đã ngừng kinh doanh.");
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Ngừng kinh doanh thất bại."),
  });
}

export function useImportProducts(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ file, brand_id }: { file: File; brand_id: string }) =>
      productService.import(brandSlug, file, brand_id),
    // Refresh the list either way — even partial-success runs create rows.
    onSuccess: () => qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) }),
    onError: (_e, _vars, _ctx) => {
      qc.invalidateQueries({ queryKey: productKeys.all(brandSlug) });
    },
    // Toasts (success / partial / failure) are owned by the dialog — it has
    // i18n + full per-row context. Hook just keeps cache fresh.
  });
}
