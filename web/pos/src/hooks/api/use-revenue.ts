/**
 * useRevenueSummary — daily / monthly revenue aggregates for the active
 * shop. Routes through resolveBaseUrl(), so the same hook serves Cloud and
 * workstation LAN responses without branching.
 *
 * 60s staleTime — the report screen has an explicit "refresh" button, and
 * the underlying data only changes when an order closes (low write rate).
 */

import { useQuery } from "@tanstack/react-query";
import {
  revenueService,
  type RevenueByProductFilters,
  type RevenueSummaryFilters,
  type RevenueVoidEventsFilters,
  type RevenueVoidsFilters,
} from "@/services/revenue-service";
import { useLocale } from "@/providers/app-provider";
import { revenueKeys } from "./query-keys";

export function useRevenueSummary(
  shopSlug: string,
  filters: RevenueSummaryFilters = {},
) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: revenueKeys.summary(shopSlug, filters, locale),
    queryFn: () => revenueService.summary(shopSlug, filters),
    enabled: !!shopSlug,
    staleTime: 60 * 1000,
    select: (response) => response.data,
  });
}

export function useRevenueByProduct(
  shopSlug: string,
  filters: RevenueByProductFilters = {},
) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: revenueKeys.byProduct(shopSlug, filters, locale),
    queryFn: () => revenueService.byProduct(shopSlug, filters),
    enabled: !!shopSlug,
    staleTime: 60 * 1000,
    select: (response) => response.data,
  });
}

export function useRevenueVoids(
  shopSlug: string,
  filters: RevenueVoidsFilters = {},
) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: revenueKeys.voids(shopSlug, filters, locale),
    queryFn: () => revenueService.voids(shopSlug, filters),
    enabled: !!shopSlug,
    staleTime: 60 * 1000,
    select: (response) => response.data,
  });
}

export function useRevenueVoidEvents(
  shopSlug: string,
  filters: RevenueVoidEventsFilters = {},
) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: revenueKeys.voidEvents(shopSlug, filters, locale),
    queryFn: () => revenueService.voidEvents(shopSlug, filters),
    enabled: !!shopSlug,
    staleTime: 60 * 1000,
    select: (response) => response.data,
  });
}
