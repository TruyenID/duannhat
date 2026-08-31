import { useQuery } from "@tanstack/react-query";
import { dashboardKeys, shopDashboardKeys } from "./query-keys";
import {
  dashboardService,
  shopDashboardService,
  type DashboardPeriod,
} from "@/services/dashboard-service";
import { useTranslation } from "@/providers/app-provider";

export function useDashboardKpis(brandSlug: string, period: DashboardPeriod) {
  return useQuery({
    queryKey: dashboardKeys.kpis(brandSlug, period),
    queryFn: () => dashboardService.kpis(brandSlug, period),
    enabled: !!brandSlug,
    staleTime: 60_000,
  });
}

export function useDashboardRevenueChart(
  brandSlug: string,
  dateFrom: string,
  dateTo: string,
  groupBy: DashboardPeriod
) {
  return useQuery({
    queryKey: dashboardKeys.revenueChart(brandSlug, dateFrom, dateTo, groupBy),
    queryFn: () => dashboardService.revenueChart(brandSlug, dateFrom, dateTo, groupBy),
    enabled: !!brandSlug,
    staleTime: 60_000,
  });
}

export function useDashboardCategorySales(brandSlug: string, period: DashboardPeriod) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: dashboardKeys.categorySales(brandSlug, period, locale),
    queryFn: () => dashboardService.categorySales(brandSlug, period),
    enabled: !!brandSlug,
    staleTime: 60_000,
  });
}

export function useDashboardShopPerformance(brandSlug: string, period: DashboardPeriod) {
  return useQuery({
    queryKey: dashboardKeys.shopPerformance(brandSlug, period),
    queryFn: () => dashboardService.shopPerformance(brandSlug, period),
    enabled: !!brandSlug,
    staleTime: 60_000,
  });
}

export function useDashboardTopProducts(brandSlug: string, period: DashboardPeriod) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: dashboardKeys.topProducts(brandSlug, period, locale),
    queryFn: () => dashboardService.topProducts(brandSlug, period),
    enabled: !!brandSlug,
    staleTime: 60_000,
  });
}

export function useDashboardRecentOrders(brandSlug: string) {
  return useQuery({
    queryKey: dashboardKeys.recentOrders(brandSlug),
    queryFn: () => dashboardService.recentOrders(brandSlug),
    enabled: !!brandSlug,
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}

// ── Shop Dashboard Hooks ──────────────────────────────────────────────────────

export function useShopDashboardKpis(shopSlug: string) {
  return useQuery({
    queryKey: shopDashboardKeys.kpis(shopSlug),
    queryFn: () => shopDashboardService.kpis(shopSlug),
    enabled: !!shopSlug,
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}

export function useShopDashboardRevenueTrend(shopSlug: string) {
  return useQuery({
    queryKey: shopDashboardKeys.revenueTrend(shopSlug),
    queryFn: () => shopDashboardService.revenueTrend(shopSlug),
    enabled: !!shopSlug,
    staleTime: 60_000,
  });
}

export function useShopDashboardTableStatus(shopSlug: string) {
  return useQuery({
    queryKey: shopDashboardKeys.tableStatus(shopSlug),
    queryFn: () => shopDashboardService.tableStatus(shopSlug),
    enabled: !!shopSlug,
    staleTime: 15_000,
    refetchInterval: 30_000,
  });
}

export function useShopDashboardTopItems(shopSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: shopDashboardKeys.topItems(shopSlug, locale),
    queryFn: () => shopDashboardService.topItems(shopSlug),
    enabled: !!shopSlug,
    staleTime: 60_000,
  });
}

export function useShopDashboardProductionQueue(shopSlug: string) {
  return useQuery({
    queryKey: shopDashboardKeys.productionQueue(shopSlug),
    queryFn: () => shopDashboardService.productionQueue(shopSlug),
    enabled: !!shopSlug,
    staleTime: 15_000,
    refetchInterval: 30_000,
  });
}

export function useShopDashboardRecentOrders(shopSlug: string) {
  return useQuery({
    queryKey: shopDashboardKeys.recentOrders(shopSlug),
    queryFn: () => shopDashboardService.recentOrders(shopSlug),
    enabled: !!shopSlug,
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}

export function useShopDashboardBranchReviews(shopSlug: string) {
  return useQuery({
    queryKey: shopDashboardKeys.branchReviews(shopSlug),
    queryFn: () => shopDashboardService.branchReviews(shopSlug),
    enabled: !!shopSlug,
    staleTime: 60_000,
  });
}
