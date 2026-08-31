/**
 * Point reward hooks — #1514.
 *
 * Hai nhóm, hai phạm vi:
 *   - `usePointRewards` / `useCreate…` / `useUpdate…` / `useDelete…` — HQ, CRUD
 *     đầy đủ trên catalog của brand.
 *   - `useShopPointRewards` / `useSetPointRewardBranchAvailability` — cửa hàng,
 *     chỉ đọc + một công tắc cho riêng chi nhánh mình.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  pointRewardService,
  type CreatePointRewardInput,
  type UpdatePointRewardInput,
} from "@/services/point-reward-service";
import { useTranslation } from "@/providers/app-provider";
import { pointRewardKeys } from "./query-keys";

// =========================================================================
//  HQ — queries
// =========================================================================

export function usePointRewards(brandSlug: string, filters?: { search?: string }) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: pointRewardKeys.list(brandSlug, locale, filters),
    queryFn: () => pointRewardService.list(brandSlug, filters),
    enabled: !!brandSlug,
  });
}

export function usePointReward(brandSlug: string, id: string | null) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: pointRewardKeys.detail(brandSlug, locale, id ?? ""),
    queryFn: () => pointRewardService.get(brandSlug, id as string),
    enabled: !!brandSlug && !!id,
  });
}

// =========================================================================
//  HQ — mutations
// =========================================================================

export function useCreatePointReward(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (data: CreatePointRewardInput) => pointRewardService.create(brandSlug, data),
    onSuccess: () => {
      toast.success(t("toast.point_reward.created"));
      qc.invalidateQueries({ queryKey: pointRewardKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.point_reward.create_failed")),
  });
}

export function useUpdatePointReward(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdatePointRewardInput }) =>
      pointRewardService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success(t("toast.point_reward.updated"));
      qc.invalidateQueries({ queryKey: pointRewardKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.point_reward.update_failed")),
  });
}

export function useTogglePointRewardActive(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, currentIsActive }: { id: string; currentIsActive: boolean }) =>
      pointRewardService.toggleActive(brandSlug, id, currentIsActive),
    onSuccess: () => qc.invalidateQueries({ queryKey: pointRewardKeys.all(brandSlug) }),
    onError: (e: Error) => toast.error(e.message || t("toast.point_reward.update_failed")),
  });
}

export function useDeletePointReward(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => pointRewardService.remove(brandSlug, id),
    onSuccess: () => {
      toast.success(t("toast.point_reward.deleted"));
      qc.invalidateQueries({ queryKey: pointRewardKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.point_reward.delete_failed")),
  });
}

// =========================================================================
//  Shop
// =========================================================================

export function useShopPointRewards(shopSlug: string, filters?: { search?: string }) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: pointRewardKeys.shopList(shopSlug, locale, filters),
    queryFn: () => pointRewardService.listForShop(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useSetPointRewardBranchAvailability(shopSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: ({ id, isAvailable }: { id: string; isAvailable: boolean }) =>
      pointRewardService.setBranchAvailability(shopSlug, id, isAvailable),
    onSuccess: () => {
      toast.success(t("toast.point_reward.availability_updated"));
      qc.invalidateQueries({ queryKey: pointRewardKeys.shopAll(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.point_reward.update_failed")),
  });
}
