/**
 * Menu Schedule Hooks — React wrappers around menuScheduleService.
 *
 * - useMenuSchedules()        → useQuery   → list schedules for a menu
 * - useCreateMenuSchedule()   → useMutation → create + invalidate
 * - useUpdateMenuSchedule()   → useMutation → update + invalidate
 * - useDeleteMenuSchedule()   → useMutation → delete + invalidate
 *
 * Every mutation invalidates menuScheduleKeys.all(brandSlug, menuId).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import { menuScheduleService, shopMenuScheduleService } from "@/services/menu-schedule-service";
import type {
  BranchScheduleOverrideInput,
  CreateMenuScheduleInput,
  UpdateMenuScheduleInput,
} from "@/types/models/MenuSchedule";
import { shopMenuScheduleKeys, menuScheduleKeys, menuKeys, shopMenuKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useMenuSchedules(brandSlug: string, menuId: string) {
  return useQuery({
    queryKey: menuScheduleKeys.list(brandSlug, menuId),
    queryFn: () => menuScheduleService.list(brandSlug, menuId),
    enabled: !!brandSlug && !!menuId,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateMenuSchedule(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateMenuScheduleInput) =>
      menuScheduleService.create(brandSlug, menuId, data),
    onSuccess: () => {
      toast.success(t("hq.menus.schedules.toast_created"));
      qc.invalidateQueries({ queryKey: menuScheduleKeys.all(brandSlug, menuId) });
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.schedules.toast_error")),
  });
}

export function useUpdateMenuSchedule(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ scheduleId, data }: { scheduleId: string; data: UpdateMenuScheduleInput }) =>
      menuScheduleService.update(brandSlug, menuId, scheduleId, data),
    onSuccess: () => {
      toast.success(t("hq.menus.schedules.toast_updated"));
      qc.invalidateQueries({ queryKey: menuScheduleKeys.all(brandSlug, menuId) });
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.schedules.toast_error")),
  });
}

export function useDeleteMenuSchedule(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) => menuScheduleService.delete(brandSlug, menuId, scheduleId),
    onSuccess: () => {
      toast.success(t("hq.menus.schedules.toast_deleted"));
      qc.invalidateQueries({ queryKey: menuScheduleKeys.all(brandSlug, menuId) });
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.schedules.toast_error")),
  });
}

export function useReorderMenuSchedules(brandSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (scheduleIds: string[]) =>
      menuScheduleService.reorder(brandSlug, menuId, scheduleIds),
    onSuccess: () => {
      toast.success(t("hq.menus.reorder_saved"));
      qc.invalidateQueries({ queryKey: menuScheduleKeys.all(brandSlug, menuId) });
      qc.invalidateQueries({ queryKey: menuKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.schedules.toast_error")),
  });
}

// =========================================================================
//  Branch override queries + mutations
// =========================================================================

export function useShopMenuSchedules(shopSlug: string, menuId: string) {
  return useQuery({
    queryKey: shopMenuScheduleKeys.list(shopSlug, menuId),
    queryFn: () => shopMenuScheduleService.list(shopSlug, menuId),
    enabled: !!shopSlug && !!menuId,
  });
}

export function useUpsertScheduleOverride(shopSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ scheduleId, data }: { scheduleId: string; data: BranchScheduleOverrideInput }) =>
      shopMenuScheduleService.upsertOverride(shopSlug, menuId, scheduleId, data),
    onSuccess: () => {
      toast.success(t("menu.schedule.override_saved"));
      qc.invalidateQueries({ queryKey: shopMenuScheduleKeys.all(shopSlug, menuId) });
      qc.invalidateQueries({ queryKey: shopMenuKeys.detailAll(shopSlug, menuId) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.schedules.toast_error")),
  });
}

export function useSetScheduleActive(shopSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ scheduleId, isActive }: { scheduleId: string; isActive: boolean }) =>
      shopMenuScheduleService.setActive(shopSlug, menuId, scheduleId, isActive),
    onSuccess: (_data, { isActive }) => {
      toast.success(
        isActive ? t("shop.menu.schedules.activated") : t("shop.menu.schedules.paused")
      );
      qc.invalidateQueries({ queryKey: shopMenuScheduleKeys.all(shopSlug, menuId) });
      qc.invalidateQueries({ queryKey: shopMenuKeys.detailAll(shopSlug, menuId) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.schedules.toast_error")),
  });
}

export function useDeleteScheduleOverride(shopSlug: string, menuId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) =>
      shopMenuScheduleService.deleteOverride(shopSlug, menuId, scheduleId),
    onSuccess: () => {
      toast.success(t("menu.schedule.override_reset"));
      qc.invalidateQueries({ queryKey: shopMenuScheduleKeys.all(shopSlug, menuId) });
      qc.invalidateQueries({ queryKey: shopMenuKeys.detailAll(shopSlug, menuId) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.menus.schedules.toast_error")),
  });
}
