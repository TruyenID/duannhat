/**
 * Menu Promotion Hooks — React Query wrappers around menuPromotionService.
 *
 * Mutations toast + invalidate the shop-scope namespace; HQ list is a
 * separate namespace because it aggregates across shops and uses different
 * filters.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  menuPromotionService,
  type CreateMenuPromotionInput,
  type MenuPromotionFilters,
  type UpdateMenuPromotionInput,
} from "@/services/menu-promotion-service";
import { menuPromotionHqKeys, menuPromotionShopKeys } from "./query-keys";
import { useTranslation } from "@/providers/app-provider";

// =========================================================================
//  Queries — Shop scope
// =========================================================================

export function useShopPromotions(shopSlug: string, filters: MenuPromotionFilters = {}) {
  return useQuery({
    queryKey: menuPromotionShopKeys.list(shopSlug, filters),
    queryFn: () => menuPromotionService.list(shopSlug, filters),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

export function useShopPromotion(shopSlug: string, id: string) {
  return useQuery({
    queryKey: menuPromotionShopKeys.detail(shopSlug, id),
    queryFn: () => menuPromotionService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

export function useShopPromotionRecentItems(shopSlug: string, id: string) {
  return useQuery({
    queryKey: menuPromotionShopKeys.recentItems(shopSlug, id),
    queryFn: () => menuPromotionService.recentItems(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

// =========================================================================
//  Mutations — Shop scope
// =========================================================================

export function useCreateShopPromotion(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateMenuPromotionInput) => menuPromotionService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Promotion created.");
      qc.invalidateQueries({ queryKey: menuPromotionShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create promotion."),
  });
}

export function useUpdateShopPromotion(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMenuPromotionInput }) =>
      menuPromotionService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Promotion updated.");
      qc.invalidateQueries({ queryKey: menuPromotionShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update promotion."),
  });
}

export function useDeleteShopPromotion(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuPromotionService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Promotion deleted.");
      qc.invalidateQueries({ queryKey: menuPromotionShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete promotion."),
  });
}

export function useToggleShopPromotion(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => menuPromotionService.toggle(shopSlug, id),
    onSuccess: () => {
      toast.success("Promotion toggled.");
      qc.invalidateQueries({ queryKey: menuPromotionShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle promotion."),
  });
}

export function useRestoreShopPromotion(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => menuPromotionService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success(t("toast.promotion.restored"));
      qc.invalidateQueries({ queryKey: menuPromotionShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useBulkDeleteShopPromotions(shopSlug: string, onDeleted?: (ids: string[]) => void) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => menuPromotionService.bulkDelete(shopSlug, ids),
    onSuccess: (data, ids) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.promotion.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.promotion.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.promotion.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: menuPromotionShopKeys.all(shopSlug) });
      if (onDeleted) {
        const deletedIds = ids.filter((id) => !data.errors.some((e) => e.id === id));
        onDeleted(deletedIds);
      }
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

// =========================================================================
//  Queries — HQ scope (read-only cross-shop)
// =========================================================================

export function useHqPromotions(brandSlug: string, filters: MenuPromotionFilters = {}) {
  return useQuery({
    queryKey: menuPromotionHqKeys.list(brandSlug, filters),
    queryFn: () => menuPromotionService.hqList(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}
