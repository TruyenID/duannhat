/**
 * Shop Floating Section Hooks — React wrappers around shopFloatingSectionService.
 *
 * Mirrors use-floating-sections.ts / use-floating-section-schedules.ts, scoped
 * to the shop's own clones. Every mutation invalidates the relevant
 * shopFloatingSection* query keys.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch } from "@/lib/api";
import { shopFloatingSectionService } from "@/services/shop-floating-section-service";
import type { FloatingSectionFilters } from "@/types/models/FloatingSection";
import type {
  MenuProductToppingItemOverride,
  ShopToppingOverrideSyncRow,
} from "@/types/shop";
import { useTranslation } from "@/providers/app-provider";
import { shopFloatingSectionKeys, shopFloatingSectionScheduleKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useShopFloatingSections(shopSlug: string, filters: FloatingSectionFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: shopFloatingSectionKeys.list(shopSlug, locale, filters),
    queryFn: () => shopFloatingSectionService.list(shopSlug, filters),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

export function useShopFloatingSection(shopSlug: string, id: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: shopFloatingSectionKeys.detail(shopSlug, locale, id),
    queryFn: () => shopFloatingSectionService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

export function useSyncShopFloatingSection(shopSlug: string, sectionId: string) {
  const { t, locale } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => shopFloatingSectionService.sync(shopSlug, sectionId),
    onSuccess: (result) => {
      toast.success(t("shop.floating_sections.sync_success"));
      // Replace the detail cache with the freshly synced response so newly
      // added products/schedules show up without an extra refetch.
      qc.setQueryData(shopFloatingSectionKeys.detail(shopSlug, locale, sectionId), result);
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
      qc.invalidateQueries({ queryKey: shopFloatingSectionScheduleKeys.all(shopSlug, sectionId) });
    },
    onError: (e: Error) => toast.error(e.message || t("shop.floating_sections.sync_error")),
  });
}

export function useShopFloatingSectionSchedules(shopSlug: string, sectionId: string) {
  return useQuery({
    queryKey: shopFloatingSectionScheduleKeys.list(shopSlug, sectionId),
    queryFn: () => shopFloatingSectionService.listSchedules(shopSlug, sectionId),
    enabled: !!shopSlug && !!sectionId,
  });
}

// =========================================================================
//  Mutations — Products
// =========================================================================

export function useToggleShopFloatingSectionProduct(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ sectionId, productId }: { sectionId: string; productId: string }) =>
      shopFloatingSectionService.toggleProduct(shopSlug, sectionId, productId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle product."),
  });
}

export function useOverrideShopFloatingSectionSkuPrice(shopSlug: string) {
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
      shopFloatingSectionService.overrideSkuPrice(shopSlug, sectionId, productId, skuId, sellingPrice),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.products.toast_price_saved"));
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.products.toast_error")),
  });
}

export function useResetShopFloatingSectionSkuPrice(shopSlug: string) {
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
    }) => shopFloatingSectionService.resetSkuPrice(shopSlug, sectionId, productId, skuId),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.products.toast_price_reset"));
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.floating_sections.products.toast_error")),
  });
}

export function useToggleShopFloatingSectionProductSku(shopSlug: string) {
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
    }) => shopFloatingSectionService.toggleSku(shopSlug, sectionId, productId, skuId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to toggle SKU."),
  });
}

// =========================================================================
//  Mutations — Schedules
// =========================================================================

export function useToggleShopFloatingSectionSchedule(shopSlug: string, sectionId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) =>
      shopFloatingSectionService.toggleSchedule(shopSlug, sectionId, scheduleId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: shopFloatingSectionScheduleKeys.all(shopSlug, sectionId) });
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
    },
    onError: (e: Error) =>
      toast.error(e.message || "Failed to toggle schedule."),
  });
}

export function useOverrideShopFloatingSectionScheduleTime(shopSlug: string, sectionId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      scheduleId,
      data,
    }: {
      scheduleId: string;
      data: { start_time?: string; end_time?: string; days_of_week?: number };
    }) => shopFloatingSectionService.overrideScheduleTime(shopSlug, sectionId, scheduleId, data),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.schedules.toast_updated"));
      qc.invalidateQueries({ queryKey: shopFloatingSectionScheduleKeys.all(shopSlug, sectionId) });
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
    },
    onError: (e: Error) =>
      toast.error(e.message || t("hq.floating_sections.schedules.toast_error")),
  });
}

export function useResetShopFloatingSectionScheduleTimeOverride(shopSlug: string, sectionId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) =>
      shopFloatingSectionService.resetScheduleTimeOverride(shopSlug, sectionId, scheduleId),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.schedules.toast_updated"));
      qc.invalidateQueries({ queryKey: shopFloatingSectionScheduleKeys.all(shopSlug, sectionId) });
      qc.invalidateQueries({ queryKey: shopFloatingSectionKeys.all(shopSlug) });
    },
    onError: (e: Error) =>
      toast.error(e.message || t("hq.floating_sections.schedules.toast_error")),
  });
}

// =========================================================================
//  Topping overrides (tier-1 shop) — mirrors the menu topping-override hooks.
// =========================================================================

function toppingOverrideUrl(
  shopSlug: string,
  sectionId: string,
  sectionProductId: string,
  toppingGroupId: string
): string {
  return `/api/v1/shops/${shopSlug}/floating-sections/${sectionId}/products/${sectionProductId}/topping-groups/${toppingGroupId}/overrides`;
}

function toppingOverrideKey(
  shopSlug: string,
  sectionId: string,
  sectionProductId: string,
  toppingGroupId: string
): (string | undefined)[] {
  return [
    "shop",
    shopSlug,
    "floating-section",
    sectionId,
    "fsp",
    sectionProductId,
    "topping-overrides",
    toppingGroupId,
  ];
}

export function useShopFloatingSectionToppingOverrides(
  shopSlug: string,
  sectionId: string,
  sectionProductId: string,
  toppingGroupId: string
) {
  return useQuery({
    queryKey: toppingOverrideKey(shopSlug, sectionId, sectionProductId, toppingGroupId),
    queryFn: () =>
      apiFetch<{ data: MenuProductToppingItemOverride[] }>(
        toppingOverrideUrl(shopSlug, sectionId, sectionProductId, toppingGroupId)
      ),
    enabled: !!(shopSlug && sectionId && sectionProductId && toppingGroupId),
    staleTime: 30_000,
  });
}

export function useSyncShopFloatingSectionToppingOverrides(
  shopSlug: string,
  sectionId: string,
  sectionProductId: string,
  toppingGroupId: string
) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  const cacheKey = toppingOverrideKey(shopSlug, sectionId, sectionProductId, toppingGroupId);

  return useMutation({
    mutationFn: (overrides: ShopToppingOverrideSyncRow[]) =>
      apiFetch<{ data: MenuProductToppingItemOverride[] }>(
        toppingOverrideUrl(shopSlug, sectionId, sectionProductId, toppingGroupId),
        { method: "PUT", body: JSON.stringify({ overrides }) }
      ),
    onSuccess: (result) => {
      toast.success(t("toast.topping_group.overrides_saved"));
      qc.setQueryData(cacheKey, result);
    },
    onError: (e: Error) => toast.error(e.message || t("toast.topping_group.overrides_error")),
  });
}
