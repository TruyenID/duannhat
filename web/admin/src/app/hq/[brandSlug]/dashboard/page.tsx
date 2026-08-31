"use client";

import { useState, useMemo } from "react";
import { useParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
  Badge,
  Progress,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Separator,
  Skeleton,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Button,
} from "@godxjp/ui";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "./components/chart";
import {
  Package,
  Banknote,
  ShoppingCart,
  Store,
  TrendingUp,
  TrendingDown,
  ArrowUpRight,
  ArrowDownRight,
  RefreshCw,
} from "lucide-react";
import {
  AreaChart,
  Area,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Cell,
  PieChart,
  Pie,
} from "recharts";
import { useTranslation } from "@/providers/app-provider";
import { formatCurrency, formatCurrencyCompact } from "@/lib/currency";
import {
  useDashboardKpis,
  useDashboardRevenueChart,
  useDashboardCategorySales,
  useDashboardShopPerformance,
  useDashboardTopProducts,
  useDashboardRecentOrders,
} from "@/hooks/api/use-dashboard";
import type { DashboardPeriod } from "@/services/dashboard-service";

// ── Constants ─────────────────────────────────────────────────────────────────

const categoryColors = [
  "oklch(56% 0.15 240)",
  "oklch(72% 0.13 155)",
  "oklch(80% 0.17 85)",
  "oklch(68% 0.16 300)",
  "oklch(68% 0.008 60)",
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function isoToDateStr(date: Date): string {
  // Serialize using local date components, not toISOString() (UTC). getChartRange
  // builds Dates via the local-time constructor (new Date(year, month, 1)), so a
  // UTC serialization shifts the day across the month boundary in non-UTC zones
  // (e.g. the 1st becomes the previous month's last day), truncating the first
  // bucket of the chart. See dashboard "Th11 shows 6 instead of 94" bug.
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function getChartRange(period: DashboardPeriod): { dateFrom: string; dateTo: string } {
  const now = new Date();
  if (period === "week") {
    const from = new Date(now);
    from.setDate(from.getDate() - 7 * 7); // 8 weeks back
    return { dateFrom: isoToDateStr(from), dateTo: isoToDateStr(now) };
  }
  if (period === "year") {
    const from = new Date(now.getFullYear() - 4, 0, 1); // 5 years back
    return { dateFrom: isoToDateStr(from), dateTo: isoToDateStr(now) };
  }
  const from = new Date(now.getFullYear(), now.getMonth() - 6, 1); // 7 months
  return { dateFrom: isoToDateStr(from), dateTo: isoToDateStr(now) };
}

function minutesAgo(isoString: string): number {
  return Math.max(0, Math.floor((Date.now() - new Date(isoString).getTime()) / 60_000));
}

function formatChartPeriod(
  period: string,
  groupBy: DashboardPeriod,
  t: (k: string) => string
): string {
  if (groupBy === "week") {
    const weekNum = period.split("-W")[1];
    return weekNum ? `W${weekNum}` : period;
  }
  if (groupBy === "year") {
    return period; // already "YYYY"
  }
  const monthNum = parseInt(period.split("-")[1], 10);
  return isNaN(monthNum) ? period : t(`dashboard.month.${monthNum}`);
}

// ── Sub-components ────────────────────────────────────────────────────────────

interface StatCardProps {
  label: string;
  value: string;
  sub: string;
  delta: number;
  icon: React.ElementType;
}

function StatCard({ label, value, sub, delta, icon: Icon }: StatCardProps) {
  const positive = delta > 0;
  const neutral = delta === 0;
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between px-4 pt-4 pb-1">
        <CardTitle className="text-xs font-medium text-muted-foreground">{label}</CardTitle>
        <Icon className="size-3.5 text-muted-foreground" />
      </CardHeader>
      <CardContent className="px-4 pb-4">
        <div className="text-2xl font-bold tracking-tight">{value}</div>
        <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
          {!neutral &&
            (positive ? (
              <ArrowUpRight className="size-3 text-success" />
            ) : (
              <ArrowDownRight className="size-3 text-destructive" />
            ))}
          {!neutral && (
            <span className={positive ? "text-success" : "text-destructive"}>
              {positive ? "+" : ""}
              {delta}%
            </span>
          )}
          <span>{sub}</span>
        </div>
      </CardContent>
    </Card>
  );
}

function SectionError({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-col items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
      <span>{t("common.error_loading")}</span>
      <Button variant="outline" size="sm" onClick={onRetry}>
        {t("common.retry")}
      </Button>
    </div>
  );
}

function statusBadge(status: string, t: (k: string) => string) {
  if (status === "completed")
    return (
      <Badge color="success" variant="soft">
        {t("dashboard.orders.status.completed")}
      </Badge>
    );
  if (status === "in_progress")
    return (
      <Badge color="info" variant="soft">
        {t("dashboard.orders.status.in_progress")}
      </Badge>
    );
  return (
    <Badge color="destructive" variant="soft">
      {t("dashboard.orders.status.cancelled")}
    </Badge>
  );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function BrandDashboardPage() {
  const { t, locale } = useTranslation();
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const router = useRouter();

  // Locale-aware currency formatters (JPY for ja/en, VND for vi).
  const fmt = useMemo(() => (n: number) => formatCurrency(n, locale), [locale]);
  const fmtCompact = useMemo(() => (n: number) => formatCurrencyCompact(n, locale), [locale]);

  const [period, setPeriod] = useState<DashboardPeriod>("month");
  const { dateFrom, dateTo } = useMemo(() => getChartRange(period), [period]);

  const kpis = useDashboardKpis(brandSlug, period);
  const revenueChart = useDashboardRevenueChart(brandSlug, dateFrom, dateTo, period);
  const categorySales = useDashboardCategorySales(brandSlug, period);
  const shopPerf = useDashboardShopPerformance(brandSlug, period);
  const topProducts = useDashboardTopProducts(brandSlug, period);
  const recentOrders = useDashboardRecentOrders(brandSlug);

  const kpiData = kpis.data?.data;

  const chartData = useMemo(
    () =>
      (revenueChart.data?.data ?? []).map((d) => ({
        ...d,
        label: formatChartPeriod(d.period, period, t),
      })),
    [revenueChart.data, period, t]
  );

  const categoryData = useMemo(
    () =>
      (categorySales.data?.data ?? []).map((c, i) => ({
        name: c.category_name,
        value: c.percentage,
        revenue: c.revenue,
        color: categoryColors[i % categoryColors.length],
      })),
    [categorySales.data]
  );

  const shopData = shopPerf.data?.data ?? [];
  const productsData = topProducts.data?.data ?? [];
  const ordersData = recentOrders.data?.data ?? [];

  // Refresh: reload all 6 dashboard queries at once.
  const refreshAll = () => {
    kpis.refetch();
    revenueChart.refetch();
    categorySales.refetch();
    shopPerf.refetch();
    topProducts.refetch();
    recentOrders.refetch();
  };

  const isRefreshing =
    kpis.isFetching ||
    revenueChart.isFetching ||
    categorySales.isFetching ||
    shopPerf.isFetching ||
    topProducts.isFetching ||
    recentOrders.isFetching;

  const vsLastPeriod =
    period === "week"
      ? t("dashboard.kpi.vs_last_week")
      : period === "year"
        ? t("dashboard.kpi.vs_last_year")
        : t("dashboard.kpi.vs_last_month");

  const revenueChartConfig = useMemo<ChartConfig>(
    () => ({
      revenue: { label: t("dashboard.chart.revenue"), color: "oklch(56% 0.15 240)" },
      orders: { label: t("dashboard.chart.orders"), color: "oklch(72% 0.13 155)" },
    }),
    [t]
  );

  const categoryChartConfig = useMemo<ChartConfig>(
    () => ({ value: { label: t("dashboard.chart.monthly_composition") } }),
    [t]
  );

  return (
    <>
      <PageHeader title={t("dashboard.title")}>
        {/* Period selector */}
        <Tabs
          value={period}
          onValueChange={(v) => setPeriod(v as DashboardPeriod)}
          className="w-auto"
        >
          <TabsList className="h-7">
            <TabsTrigger value="week" className="px-3 text-xs">
              {t("dashboard.period.week")}
            </TabsTrigger>
            <TabsTrigger value="month" className="px-3 text-xs">
              {t("dashboard.period.month")}
            </TabsTrigger>
            <TabsTrigger value="year" className="px-3 text-xs">
              {t("dashboard.period.year")}
            </TabsTrigger>
          </TabsList>
        </Tabs>
        <Button
          variant="outline"
          size="sm"
          className="h-7 gap-1.5 px-2.5 text-xs"
          onClick={refreshAll}
          disabled={isRefreshing}
        >
          <RefreshCw className={`size-3.5 ${isRefreshing ? "animate-spin" : ""}`} />
          {t("common.refresh")}
        </Button>
      </PageHeader>

      <PageContent>
        <div className="space-y-6">
          {/* ── KPI row ── */}
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            {kpis.isLoading ? (
              Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-[88px] rounded-lg" />
              ))
            ) : kpis.isError ? (
              <div className="col-span-4">
                <SectionError onRetry={() => kpis.refetch()} />
              </div>
            ) : (
              <>
                <StatCard
                  label={t("dashboard.kpi.revenue")}
                  value={fmt(kpiData?.revenue.value ?? 0)}
                  sub={vsLastPeriod}
                  delta={kpiData?.revenue.delta_pct ?? 0}
                  icon={Banknote}
                />
                <StatCard
                  label={t("dashboard.kpi.orders")}
                  value={t("dashboard.kpi.orders_value", { count: kpiData?.orders.value ?? 0 })}
                  sub={vsLastPeriod}
                  delta={kpiData?.orders.delta_pct ?? 0}
                  icon={ShoppingCart}
                />
                <StatCard
                  label={t("dashboard.kpi.products")}
                  value={String(kpiData?.products.value ?? 0)}
                  sub={vsLastPeriod}
                  delta={kpiData?.products.delta_pct ?? 0}
                  icon={Package}
                />
                <StatCard
                  label={t("dashboard.kpi.shops")}
                  value={String(kpiData?.shops.value ?? 0)}
                  sub={t("dashboard.kpi.no_change")}
                  delta={0}
                  icon={Store}
                />
              </>
            )}
          </div>

          {/* ── Revenue chart + Category pie ── */}
          <div className="grid gap-4 lg:grid-cols-3">
            {/* Revenue / Orders chart */}
            <Card className="lg:col-span-2">
              <CardHeader className="px-4 pt-4 pb-2">
                <div className="flex items-start justify-between">
                  <div>
                    <CardTitle className="text-sm font-medium">
                      {t("dashboard.chart.revenue_trend")}
                    </CardTitle>
                    <CardDescription className="mt-0.5 text-xs">
                      {period === "week"
                        ? t("dashboard.chart.last_8_weeks")
                        : period === "year"
                          ? t("dashboard.chart.last_5_years")
                          : t("dashboard.chart.last_7_months")}
                    </CardDescription>
                  </div>
                  {kpiData && kpiData.revenue.delta_pct !== 0 && (
                    <Badge
                      variant="soft"
                      color={kpiData.revenue.delta_pct > 0 ? "success" : "destructive"}
                      className="text-xs"
                    >
                      {kpiData.revenue.delta_pct > 0 ? (
                        <TrendingUp className="mr-1 size-3" />
                      ) : (
                        <TrendingDown className="mr-1 size-3" />
                      )}
                      {kpiData.revenue.delta_pct > 0 ? "+" : ""}
                      {kpiData.revenue.delta_pct}% {vsLastPeriod}
                    </Badge>
                  )}
                </div>
              </CardHeader>
              <CardContent className="px-4 pb-4">
                {revenueChart.isLoading ? (
                  <Skeleton className="h-[232px] w-full rounded-md" />
                ) : revenueChart.isError ? (
                  <SectionError onRetry={() => revenueChart.refetch()} />
                ) : (
                  <Tabs defaultValue="revenue">
                    <TabsList className="mb-4 h-7">
                      <TabsTrigger value="revenue" className="px-3 text-xs">
                        {t("dashboard.chart.revenue")}
                      </TabsTrigger>
                      <TabsTrigger value="orders" className="px-3 text-xs">
                        {t("dashboard.chart.orders")}
                      </TabsTrigger>
                    </TabsList>
                    <TabsContent value="revenue">
                      <ChartContainer config={revenueChartConfig} className="h-[200px] w-full">
                        <AreaChart
                          data={chartData}
                          margin={{ top: 4, right: 4, left: 0, bottom: 0 }}
                        >
                          <defs>
                            <linearGradient id="fillRevenue" x1="0" y1="0" x2="0" y2="1">
                              <stop
                                offset="5%"
                                stopColor="oklch(56% 0.15 240)"
                                stopOpacity={0.15}
                              />
                              <stop offset="95%" stopColor="oklch(56% 0.15 240)" stopOpacity={0} />
                            </linearGradient>
                          </defs>
                          <CartesianGrid
                            vertical={false}
                            strokeDasharray="3 3"
                            stroke="oklch(90% 0.005 60)"
                          />
                          <XAxis
                            dataKey="label"
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                          />
                          <YAxis
                            tickFormatter={(v: number) => fmtCompact(v)}
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                            width={52}
                          />
                          <ChartTooltip
                            content={
                              <ChartTooltipContent formatter={(value) => fmt(Number(value))} />
                            }
                          />
                          <Area
                            type="monotone"
                            dataKey="revenue"
                            stroke="oklch(56% 0.15 240)"
                            strokeWidth={2}
                            fill="url(#fillRevenue)"
                            dot={false}
                            activeDot={{ r: 4, strokeWidth: 0 }}
                          />
                        </AreaChart>
                      </ChartContainer>
                    </TabsContent>
                    <TabsContent value="orders">
                      <ChartContainer config={revenueChartConfig} className="h-[200px] w-full">
                        <BarChart
                          data={chartData}
                          margin={{ top: 4, right: 4, left: 0, bottom: 0 }}
                        >
                          <CartesianGrid
                            vertical={false}
                            strokeDasharray="3 3"
                            stroke="oklch(90% 0.005 60)"
                          />
                          <XAxis
                            dataKey="label"
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                          />
                          <YAxis
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                            width={32}
                          />
                          <ChartTooltip content={<ChartTooltipContent />} />
                          <Bar dataKey="orders" fill="oklch(72% 0.13 155)" radius={[3, 3, 0, 0]} />
                        </BarChart>
                      </ChartContainer>
                    </TabsContent>
                  </Tabs>
                )}
              </CardContent>
            </Card>

            {/* Category breakdown */}
            <Card>
              <CardHeader className="px-4 pt-4 pb-2">
                <CardTitle className="text-sm font-medium">
                  {t("dashboard.chart.category_sales")}
                </CardTitle>
                <CardDescription className="mt-0.5 text-xs">
                  {t("dashboard.chart.monthly_composition")}
                </CardDescription>
              </CardHeader>
              <CardContent className="px-4 pb-4">
                {categorySales.isLoading ? (
                  <Skeleton className="h-[200px] w-full rounded-md" />
                ) : categorySales.isError ? (
                  <SectionError onRetry={() => categorySales.refetch()} />
                ) : categoryData.length === 0 ? (
                  <div className="flex h-[200px] items-center justify-center text-xs text-muted-foreground">
                    {t("common.no_data")}
                  </div>
                ) : (
                  <>
                    <ChartContainer config={categoryChartConfig} className="h-[140px] w-full">
                      <PieChart>
                        <Pie
                          data={categoryData}
                          cx="50%"
                          cy="50%"
                          innerRadius={40}
                          outerRadius={60}
                          paddingAngle={2}
                          dataKey="value"
                        >
                          {categoryData.map((entry) => (
                            <Cell key={entry.name} fill={entry.color} />
                          ))}
                        </Pie>
                        <ChartTooltip
                          content={
                            <ChartTooltipContent
                              formatter={(value, _name, item) => {
                                const revenue = (item?.payload as { revenue?: number } | undefined)
                                  ?.revenue;
                                return revenue != null
                                  ? `${value}% · ${fmt(revenue)}`
                                  : `${value}%`;
                              }}
                              nameKey="name"
                            />
                          }
                        />
                      </PieChart>
                    </ChartContainer>
                    <div className="mt-2 space-y-1.5">
                      {categoryData.map((c) => (
                        <div key={c.name} className="flex items-center justify-between text-xs">
                          <div className="flex items-center gap-1.5">
                            <span
                              className="inline-block size-2 shrink-0 rounded-full"
                              style={{ background: c.color }}
                            />
                            <span className="text-muted-foreground">{c.name}</span>
                          </div>
                          <span className="font-medium tabular-nums">{c.value}%</span>
                        </div>
                      ))}
                    </div>
                  </>
                )}
              </CardContent>
            </Card>
          </div>

          {/* ── Shop performance + Top products ── */}
          <div className="grid gap-4 lg:grid-cols-2">
            {/* Shop performance */}
            <Card>
              <CardHeader className="px-4 pt-4 pb-2">
                <CardTitle className="text-sm font-medium">
                  {t("dashboard.shop.performance")}
                </CardTitle>
                <CardDescription className="mt-0.5 text-xs">
                  {t("dashboard.shop.monthly_target")}
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4 px-4 pb-4">
                {shopPerf.isLoading ? (
                  <Skeleton className="h-[160px] w-full rounded-md" />
                ) : shopPerf.isError ? (
                  <SectionError onRetry={() => shopPerf.refetch()} />
                ) : shopData.length === 0 ? (
                  <div className="py-6 text-center text-xs text-muted-foreground">
                    {t("common.no_data")}
                  </div>
                ) : (
                  shopData.map((shop) => {
                    // Guard against target = 0 → NaN/Infinity; clamp display to 100%.
                    const pct =
                      shop.target > 0
                        ? Math.min(100, Math.round((shop.revenue / shop.target) * 100))
                        : 0;
                    const onTrack = pct >= 80;
                    const canNavigate = !!shop.branch_slug;
                    return (
                      <div
                        key={shop.branch_id}
                        role={canNavigate ? "button" : undefined}
                        tabIndex={canNavigate ? 0 : undefined}
                        onClick={
                          canNavigate
                            ? () => router.push(`/hq/${brandSlug}/shops/${shop.branch_slug}`)
                            : undefined
                        }
                        onKeyDown={
                          canNavigate
                            ? (e) => {
                                if (e.key === "Enter" || e.key === " ") {
                                  e.preventDefault();
                                  router.push(`/hq/${brandSlug}/shops/${shop.branch_slug}`);
                                }
                              }
                            : undefined
                        }
                        className={
                          canNavigate
                            ? "-mx-2 cursor-pointer rounded-md px-2 py-1 transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                            : undefined
                        }
                      >
                        <div className="mb-1.5 flex items-center justify-between">
                          <span className="text-xs font-medium">{shop.branch_name}</span>
                          <div className="flex items-center gap-2">
                            <span className="text-xs text-muted-foreground tabular-nums">
                              {fmt(shop.revenue)} / {fmt(shop.target)}
                            </span>
                            <span
                              className={`text-xs font-medium tabular-nums ${onTrack ? "text-success" : "text-warning"}`}
                            >
                              {pct}%
                            </span>
                          </div>
                        </div>
                        <Progress value={pct} className="h-1.5" />
                      </div>
                    );
                  })
                )}
              </CardContent>
            </Card>

            {/* Top products */}
            <Card>
              <CardHeader className="px-4 pt-4 pb-2">
                <CardTitle className="text-sm font-medium">
                  {t("dashboard.products.top5")}
                </CardTitle>
                <CardDescription className="mt-0.5 text-xs">
                  {t("dashboard.products.by_sales")}
                </CardDescription>
              </CardHeader>
              <CardContent className="px-0 pb-2">
                {topProducts.isLoading ? (
                  <Skeleton className="mx-4 h-[160px] rounded-md" />
                ) : topProducts.isError ? (
                  <SectionError onRetry={() => topProducts.refetch()} />
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent">
                        <TableHead className="h-8 px-4 text-xs">
                          {t("dashboard.products.col.product")}
                        </TableHead>
                        <TableHead className="h-8 px-4 text-right text-xs">
                          {t("dashboard.products.col.sold")}
                        </TableHead>
                        <TableHead className="h-8 px-4 text-right text-xs">
                          {t("dashboard.products.col.revenue")}
                        </TableHead>
                        <TableHead className="h-8 w-8 px-4 text-xs" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {productsData.length === 0 ? (
                        <TableRow className="hover:bg-transparent">
                          <TableCell
                            colSpan={4}
                            className="px-4 py-6 text-center text-xs text-muted-foreground"
                          >
                            {t("common.no_data")}
                          </TableCell>
                        </TableRow>
                      ) : (
                        productsData.map((p) => (
                          <TableRow
                            key={p.product_id}
                            className="cursor-pointer text-xs"
                            onClick={() => router.push(`/hq/${brandSlug}/products/${p.product_id}`)}
                          >
                            <TableCell className="px-4 py-2">
                              <div className="leading-snug font-medium">{p.product_name}</div>
                              <div className="mt-0.5 text-[11px] text-muted-foreground">
                                {p.category_name}
                              </div>
                            </TableCell>
                            <TableCell className="px-4 py-2 text-right tabular-nums">
                              {p.sold}
                            </TableCell>
                            <TableCell className="px-4 py-2 text-right font-medium tabular-nums">
                              {fmt(p.revenue)}
                            </TableCell>
                            <TableCell className="px-4 py-2">
                              {p.trend === "up" ? (
                                <TrendingUp className="size-3 text-success" />
                              ) : (
                                <TrendingDown className="size-3 text-destructive" />
                              )}
                            </TableCell>
                          </TableRow>
                        ))
                      )}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>
          </div>

          {/* ── Recent orders ── */}
          <Card>
            <CardHeader className="px-4 pt-4 pb-2">
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="text-sm font-medium">
                    {t("dashboard.orders.recent")}
                  </CardTitle>
                  <CardDescription className="mt-0.5 text-xs">
                    {t("dashboard.orders.recent5")}
                  </CardDescription>
                </div>
                {kpiData && (
                  <Badge variant="outline" className="text-xs">
                    {t("dashboard.orders.today_count", { count: kpiData.orders.value })}
                  </Badge>
                )}
              </div>
            </CardHeader>
            <Separator />
            <CardContent className="px-0 pb-2">
              {recentOrders.isLoading ? (
                <Skeleton className="mx-4 my-4 h-[160px] rounded-md" />
              ) : recentOrders.isError ? (
                <SectionError onRetry={() => recentOrders.refetch()} />
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent">
                      <TableHead className="h-8 px-4 text-xs">
                        {t("dashboard.orders.col.id")}
                      </TableHead>
                      <TableHead className="h-8 px-4 text-xs">
                        {t("dashboard.orders.col.table")}
                      </TableHead>
                      <TableHead className="h-8 px-4 text-right text-xs">
                        {t("dashboard.orders.col.items")}
                      </TableHead>
                      <TableHead className="h-8 px-4 text-right text-xs">
                        {t("dashboard.orders.col.total")}
                      </TableHead>
                      <TableHead className="h-8 px-4 text-xs">{t("common.status")}</TableHead>
                      <TableHead className="h-8 px-4 text-right text-xs">
                        {t("dashboard.orders.col.time")}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {ordersData.length === 0 ? (
                      <TableRow className="hover:bg-transparent">
                        <TableCell
                          colSpan={6}
                          className="px-4 py-6 text-center text-xs text-muted-foreground"
                        >
                          {t("common.no_data")}
                        </TableCell>
                      </TableRow>
                    ) : (
                      ordersData.map((order) => (
                        <TableRow
                          key={order.id}
                          className="cursor-pointer text-xs"
                          onClick={() => router.push(`/hq/${brandSlug}/orders/${order.id}`)}
                        >
                          <TableCell className="px-4 py-2 font-mono text-muted-foreground">
                            {order.order_code}
                          </TableCell>
                          <TableCell className="px-4 py-2">{order.table_code ?? "—"}</TableCell>
                          <TableCell className="px-4 py-2 text-right tabular-nums">
                            {order.items_count}
                          </TableCell>
                          <TableCell className="px-4 py-2 text-right font-medium tabular-nums">
                            {fmt(order.total_amount)}
                          </TableCell>
                          <TableCell className="px-4 py-2">
                            {statusBadge(order.status, t)}
                          </TableCell>
                          <TableCell className="px-4 py-2 text-right text-muted-foreground">
                            {t("dashboard.mock.minutes_ago", { n: minutesAgo(order.created_at) })}
                          </TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        </div>
      </PageContent>
    </>
  );
}
