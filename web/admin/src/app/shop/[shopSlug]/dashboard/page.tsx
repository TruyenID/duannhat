"use client";

import { useParams } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
  Badge,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Separator,
  Spinner,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Button,
} from "@godxjp/ui";
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
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { useShopCurrency } from "@/providers/shop-currency-provider";
import { formatCurrency, formatCurrencyCompact } from "@/lib/currency";
import type { LocaleCode } from "@/i18n";
import { formatDate, formatTime } from "@/lib/date";
import {
  type ChartConfig,
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
} from "@/app/hq/[brandSlug]/dashboard/components/chart";
import {
  Banknote,
  ShoppingCart,
  LayoutGrid,
  AlertTriangle,
  Star,
  TrendingUp,
  TrendingDown,
  ArrowDownRight,
  ArrowUpRight,
} from "lucide-react";
import {
  useShopDashboardKpis,
  useShopDashboardRevenueTrend,
  useShopDashboardTableStatus,
  useShopDashboardTopItems,
  useShopDashboardProductionQueue,
  useShopDashboardRecentOrders,
  useShopDashboardBranchReviews,
} from "@/hooks/api/use-dashboard";

// ── Constants ─────────────────────────────────────────────────────────────────

const TABLE_STATUS_COLORS: Record<string, string> = {
  free: "oklch(72% 0.13 155)",
  occupied: "oklch(56% 0.15 240)",
  reserved: "oklch(80% 0.17 85)",
  cleaning: "oklch(80% 0.10 200)",
  out_of_service: "oklch(68% 0.008 60)",
};

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Money on this page is the SHOP's, so the currency comes from the shop and not
 * from the reader's language (#1260). This used to interpolate `¥` and group
 * digits ja-JP unconditionally, which showed a Vietnamese shop its VND takings
 * as yen.
 *
 * formatCurrencyCompact already does the ≥1M abbreviation this hand-rolled, and
 * does it per-locale.
 */
function fmt(n: number, locale: LocaleCode, currency: string | null) {
  return n >= 1_000_000
    ? formatCurrencyCompact(n, locale, currency ?? undefined)
    : formatCurrency(n, locale, currency ?? undefined);
}

interface StatCardProps {
  label: string;
  value: string;
  sub: string;
  delta: number;
  icon: React.ElementType;
  loading?: boolean;
}

function StatCard({ label, value, sub, delta, icon: Icon, loading }: StatCardProps) {
  const positive = delta > 0;
  const neutral = delta === 0;
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between px-4 pt-4 pb-1">
        <CardTitle className="text-xs font-medium text-muted-foreground">{label}</CardTitle>
        <Icon className="size-3.5 text-muted-foreground" />
      </CardHeader>
      <CardContent className="px-4 pb-4">
        {loading ? (
          <Spinner className="size-4" />
        ) : (
          <>
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
          </>
        )}
      </CardContent>
    </Card>
  );
}

function SectionError({ onRetry }: { onRetry: () => void }) {
  const { t } = useTranslation();
  return (
    <div className="flex items-center gap-2 py-4 text-xs text-muted-foreground">
      <span>{t("common.error_loading")}</span>
      <Button variant="outline" size="sm" onClick={onRetry}>
        {t("common.retry")}
      </Button>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ShopDashboardPage() {
  // Shop-scoped screen: the money is this shop's, so its currency is too.
  const shopCurrency = useShopCurrency();
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const kpisQuery = useShopDashboardKpis(shopSlug);
  const trendQuery = useShopDashboardRevenueTrend(shopSlug);
  const tableStatusQuery = useShopDashboardTableStatus(shopSlug);
  const topItemsQuery = useShopDashboardTopItems(shopSlug);
  const queueQuery = useShopDashboardProductionQueue(shopSlug);
  const recentOrdersQuery = useShopDashboardRecentOrders(shopSlug);
  const branchReviewsQuery = useShopDashboardBranchReviews(shopSlug);

  const kpis = kpisQuery.data?.data;
  const trendData = trendQuery.data?.data ?? [];
  const tableStatusData = (tableStatusQuery.data?.data ?? []).map((s) => ({
    name: t(`shop.dashboard.table_status.${s.status}`),
    value: s.count,
    color: TABLE_STATUS_COLORS[s.status] ?? "oklch(68% 0.008 60)",
    status: s.status,
  }));
  const topItems = topItemsQuery.data?.data ?? [];
  const productionQueue = queueQuery.data?.data ?? [];
  const recentOrders = recentOrdersQuery.data?.data ?? [];

  const revenueChartConfig: ChartConfig = {
    revenue: { label: t("dashboard.chart.revenue"), color: "oklch(56% 0.15 240)" },
    orders: { label: t("dashboard.chart.orders"), color: "oklch(72% 0.13 155)" },
  };

  const tableChartConfig: ChartConfig = {
    value: { label: t("shop.dashboard.table_status.title") },
  };

  // Today's revenue delta badge
  const revenueDelta = kpis?.revenue.delta_pct ?? 0;
  const revenueDeltaPositive = revenueDelta >= 0;

  function orderStatusBadge(status: string) {
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

  function productionStatusBadge(status: string) {
    if (status === "in_progress")
      return (
        <Badge color="info" variant="soft">
          {t("dashboard.orders.status.in_progress")}
        </Badge>
      );
    return (
      <Badge color="warning" variant="soft">
        {t("status.pending")}
      </Badge>
    );
  }

  return (
    <>
      <PageHeader title={t("shop.dashboard.title")}>
        <HelpPanel
          title={t("shop.dashboard.title")}
          subtitle={t("help.panel.shop_dashboard.subtitle")}
          purpose={t("help.panel.shop_dashboard.purpose")}
          usage={[
            t("help.panel.shop_dashboard.usage.1"),
            t("help.panel.shop_dashboard.usage.2"),
            t("help.panel.shop_dashboard.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_dashboard.checks.1"),
            t("help.panel.shop_dashboard.checks.2"),
            t("help.panel.shop_dashboard.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_dashboard.glossary.delta.term"),
              description: t("help.panel.shop_dashboard.glossary.delta.desc"),
            },
            {
              term: t("help.panel.shop_dashboard.glossary.low_stock.term"),
              description: t("help.panel.shop_dashboard.glossary.low_stock.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <div className="space-y-6">
          {/* ── KPI row ── */}
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard
              label={t("shop.dashboard.kpi.today_revenue")}
              value={kpis ? fmt(kpis.revenue.value, locale, shopCurrency) : "—"}
              sub={t("shop.dashboard.kpi.vs_yesterday")}
              delta={kpis?.revenue.delta_pct ?? 0}
              icon={Banknote}
              loading={kpisQuery.isLoading}
            />
            <StatCard
              label={t("shop.dashboard.kpi.today_orders")}
              value={
                kpis ? t("shop.dashboard.kpi.orders_count", { count: kpis.orders.value }) : "—"
              }
              sub={t("shop.dashboard.kpi.vs_yesterday")}
              delta={kpis?.orders.delta_pct ?? 0}
              icon={ShoppingCart}
              loading={kpisQuery.isLoading}
            />
            <StatCard
              label={t("shop.dashboard.kpi.table_occupancy")}
              value={
                kpis ? `${kpis.table_occupancy.occupied} / ${kpis.table_occupancy.total}` : "—"
              }
              sub={t("shop.dashboard.kpi.tables_occupied")}
              delta={0}
              icon={LayoutGrid}
              loading={kpisQuery.isLoading}
            />
            <StatCard
              label={t("shop.dashboard.low_stock")}
              value={kpis ? String(kpis.low_stock.value) : "—"}
              sub={t("shop.dashboard.kpi.below_minimum")}
              delta={0}
              icon={AlertTriangle}
              loading={kpisQuery.isLoading}
            />
          </div>

          {/* ── Daily trend + Table status ── */}
          <div className="grid gap-4 lg:grid-cols-3">
            {/* Revenue / Orders trend (7 days) */}
            <Card className="lg:col-span-2">
              <CardHeader className="px-4 pt-4 pb-2">
                <div className="flex items-start justify-between">
                  <div>
                    <CardTitle className="text-sm font-medium">
                      {t("dashboard.chart.revenue_trend")}
                    </CardTitle>
                    <CardDescription className="mt-0.5 text-xs">
                      {t("shop.dashboard.chart.last_7_days")}
                    </CardDescription>
                  </div>
                  {kpis && (
                    <Badge
                      variant="soft"
                      color={revenueDeltaPositive ? "success" : "destructive"}
                      className="text-xs"
                    >
                      {revenueDeltaPositive ? (
                        <TrendingUp className="mr-1 size-3" />
                      ) : (
                        <TrendingDown className="mr-1 size-3" />
                      )}
                      {t("shop.dashboard.kpi.vs_yesterday_pct", {
                        pct: `${revenueDeltaPositive ? "+" : ""}${revenueDelta}`,
                      })}
                    </Badge>
                  )}
                </div>
              </CardHeader>
              <CardContent className="px-4 pb-4">
                {trendQuery.isError ? (
                  <SectionError onRetry={() => trendQuery.refetch()} />
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
                          data={trendData}
                          margin={{ top: 4, right: 4, left: 0, bottom: 0 }}
                        >
                          <defs>
                            <linearGradient id="fillShopRevenue" x1="0" y1="0" x2="0" y2="1">
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
                            dataKey="day"
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                            tickFormatter={(v: string) => v.slice(5)}
                          />
                          <YAxis
                            tickFormatter={(v: number) => `${(v / 1000).toFixed(0)}k`}
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                            width={36}
                          />
                          <ChartTooltip
                            content={
                              <ChartTooltipContent
                                formatter={(value: unknown) =>
                                  fmt(Number(value), locale, shopCurrency)
                                }
                              />
                            }
                          />
                          <Area
                            type="monotone"
                            dataKey="revenue"
                            stroke="oklch(56% 0.15 240)"
                            strokeWidth={2}
                            fill="url(#fillShopRevenue)"
                            dot={false}
                            activeDot={{ r: 4, strokeWidth: 0 }}
                          />
                        </AreaChart>
                      </ChartContainer>
                    </TabsContent>
                    <TabsContent value="orders">
                      <ChartContainer config={revenueChartConfig} className="h-[200px] w-full">
                        <BarChart
                          data={trendData}
                          margin={{ top: 4, right: 4, left: 0, bottom: 0 }}
                        >
                          <CartesianGrid
                            vertical={false}
                            strokeDasharray="3 3"
                            stroke="oklch(90% 0.005 60)"
                          />
                          <XAxis
                            dataKey="day"
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                            tickFormatter={(v: string) => v.slice(5)}
                          />
                          <YAxis
                            tick={{ fontSize: 11 }}
                            tickLine={false}
                            axisLine={false}
                            width={28}
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

            {/* Table status pie */}
            <Card>
              <CardHeader className="px-4 pt-4 pb-2">
                <CardTitle className="text-sm font-medium">
                  {t("shop.dashboard.table_status.title")}
                </CardTitle>
                <CardDescription className="mt-0.5 text-xs">
                  {t("shop.dashboard.table_status.realtime")}
                </CardDescription>
              </CardHeader>
              <CardContent className="px-4 pb-4">
                {tableStatusQuery.isError ? (
                  <SectionError onRetry={() => tableStatusQuery.refetch()} />
                ) : tableStatusQuery.isLoading ? (
                  <div className="flex h-[140px] items-center justify-center">
                    <Spinner className="size-4" />
                  </div>
                ) : (
                  <>
                    <ChartContainer config={tableChartConfig} className="h-[140px] w-full">
                      <PieChart>
                        <Pie
                          data={tableStatusData}
                          cx="50%"
                          cy="50%"
                          innerRadius={40}
                          outerRadius={60}
                          paddingAngle={2}
                          dataKey="value"
                        >
                          {tableStatusData.map((entry, i) => (
                            <Cell key={i} fill={entry.color} />
                          ))}
                        </Pie>
                        <ChartTooltip
                          content={
                            <ChartTooltipContent
                              formatter={(value: unknown) =>
                                t("shop.dashboard.table_status.tables_count", {
                                  count: Number(value),
                                })
                              }
                              nameKey="name"
                            />
                          }
                        />
                      </PieChart>
                    </ChartContainer>
                    <div className="mt-2 space-y-1.5">
                      {tableStatusData.map((s) => (
                        <div key={s.status} className="flex items-center justify-between text-xs">
                          <div className="flex items-center gap-1.5">
                            <span
                              className="inline-block size-2 shrink-0 rounded-full"
                              style={{ background: s.color }}
                            />
                            <span className="text-muted-foreground">{s.name}</span>
                          </div>
                          <span className="font-medium tabular-nums">{s.value}</span>
                        </div>
                      ))}
                    </div>
                  </>
                )}
              </CardContent>
            </Card>
          </div>

          {/* ── Branch Reviews ── */}
          <Card>
            <CardHeader className="px-4 pt-4 pb-2">
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="text-sm font-medium">
                    {t("shop.dashboard.reviews.title")}
                  </CardTitle>
                  <CardDescription className="mt-0.5 text-xs">
                    {t("shop.dashboard.reviews.desc")}
                  </CardDescription>
                </div>
                {branchReviewsQuery.data?.data && (
                  <div className="flex items-center gap-1.5">
                    <Star className="size-4 fill-amber-400 text-amber-400" />
                    <span className="text-lg font-bold tabular-nums">
                      {branchReviewsQuery.data.data.avg_rating?.toFixed(1) ?? "—"}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      ({branchReviewsQuery.data.data.total_count})
                    </span>
                  </div>
                )}
              </div>
            </CardHeader>
            <CardContent className="px-0 pb-2">
              {branchReviewsQuery.isError ? (
                <div className="px-4">
                  <SectionError onRetry={() => branchReviewsQuery.refetch()} />
                </div>
              ) : branchReviewsQuery.isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Spinner className="size-4" />
                </div>
              ) : !branchReviewsQuery.data?.data.recent.length ? (
                <p className="px-4 py-4 text-xs text-muted-foreground">{t("common.no_data")}</p>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent">
                      <TableHead className="h-8 px-4 text-xs">
                        {t("shop.dashboard.reviews.col.rating")}
                      </TableHead>
                      <TableHead className="h-8 px-4 text-xs">
                        {t("shop.dashboard.reviews.col.comment")}
                      </TableHead>
                      <TableHead className="h-8 px-4 text-right text-xs">
                        {t("shop.dashboard.reviews.col.date")}
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {branchReviewsQuery.data.data.recent.map((review) => (
                      <TableRow key={review.id} className="text-xs">
                        <TableCell className="px-4 py-2">
                          <div className="flex items-center gap-0.5">
                            {[1, 2, 3, 4, 5].map((s) => (
                              <Star
                                key={s}
                                className={`size-3 ${
                                  s <= review.rating
                                    ? "fill-amber-400 text-amber-400"
                                    : "text-muted-foreground/30"
                                }`}
                              />
                            ))}
                          </div>
                        </TableCell>
                        <TableCell className="max-w-[300px] truncate px-4 py-2 text-muted-foreground">
                          {review.comment || "—"}
                        </TableCell>
                        <TableCell className="px-4 py-2 text-right text-muted-foreground">
                          {formatDate(review.created_at, locale, timezone)}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>

          {/* ── Top items + Production queue ── */}
          <div className="grid gap-4 lg:grid-cols-2">
            {/* Top 5 items today */}
            <Card>
              <CardHeader className="px-4 pt-4 pb-2">
                <CardTitle className="text-sm font-medium">
                  {t("dashboard.products.top5")}
                </CardTitle>
                <CardDescription className="mt-0.5 text-xs">
                  {t("shop.dashboard.top_items.by_sales_today")}
                </CardDescription>
              </CardHeader>
              <CardContent className="px-0 pb-2">
                {topItemsQuery.isError ? (
                  <div className="px-4">
                    <SectionError onRetry={() => topItemsQuery.refetch()} />
                  </div>
                ) : topItemsQuery.isLoading ? (
                  <div className="flex items-center justify-center py-8">
                    <Spinner className="size-4" />
                  </div>
                ) : topItems.length === 0 ? (
                  <p className="px-4 py-4 text-xs text-muted-foreground">{t("common.no_data")}</p>
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
                      {topItems.map((item) => (
                        <TableRow key={item.product_id} className="text-xs">
                          <TableCell className="px-4 py-2">
                            <div className="leading-snug font-medium">{item.product_name}</div>
                            <div className="mt-0.5 text-[11px] text-muted-foreground">
                              {item.category_name}
                            </div>
                          </TableCell>
                          <TableCell className="px-4 py-2 text-right tabular-nums">
                            {item.sold}
                          </TableCell>
                          <TableCell className="px-4 py-2 text-right font-medium tabular-nums">
                            {fmt(item.revenue, locale, shopCurrency)}
                          </TableCell>
                          <TableCell className="px-4 py-2">
                            {item.trend === "up" ? (
                              <TrendingUp className="size-3 text-success" />
                            ) : (
                              <TrendingDown className="size-3 text-destructive" />
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </CardContent>
            </Card>

            {/* Production queue */}
            <Card>
              <CardHeader className="px-4 pt-4 pb-2">
                <div className="flex items-center justify-between">
                  <div>
                    <CardTitle className="text-sm font-medium">
                      {t("shop.dashboard.production.title")}
                    </CardTitle>
                    <CardDescription className="mt-0.5 text-xs">
                      {t("shop.dashboard.production.desc")}
                    </CardDescription>
                  </div>
                  <Badge variant="outline" className="text-xs">
                    {t("shop.dashboard.production.count", { count: productionQueue.length })}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="px-0 pb-2">
                {queueQuery.isError ? (
                  <div className="px-4">
                    <SectionError onRetry={() => queueQuery.refetch()} />
                  </div>
                ) : queueQuery.isLoading ? (
                  <div className="flex items-center justify-center py-8">
                    <Spinner className="size-4" />
                  </div>
                ) : productionQueue.length === 0 ? (
                  <p className="px-4 py-4 text-xs text-muted-foreground">{t("common.no_data")}</p>
                ) : (
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent">
                        <TableHead className="h-8 px-4 text-xs">
                          {t("dashboard.orders.col.id")}
                        </TableHead>
                        <TableHead className="h-8 px-4 text-xs">
                          {t("dashboard.products.col.product")}
                        </TableHead>
                        <TableHead className="h-8 px-4 text-right text-xs">
                          {t("shop.dashboard.production.col.qty")}
                        </TableHead>
                        <TableHead className="h-8 px-4 text-xs">{t("common.status")}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {productionQueue.map((po, i) => (
                        <TableRow key={`${po.order_code}-${i}`} className="text-xs">
                          <TableCell className="px-4 py-2 font-mono text-muted-foreground">
                            {po.order_code}
                          </TableCell>
                          <TableCell className="px-4 py-2 font-medium">{po.product_name}</TableCell>
                          <TableCell className="px-4 py-2 text-right tabular-nums">
                            {po.quantity}
                          </TableCell>
                          <TableCell className="px-4 py-2">
                            {productionStatusBadge(po.status)}
                          </TableCell>
                        </TableRow>
                      ))}
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
                {kpis && (
                  <Badge variant="outline" className="text-xs">
                    {t("dashboard.orders.today_count", { count: kpis.orders.value })}
                  </Badge>
                )}
              </div>
            </CardHeader>
            <Separator />
            <CardContent className="px-0 pb-2">
              {recentOrdersQuery.isError ? (
                <div className="px-4">
                  <SectionError onRetry={() => recentOrdersQuery.refetch()} />
                </div>
              ) : recentOrdersQuery.isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Spinner className="size-4" />
                </div>
              ) : recentOrders.length === 0 ? (
                <p className="px-4 py-4 text-xs text-muted-foreground">{t("common.no_data")}</p>
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
                    {recentOrders.map((order) => (
                      <TableRow key={order.id} className="text-xs">
                        <TableCell className="px-4 py-2 font-mono text-muted-foreground">
                          {order.order_code}
                        </TableCell>
                        <TableCell className="px-4 py-2">{order.table_code ?? "—"}</TableCell>
                        <TableCell className="px-4 py-2 text-right tabular-nums">
                          {order.items_count}
                        </TableCell>
                        <TableCell className="px-4 py-2 text-right font-medium tabular-nums">
                          {fmt(order.total_amount, locale, shopCurrency)}
                        </TableCell>
                        <TableCell className="px-4 py-2">
                          {orderStatusBadge(order.status)}
                        </TableCell>
                        <TableCell className="px-4 py-2 text-right text-muted-foreground">
                          {formatTime(order.created_at, locale, timezone)}
                        </TableCell>
                      </TableRow>
                    ))}
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
