/**
 * Material Lot Reservation Hooks — React-Query wrappers, SHOP scope (#3112).
 *
 * `useCreateShopLotReservation` intentionally does NOT toast on success: the
 * batch-creation screen fires it once per selected lot and reports one summary
 * instead of N toasts. It DOES surface every failure — a hold that silently
 * fails to land is the exact defect #3077 was filed for.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import {
  materialLotReservationShopService,
  type CreateLotReservationInput,
  type MaterialLotReservationStatus,
} from "@/services/material-lot-reservation-service";

import { materialLotKeys, materialLotReservationKeys, materialLotScope } from "./query-keys";

export function useShopLotReservationsByBatch(
  shopSlug: string,
  materialBatchId: string | undefined,
  status?: MaterialLotReservationStatus
) {
  return useQuery({
    queryKey: materialLotReservationKeys.byBatch(shopSlug, materialBatchId ?? "noop", status),
    queryFn: () =>
      materialLotReservationShopService.listByBatch(shopSlug, materialBatchId!, status),
    enabled: !!shopSlug && !!materialBatchId,
  });
}

export function useCreateShopLotReservation(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateLotReservationInput) =>
      materialLotReservationShopService.create(shopSlug, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: materialLotReservationKeys.all(shopSlug) });
      // A hold changes the lot's available qty, so the lot lists are stale too.
      qc.invalidateQueries({ queryKey: materialLotKeys.all(materialLotScope.shop(shopSlug)) });
    },
  });
}

export function useReleaseShopLotReservation(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => materialLotReservationShopService.release(shopSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: materialLotReservationKeys.all(shopSlug) });
      qc.invalidateQueries({ queryKey: materialLotKeys.all(materialLotScope.shop(shopSlug)) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to release the hold."),
  });
}
