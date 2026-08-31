/**
 * Material Lot Hooks — React-Query wrappers around material-lot-service.
 *
 * Two scopes share this file because they're the same domain:
 *   - HQ:   useHqMaterialLots / useHqMaterialLot / quarantine / release / dispose
 *   - Shop: useShopMaterialLots / useShopMaterialLot / receive / expiring
 *
 * Mutations always invalidate `materialLotKeys.all(slug)` for the scope they
 * were fired in, so list views and detail panes refetch in parallel.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import { useTranslation } from "@/providers/app-provider";
import {
  materialLotHqService,
  materialLotShopService,
  type DisposeInput,
  type MaterialLotListFilters,
  type QuarantineInput,
  type ReceiveLotInput,
  type ReleaseInput,
  type SplitLotInput,
  type TimelineFilters,
} from "@/services/material-lot-service";

import { materialLotKeys, materialLotScope } from "./query-keys";

// =========================================================================
//  HQ queries
// =========================================================================

export function useHqMaterialLots(brandSlug: string, filters: MaterialLotListFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    // plan-040 M21: discriminate HQ scope so it can't collide with a same-slug shop.
    queryKey: materialLotKeys.list(materialLotScope.hq(brandSlug), locale, filters),
    queryFn: () => materialLotHqService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useHqMaterialLot(brandSlug: string, id: string | undefined) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: id
      ? materialLotKeys.detail(materialLotScope.hq(brandSlug), locale, id)
      : ["material-lots", "detail", "noop"],
    queryFn: () => materialLotHqService.getById(brandSlug, id!),
    enabled: !!brandSlug && !!id,
  });
}

export function useLotTimeline(
  brandSlug: string,
  lotId: string | undefined,
  filters: TimelineFilters = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: lotId
      ? [
          ...materialLotKeys.detail(materialLotScope.hq(brandSlug), locale, lotId),
          "timeline",
          filters,
        ]
      : ["material-lots", "timeline", "noop"],
    queryFn: () => materialLotHqService.timeline(brandSlug, lotId!, filters),
    enabled: !!brandSlug && !!lotId,
    placeholderData: keepPreviousData,
  });
}

// =========================================================================
//  HQ mutations
// =========================================================================

export function useQuarantineLot(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: QuarantineInput }) =>
      materialLotHqService.quarantine(brandSlug, id, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialLotKeys.all(materialLotScope.hq(brandSlug)),
      });
      toast.success(t("material_lot.quarantined"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useReleaseLot(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input?: ReleaseInput }) =>
      materialLotHqService.release(brandSlug, id, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialLotKeys.all(materialLotScope.hq(brandSlug)),
      });
      toast.success(t("material_lot.released"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useDisposeLot(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input?: DisposeInput }) =>
      materialLotHqService.dispose(brandSlug, id, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialLotKeys.all(materialLotScope.hq(brandSlug)),
      });
      toast.success(t("material_lot.disposed"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useBulkDeleteMaterialLots(brandSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (ids: string[]) => materialLotHqService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.material_lot.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(
            t("toast.material_lot.bulk_skipped", { deleted: data.deleted, skipped, names })
          );
        }
      } else {
        toast.success(t("toast.material_lot.bulk_deleted", { n: data.deleted }));
      }
      queryClient.invalidateQueries({
        queryKey: materialLotKeys.all(materialLotScope.hq(brandSlug)),
      });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

// =========================================================================
//  Shop queries + mutations
// =========================================================================

export function useShopMaterialLots(shopSlug: string, filters: MaterialLotListFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    // plan-040 M21: discriminate shop scope so it can't collide with a same-slug brand.
    queryKey: materialLotKeys.list(materialLotScope.shop(shopSlug), locale, filters),
    queryFn: () => materialLotShopService.list(shopSlug, filters),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

export function useShopMaterialLot(shopSlug: string, id: string | undefined) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: id
      ? materialLotKeys.detail(materialLotScope.shop(shopSlug), locale, id)
      : ["material-lots", "detail", "noop"],
    queryFn: () => materialLotShopService.getById(shopSlug, id!),
    enabled: !!shopSlug && !!id,
  });
}

export function useExpiringLots(shopSlug: string, days = 7, warehouseId?: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: materialLotKeys.expiring(materialLotScope.shop(shopSlug), locale, days, warehouseId),
    queryFn: () => materialLotShopService.expiring(shopSlug, days, warehouseId),
    enabled: !!shopSlug,
  });
}

export function useReceiveLot(shopSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: (input: ReceiveLotInput) => materialLotShopService.receive(shopSlug, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialLotKeys.all(materialLotScope.shop(shopSlug)),
      });
      toast.success(t("material_lot.received"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}

export function useSplitLot(shopSlug: string) {
  const queryClient = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({ lotId, input }: { lotId: string; input: SplitLotInput }) =>
      materialLotShopService.split(shopSlug, lotId, input),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: materialLotKeys.all(materialLotScope.shop(shopSlug)),
      });
      toast.success(t("material_lot.split_success"));
    },
    onError: (err: Error) => toast.error(err.message),
  });
}
