/**
 * Shop Hooks — React-Query wrappers around shopService.
 *
 * Pattern mirrors use-menus.ts:
 *   useShops()      → list scoped to a brand (paginated)
 *   useShop()       → single shop by slug (used by the detail page)
 *   useCreateShop() → mutation to create a new shop under a brand
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import {
  shopService,
  type CreateShopInput,
  type ShopFilters,
  type UpdateShopInput,
} from "@/services/shop-service";
import { shopKeys } from "./query-keys";

export function useShops(brandSlug: string, filters: ShopFilters = {}) {
  return useQuery({
    queryKey: shopKeys.list(brandSlug, filters),
    queryFn: () => shopService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useShop(shopSlug: string) {
  return useQuery({
    queryKey: shopKeys.detail(shopSlug),
    queryFn: () => shopService.getBySlug(shopSlug),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateShop(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateShopInput) => shopService.create(brandSlug, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: shopKeys.all(brandSlug) });
    },
  });
}

export function useUpdateShop(brandSlug: string, shopId: string, shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: UpdateShopInput) => shopService.update(brandSlug, shopId, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: shopKeys.detail(shopSlug) });
      qc.invalidateQueries({ queryKey: shopKeys.all(brandSlug) });
    },
  });
}

export function useDeleteShop(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (shopId: string) => shopService.delete(brandSlug, shopId),
    onSuccess: () => {
      toast.success("Shop deleted.");
      qc.invalidateQueries({ queryKey: shopKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete shop."),
  });
}

export function useBulkDeleteShops(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => shopService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.shop.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(t("toast.shop.bulk_skipped", { deleted: data.deleted, skipped, names }));
        }
      } else {
        toast.success(t("toast.shop.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: shopKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to bulk delete shops."),
  });
}

export function useToggleShopStatus(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (shopId: string) => shopService.toggleStatus(brandSlug, shopId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: shopKeys.all(brandSlug) });
    },
  });
}

export function useRestoreShop(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (shopId: string) => shopService.restore(brandSlug, shopId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: shopKeys.all(brandSlug) });
    },
  });
}
