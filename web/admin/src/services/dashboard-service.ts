import { apiFetch } from "@/lib/api";

// ── HQ Types ──────────────────────────────────────────────────────────────────

export interface DashboardKpiItem {
  value: number;
  delta_pct: number;
}

export interface DashboardKpis {
  revenue: DashboardKpiItem;
  orders: DashboardKpiItem;
  products: DashboardKpiItem;
  shops: DashboardKpiItem;
}

export interface RevenueChartPoint {
  period: string;
  revenue: number;
  orders: number;
}

export interface CategorySalesItem {
  category_id: string;
  category_name: string;
  revenue: number;
  percentage: number;
}

export interface ShopPerformanceItem {
  branch_id: string;
  branch_slug: string | null;
  branch_name: string;
  revenue: number;
  target: number;
}

export interface TopProductItem {
  product_id: string;
  product_name: string;
  category_name: string;
  sold: number;
  revenue: number;
  trend: "up" | "down";
}

export interface RecentOrderItem {
  id: string;
  order_code: string;
  table_code: string | null;
  items_count: number;
  total_amount: number;
  status: "completed" | "in_progress" | "cancelled";
  created_at: string;
}

export type DashboardPeriod = "month" | "week" | "year";

// ── Shop Types ────────────────────────────────────────────────────────────────

export interface ShopDashboardKpis {
  revenue: DashboardKpiItem;
  orders: DashboardKpiItem;
  table_occupancy: { occupied: number; total: number };
  low_stock: { value: number };
}

export interface RevenueTrendPoint {
  day: string;
  revenue: number;
  orders: number;
}

export interface TableStatusItem {
  status: "free" | "occupied" | "reserved" | "cleaning" | "out_of_service";
  count: number;
}

export interface TopShopItem {
  product_id: string;
  product_name: string;
  category_name: string;
  sold: number;
  revenue: number;
  trend: "up" | "down";
}

export interface ProductionQueueItem {
  order_code: string;
  product_name: string;
  quantity: number;
  status: "pending" | "in_progress";
}

// ── URL helpers ───────────────────────────────────────────────────────────────

function base(brandSlug: string, endpoint: string, params?: URLSearchParams): string {
  const qs = params?.toString();
  return `/api/v1/hq/${brandSlug}/dashboard/${endpoint}${qs ? `?${qs}` : ""}`;
}

function shopBase(shopSlug: string, endpoint: string, params?: URLSearchParams): string {
  const qs = params?.toString();
  return `/api/v1/shops/${shopSlug}/dashboard/${endpoint}${qs ? `?${qs}` : ""}`;
}

// ── HQ Service ────────────────────────────────────────────────────────────────

export const dashboardService = {
  kpis: (brandSlug: string, period: DashboardPeriod) => {
    const p = new URLSearchParams({ period });
    return apiFetch<{ data: DashboardKpis }>(base(brandSlug, "kpis", p));
  },

  revenueChart: (brandSlug: string, dateFrom: string, dateTo: string, groupBy: DashboardPeriod) => {
    const p = new URLSearchParams({ date_from: dateFrom, date_to: dateTo, group_by: groupBy });
    return apiFetch<{ data: RevenueChartPoint[] }>(base(brandSlug, "revenue-chart", p));
  },

  categorySales: (brandSlug: string, period: DashboardPeriod) => {
    const p = new URLSearchParams({ period });
    return apiFetch<{ data: CategorySalesItem[] }>(base(brandSlug, "category-sales", p));
  },

  shopPerformance: (brandSlug: string, period: DashboardPeriod) => {
    const p = new URLSearchParams({ period });
    return apiFetch<{ data: ShopPerformanceItem[] }>(base(brandSlug, "shop-performance", p));
  },

  topProducts: (brandSlug: string, period: DashboardPeriod, limit = 5) => {
    const p = new URLSearchParams({ period, limit: String(limit) });
    return apiFetch<{ data: TopProductItem[] }>(base(brandSlug, "top-products", p));
  },

  recentOrders: (brandSlug: string, limit = 5) => {
    const p = new URLSearchParams({ limit: String(limit) });
    return apiFetch<{ data: RecentOrderItem[] }>(base(brandSlug, "recent-orders", p));
  },
};

// ── Shop Service ──────────────────────────────────────────────────────────────

export const shopDashboardService = {
  kpis: (shopSlug: string) => apiFetch<{ data: ShopDashboardKpis }>(shopBase(shopSlug, "kpis")),

  revenueTrend: (shopSlug: string) =>
    apiFetch<{ data: RevenueTrendPoint[] }>(shopBase(shopSlug, "revenue-trend")),

  tableStatus: (shopSlug: string) =>
    apiFetch<{ data: TableStatusItem[] }>(shopBase(shopSlug, "table-status")),

  topItems: (shopSlug: string, limit = 5) => {
    const p = new URLSearchParams({ limit: String(limit) });
    return apiFetch<{ data: TopShopItem[] }>(shopBase(shopSlug, "top-items", p));
  },

  productionQueue: (shopSlug: string, limit = 10) => {
    const p = new URLSearchParams({ limit: String(limit) });
    return apiFetch<{ data: ProductionQueueItem[] }>(shopBase(shopSlug, "production-queue", p));
  },

  recentOrders: (shopSlug: string, limit = 5) => {
    const p = new URLSearchParams({ limit: String(limit) });
    return apiFetch<{ data: RecentOrderItem[] }>(shopBase(shopSlug, "recent-orders", p));
  },

  branchReviews: (shopSlug: string, limit = 5) => {
    const p = new URLSearchParams({ limit: String(limit) });
    return apiFetch<{
      data: {
        avg_rating: number | null;
        total_count: number;
        recent: Array<{
          id: string;
          rating: number;
          comment: string | null;
          created_at: string;
        }>;
      };
    }>(shopBase(shopSlug, "branch-reviews", p));
  },
};
