/**
 * React Query hooks for the HQ settlement screens (#1157 · plan-050 M5 T5.1).
 *
 * `retry: false` on purpose. These are money-reconciliation reads: when the
 * request fails the operator must SEE that it failed, promptly, and decide
 * whether to trust the screen. Three silent retries turn a hard failure into a
 * ten-second spinner that then shows an error anyway — and worse, with
 * `placeholderData` in play, keeps the previous page's numbers on screen while
 * it happens.
 */

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import {
  settlementService,
  type SettlementAgingFilters,
  type SettlementBatchFilters,
  type SettlementListFilters,
  type SettlementPayoutFilters,
} from "@/services/settlement-service";
import { settlementKeys } from "./query-keys";

export function useSettlementBatches(brandSlug: string, filters: SettlementBatchFilters = {}) {
  return useQuery({
    queryKey: settlementKeys.batches(brandSlug, filters),
    queryFn: () => settlementService.listBatches(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
    retry: false,
  });
}

export function useSettlementPayouts(brandSlug: string, filters: SettlementPayoutFilters = {}) {
  return useQuery({
    queryKey: settlementKeys.payouts(brandSlug, filters),
    queryFn: () => settlementService.listPayouts(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
    retry: false,
  });
}

export function useSettlementAging(brandSlug: string, filters: SettlementAgingFilters = {}) {
  return useQuery({
    queryKey: settlementKeys.aging(brandSlug, filters),
    queryFn: () => settlementService.listAging(brandSlug, filters),
    enabled: !!brandSlug,
    retry: false,
  });
}

export function useSettlementRows(
  brandSlug: string,
  filters: SettlementListFilters = {},
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: settlementKeys.list(brandSlug, filters),
    queryFn: () => settlementService.listSettlements(brandSlug, filters),
    enabled: !!brandSlug && options.enabled !== false,
    placeholderData: keepPreviousData,
    retry: false,
  });
}
