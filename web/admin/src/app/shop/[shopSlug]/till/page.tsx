"use client";

/**
 * Plan-036 — Manager Till Tracking Dashboard.
 *
 * Polled every 5 seconds (pauses when the tab is hidden). Shows four KPI
 * cards, per-till status cards, a variance trend chart, the most recent
 * settlements, and reuses the plan-032 force-abandon activity card.
 */

import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Skeleton,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import {
  CartesianGrid,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import { useTranslation } from "@/providers/app-provider";
import { formatCurrency, formatCurrencyCompact } from "@/lib/currency";
import type { LocaleCode } from "@/i18n";
import { useShopTillDashboard } from "@/hooks/api/use-shop-till-tracking";
import type { DashboardTill, TillStatus, TrendDays } from "@/services/shop-till-tracking-service";

import { HelpPanel } from "@/components/shared/help-panel";
import { SessionsHistoryTab } from "./sessions/components/sessions-history-tab";
import { StaleSessionsPanel, isStaleFilter } from "./components/stale-sessions-panel";

const TREND_OPTIONS: TrendDays[] = [7, 14, 30, 90];

function isTrendDays(v: string | null): v is `${TrendDays}` {
  return v !== null && ["7", "14", "30", "90"].includes(v);
}

type TillTab = "overview" | "history" | "stale";
const isTillTab = (v: string | null): v is TillTab =>
  v === "overview" || v === "history" || v === "stale";

const TILL_STATUSES: TillStatus[] = ["open", "closing", "settled", "abandoned", "expired"];

/**
 * `?status=open` / `?status=open,closing` — seeds the history tab's status
 * chips (#1221).
 *
 * The currency guard needs to show "the shifts blocking you right now", and
 * those are open shifts of any age. It used to link at `?filter=open_overdue`,
 * which only ever lists shifts past the overdue band — so a 3-hour-old blocking
 * shift landed the operator on an empty tab. Status is the right axis for that
 * question; age is not.
 *
 * Unknown values are dropped rather than passed through: the backend 422s an
 * invalid `status[]`, which would turn a mistyped link into an error screen.
 */
function parseStatusParam(raw: string | null): TillStatus[] | undefined {
  if (!raw) return undefined;
  const picked = raw
    .split(",")
    .map((s) => s.trim())
    .filter((s): s is TillStatus => (TILL_STATUSES as string[]).includes(s));
  return picked.length > 0 ? picked : undefined;
}

export default function ShopTillDashboardPage() {
  const { t, locale } = useTranslation();
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const router = useRouter();
  const searchParams = useSearchParams();
  const urlTrend = searchParams.get("trend_days");
  const trendDays = (isTrendDays(urlTrend) ? Number(urlTrend) : 14) as TrendDays;

  const { data, isLoading, isError, refetch, isFetching } = useShopTillDashboard(shopSlug, {
    trendDays,
  });

  const dashboard = data?.data;
  const meta = data?.meta;

  const staleCount = dashboard
    ? dashboard.kpis.stale_count.open_overdue + dashboard.kpis.stale_count.expired
    : 0;

  // Which of the three tabs is active, derived from the URL so deep links and
  // the browser back button work. `?filter=` (plan-031 settings deep link)
  // lands on the Ca treo tab with that stale filter preselected.
  const urlTab = searchParams.get("tab");
  const urlFilter = searchParams.get("filter");
  const initialStaleFilter = isStaleFilter(urlFilter) ? urlFilter : undefined;
  const initialHistoryStatus = parseStatusParam(searchParams.get("status"));
  const activeTab: TillTab = isTillTab(urlTab) ? urlTab : urlFilter ? "stale" : "overview";

  const setTab = (next: TillTab) => {
    const q = new URLSearchParams(searchParams.toString());
    q.set("tab", next);
    router.replace(`/shop/${shopSlug}/till?${q.toString()}`);
  };

  const setTrend = (next: TrendDays) => {
    const q = new URLSearchParams(searchParams.toString());
    q.set("trend_days", String(next));
    router.replace(`/shop/${shopSlug}/till?${q.toString()}`);
  };

  const currency = dashboard?.currency_code ?? "JPY";
  const fmt = (amount: number | null | undefined) => formatCurrency(amount ?? 0, locale, currency);
  const varianceNet = dashboard?.kpis.variance_today.net_amount ?? 0;
  const varianceColor =
    varianceNet === 0
      ? "text-muted-foreground"
      : varianceNet > 0
        ? "text-emerald-600"
        : "text-red-600";

  return (
    <div data-slot="shop-till-dashboard" className="p-6">
      <Tabs value={activeTab} onValueChange={(v) => setTab(v as TillTab)}>
        {/* The help panel sits beside the tab strip rather than inside a tab:
            this screen has no PageHeader, and the panel documents all three
            tabs, so it must stay reachable from whichever one is open. */}
        <div className="flex items-center justify-between gap-2">
          <TabsList>
            <TabsTrigger value="overview">{t("till_tracking.tabs.overview")}</TabsTrigger>
            <TabsTrigger value="history">{t("till_tracking.tabs.history")}</TabsTrigger>
            <TabsTrigger value="stale" className="gap-1.5">
              {t("till_tracking.tabs.stale")}
              {staleCount > 0 && (
                <Badge variant="destructive" className="px-1.5">
                  {staleCount}
                </Badge>
              )}
            </TabsTrigger>
          </TabsList>
          <HelpPanel
            title={t("till_tracking.dashboard.title")}
            subtitle={t("help.panel.shop_till.subtitle")}
            purpose={t("help.panel.shop_till.purpose")}
            usage={[
              t("help.panel.shop_till.usage.1"),
              t("help.panel.shop_till.usage.2"),
              t("help.panel.shop_till.usage.3"),
              t("help.panel.shop_till.usage.4"),
            ]}
            checks={[
              t("help.panel.shop_till.checks.1"),
              t("help.panel.shop_till.checks.2"),
              t("help.panel.shop_till.checks.3"),
            ]}
            glossary={[
              {
                term: t("help.panel.shop_till.glossary.variance.term"),
                description: t("help.panel.shop_till.glossary.variance.desc"),
              },
              {
                term: t("help.panel.shop_till.glossary.stale.term"),
                description: t("help.panel.shop_till.glossary.stale.desc"),
              },
              {
                term: t("help.panel.shop_till.glossary.stale_bands.term"),
                description: t("help.panel.shop_till.glossary.stale_bands.desc"),
              },
              {
                term: t("help.panel.shop_till.glossary.open_tills.term"),
                description: t("help.panel.shop_till.glossary.open_tills.desc"),
              },
            ]}
          />
        </div>

        <TabsContent value="overview" className="mt-4 space-y-4">
          <div className="flex items-start justify-between">
            <div>
              <h1 className="text-xl font-semibold">{t("till_tracking.dashboard.title")}</h1>
              <p className="mt-1 text-sm text-muted-foreground">
                {t("till_tracking.dashboard.subtitle")}
                {meta?.cached_at && (
                  <span className="ml-2 text-xs">
                    {t("till_tracking.dashboard.updated_at", {
                      time: meta.cached_at.slice(11, 19),
                    })}
                  </span>
                )}
              </p>
            </div>
            {isFetching && (
              <span className="text-xs text-muted-foreground">
                {t("till_tracking.dashboard.refreshing")}
              </span>
            )}
          </div>

          {isError && (
            <Alert variant="destructive">
              <AlertTitle>{t("till_tracking.dashboard.error_title")}</AlertTitle>
              <AlertDescription>
                <Button variant="link" onClick={() => refetch()} className="px-0">
                  {t("till_tracking.dashboard.retry")}
                </Button>
              </AlertDescription>
            </Alert>
          )}

          {/* ─── KPI cards ─────────────────────────────────────────────── */}
          <div
            data-slot="kpi-grid"
            className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
          >
            <KpiCard
              title={t("till_tracking.dashboard.kpi.open_tills")}
              loading={isLoading}
              value={
                dashboard
                  ? `${dashboard.kpis.open_till_count} / ${dashboard.kpis.total_till_count}`
                  : ""
              }
            />
            <KpiCard
              title={t("till_tracking.dashboard.kpi.settled_today")}
              loading={isLoading}
              value={dashboard ? `${dashboard.kpis.settled_today.count}` : ""}
              subtitle={
                dashboard
                  ? formatCurrency(
                      dashboard.kpis.settled_today.gross_total_amount,
                      locale,
                      dashboard.kpis.settled_today.currency_code
                    )
                  : ""
              }
            />
            <KpiCard
              title={t("till_tracking.dashboard.kpi.variance_today")}
              loading={isLoading}
              value={dashboard ? fmt(varianceNet) : ""}
              valueClass={varianceColor}
            />
            <KpiCard
              title={t("till_tracking.dashboard.kpi.stale_count")}
              loading={isLoading}
              value={
                dashboard
                  ? `${dashboard.kpis.stale_count.open_overdue + dashboard.kpis.stale_count.expired}`
                  : ""
              }
              subtitle={
                dashboard
                  ? t("till_tracking.dashboard.kpi.stale_breakdown", {
                      overdue: dashboard.kpis.stale_count.open_overdue,
                      expired: dashboard.kpis.stale_count.expired,
                    })
                  : ""
              }
              onClick={() => setTab("stale")}
              interactive
            />
          </div>

          {/* ─── Per-till status row ───────────────────────────────────── */}
          <Card>
            <CardHeader>
              <CardTitle>{t("till_tracking.dashboard.tills_title")}</CardTitle>
              <CardDescription>{t("till_tracking.dashboard.tills_subtitle")}</CardDescription>
            </CardHeader>
            <CardContent>
              {isLoading ? (
                <Skeleton className="h-20 w-full" />
              ) : dashboard?.tills.length ? (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {dashboard.tills.map((till) => (
                    <TillStatusCard key={till.id} till={till} shopSlug={shopSlug} />
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">
                  {t("till_tracking.dashboard.tills_empty")}
                </p>
              )}
            </CardContent>
          </Card>

          {/* ─── Variance trend ────────────────────────────────────────── */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <div>
                <CardTitle>{t("till_tracking.dashboard.trend_title")}</CardTitle>
                <CardDescription>
                  {t("till_tracking.dashboard.trend_subtitle", { days: trendDays })}
                </CardDescription>
              </div>
              <Tabs
                value={String(trendDays)}
                onValueChange={(v) => setTrend(Number(v) as TrendDays)}
              >
                <TabsList>
                  {TREND_OPTIONS.map((d) => (
                    <TabsTrigger key={d} value={String(d)}>
                      {t("till_tracking.dashboard.trend_range", { days: d })}
                    </TabsTrigger>
                  ))}
                </TabsList>
              </Tabs>
            </CardHeader>
            <CardContent>
              {isLoading ? (
                <Skeleton className="h-32 w-full" />
              ) : dashboard?.variance_trend.length ? (
                <VarianceTrendChart
                  rows={dashboard.variance_trend}
                  currency={currency}
                  locale={locale}
                />
              ) : (
                <p className="text-sm text-muted-foreground">
                  {t("till_tracking.dashboard.trend_empty")}
                </p>
              )}
            </CardContent>
          </Card>

          {/* ─── Recent settlements ────────────────────────────────────── */}
          <Card>
            <CardHeader>
              <CardTitle>{t("till_tracking.dashboard.recent_title")}</CardTitle>
              <CardDescription>{t("till_tracking.dashboard.recent_subtitle")}</CardDescription>
            </CardHeader>
            <CardContent>
              {isLoading ? (
                <Skeleton className="h-24 w-full" />
              ) : dashboard?.recent_settlements.length ? (
                <ul className="divide-y">
                  {dashboard.recent_settlements.map((row) => (
                    <li
                      key={row.id}
                      className="flex cursor-pointer items-center justify-between py-2 text-sm hover:bg-muted/30"
                      onClick={() => router.push(`/shop/${shopSlug}/till/sessions/${row.id}`)}
                    >
                      <div>
                        <div className="font-medium">{row.session_code}</div>
                        <div className="text-xs text-muted-foreground">
                          {row.till_name} · {row.opener_name ?? "—"}
                        </div>
                      </div>
                      <div className="text-right">
                        <div>{fmt(row.counted_cash_amount)}</div>
                        <div
                          className={
                            row.variance_amount === 0
                              ? "text-xs text-muted-foreground"
                              : row.variance_amount > 0
                                ? "text-xs text-emerald-600"
                                : "text-xs text-red-600"
                          }
                        >
                          {fmt(row.variance_amount)}
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted-foreground">
                  {t("till_tracking.dashboard.recent_empty")}
                </p>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="history" className="mt-4">
          <SessionsHistoryTab
            shopSlug={shopSlug}
            initialFilters={
              initialHistoryStatus
                ? { status: initialHistoryStatus, per_page: 25, page: 1 }
                : undefined
            }
          />
        </TabsContent>

        <TabsContent value="stale" className="mt-4">
          <StaleSessionsPanel
            shopSlug={shopSlug}
            initialFilter={initialStaleFilter}
            counts={dashboard?.kpis.stale_count}
          />
        </TabsContent>
      </Tabs>
    </div>
  );
}

// =============================================================================
//  Local primitives
// =============================================================================

interface TillStatusCardProps {
  till: DashboardTill;
  shopSlug: string;
}

/**
 * One per-till status card. When the till has a shift open the whole card links
 * to that session's detail page — until now the card was the only place a stuck
 * shift surfaced on the overview and it had no affordance at all, so a manager
 * saw the amber "quá hạn" badge with nowhere to click (plan-036 TASKS §178).
 *
 * A real `<Link>` rather than an `onClick` div: it keeps keyboard focus, and
 * middle-click / cmd-click open the session in a new tab, which is how a manager
 * reconciling several tills actually works. An idle till renders the plain card.
 */
function TillStatusCard({ till, shopSlug }: TillStatusCardProps) {
  const { t } = useTranslation();
  const session = till.current_session;

  const card = (
    <Card
      data-slot="till-card"
      className={`border-muted${session ? "transition-colors hover:bg-muted/30" : ""}`}
    >
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center justify-between text-base">
          <span>{till.name}</span>
          <Badge variant={till.status === "open" ? "default" : "secondary"}>
            {t(`till_tracking.dashboard.till_status.${till.status}`)}
          </Badge>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-1 pt-0 text-sm">
        {session ? (
          <>
            <div className="text-muted-foreground">{session.session_code}</div>
            <div className="text-muted-foreground">{session.opener_name ?? "—"}</div>
            <div className="text-xs">
              {t("till_tracking.dashboard.duration", {
                hours: session.duration_hours.toFixed(1),
              })}
            </div>
            {session.stale_critical && (
              <Badge variant="destructive">{t("till_tracking.dashboard.stale_critical")}</Badge>
            )}
            {!session.stale_critical && session.stale_warning && (
              <Badge variant="outline" className="border-amber-500 text-amber-600">
                {t("till_tracking.dashboard.stale_warning")}
              </Badge>
            )}
          </>
        ) : (
          <span className="text-muted-foreground">{t("till_tracking.dashboard.till_idle")}</span>
        )}
      </CardContent>
    </Card>
  );

  if (!session) return card;

  return (
    <Link
      href={`/shop/${shopSlug}/till/sessions/${session.id}`}
      className="block rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
    >
      {card}
    </Link>
  );
}

interface KpiCardProps {
  title: string;
  value: string;
  subtitle?: string;
  loading?: boolean;
  valueClass?: string;
  interactive?: boolean;
  onClick?: () => void;
}

function KpiCard({
  title,
  value,
  subtitle,
  loading,
  valueClass,
  interactive,
  onClick,
}: KpiCardProps) {
  return (
    <Card
      data-slot="kpi-card"
      onClick={onClick}
      className={interactive ? "cursor-pointer transition-colors hover:bg-muted/30" : undefined}
    >
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        {loading ? (
          <Skeleton className="h-8 w-24" />
        ) : (
          <>
            <div className={`text-2xl font-semibold ${valueClass ?? ""}`}>{value}</div>
            {subtitle && <div className="mt-1 text-xs text-muted-foreground">{subtitle}</div>}
          </>
        )}
      </CardContent>
    </Card>
  );
}

interface VarianceTrendChartProps {
  rows: Array<{ date: string; avg_amount: number; abs_max_amount: number; session_count: number }>;
  currency: string;
  locale: LocaleCode;
}

function VarianceTrendChart({ rows, currency, locale }: VarianceTrendChartProps) {
  // Variance trend as a recharts LineChart with a zero reference line — "did
  // we drift above or below" reads at a glance, which the earlier inline
  // bars (positive-only height) hid. Line is amber so the dashboard's KPI
  // semantics stay intact (green = good, red = bad reserved for the KPI
  // variance card; amber = "watch this").
  const data = rows.map((r) => ({
    ...r,
    // Short MM-DD label keeps 90-day axes legible without a custom tick.
    label: r.date.slice(5),
  }));
  return (
    <div className="h-32 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
          <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
          <XAxis
            dataKey="label"
            tick={{ fontSize: 10 }}
            interval="preserveStartEnd"
            minTickGap={20}
          />
          <YAxis
            tick={{ fontSize: 10 }}
            // The KPI card above passes the shop's currency (line ~207); this
            // axis did not, so a shop on any currency other than the UI
            // locale's default had its chart labelled in the wrong one while
            // the card beside it was right. `currency` is already resolved at
            // the top of this component.
            tickFormatter={(v: number) => formatCurrencyCompact(v, locale, currency)}
            width={64}
          />
          <ReferenceLine y={0} stroke="currentColor" className="text-muted-foreground/60" />
          <Tooltip
            formatter={(value) =>
              [formatCurrency(Number(value), locale, currency), "avg variance"] as [string, string]
            }
            labelFormatter={(_label, payload) => {
              const row = payload?.[0]?.payload as
                { date: string; session_count: number } | undefined;
              return row ? `${row.date} · n=${row.session_count}` : "";
            }}
            contentStyle={{ fontSize: 11 }}
          />
          <Line
            type="monotone"
            dataKey="avg_amount"
            stroke="#f59e0b"
            strokeWidth={2}
            dot={{ r: 2 }}
            isAnimationActive={false}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
