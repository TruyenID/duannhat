import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { PosHeader } from "@/app/pos/components/pos-header";
import {
  Button,
  Calendar,
  Card,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import {
  ArrowLeftIcon,
  CalendarIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ChevronsLeftIcon,
  ChevronsRightIcon,
  MaximizeIcon,
  MinusIcon,
  PlusIcon,
  TrendingDownIcon,
  TrendingUpIcon,
  XIcon,
} from "lucide-react";
import { setCurrentShopSlug } from "@/lib/shop-context";
import { formatDate, formatDateTime, formatYmd } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";
import { useShop } from "@/hooks/api/use-shop";
import { useShopOrderSettings } from "@/hooks/api/use-shop-order-settings";
import {
  useRevenueByProduct,
  useRevenueSummary,
  useRevenueVoidEvents,
  useRevenueVoids,
} from "@/hooks/api/use-revenue";
import type {
  RevenueGranularity,
  RevenueSeriesPoint,
  RevenueSummary,
  RevenueVoidEvent,
  RevenueVoidEventType,
  RevenueVoidReason,
  RevenueVoids,
  RevenueVoidSeriesPoint,
  RevenueVoidTopItem,
} from "@/services/revenue-service";
import { isProvisionalCode } from "@/app/pos/lib/order-code";
import { cn } from "@/lib/utils";

/**
 * Revenue report — 日別 / 月別 doanh thu.
 *
 * URL: /shop/:shopSlug/reports/revenue
 *
 * Data source is identical for LAN and Cloud — pos-web's apiFetch routes the
 * call through the workstation LAN base URL first and falls back to Cloud,
 * so the `source` field in the response surfaces which one served us.
 */
export function RevenuePage() {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();
  const navigate = useNavigate();
  const { t, locale } = useTranslation();

  useEffect(() => {
    setCurrentShopSlug(shopSlug);
  }, [shopSlug]);

  const { data: shopResponse } = useShop(shopSlug);
  const shopSettings = useShopOrderSettings(shopSlug);
  const currency = shopSettings.data?.data.currency_code ?? "JPY";
  const shop = shopResponse?.data;

  const [topView, setTopView] = useState<"time" | "product" | "voids">("time");
  const now = useMemo(() => new Date(), []);
  const [granularity, setGranularity] = useState<RevenueGranularity>("day");
  const [year, setYear] = useState<number>(now.getFullYear());
  const [month, setMonth] = useState<number>(now.getMonth() + 1); // 1-12
  const [customRange, setCustomRange] = useState<
    { from: Date; to: Date } | null
  >(null);

  const { from, to } = useMemo(() => {
    if (customRange) {
      return {
        from: formatYmd(customRange.from),
        to: formatYmd(customRange.to),
      };
    }
    return computeRange(granularity, year, month);
  }, [customRange, granularity, year, month]);

  const { data, isLoading, error } = useRevenueSummary(
    shopSlug,
    { granularity, from, to },
  );

  const moneyFmt = useMemo(
    () =>
      new Intl.NumberFormat(locale, {
        style: "currency",
        currency,
        // JPY/VND have 0 decimals, USD/EUR/etc. have 2 — let Intl pick from
        // the currency code instead of hard-capping at 0.
      }),
    [locale, currency],
  );
  const numberFmt = useMemo(() => new Intl.NumberFormat(locale), [locale]);

  const shiftPeriod = (delta: number) => {
    // Year mode shifts the trailing 5-year anchor by ±1 year. Month
    // mode shifts the whole year ±1. Day mode shifts ±1 month with
    // proper year-rollover.
    if (granularity === "year") {
      setYear((y) => y + delta);
      return;
    }
    if (granularity === "month") {
      setYear((y) => y + delta);
      return;
    }
    let m = month + delta;
    let y = year;
    while (m < 1) {
      m += 12;
      y -= 1;
    }
    while (m > 12) {
      m -= 12;
      y += 1;
    }
    setMonth(m);
    setYear(y);
  };

  return (
    <div className="min-h-dvh bg-muted/30 text-foreground">
      <PosHeader shopName={shop?.name ?? ""} helpTopic="revenue" />

      <div className="border-b bg-card/60">
        <div className="mx-auto flex max-w-[1600px] flex-wrap items-center gap-3 px-3 py-2 sm:px-6">
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate(`/shop/${shopSlug}`)}
            className="h-9 gap-1.5 rounded-md border-border bg-background px-3 shadow-sm hover:bg-muted"
          >
            <ArrowLeftIcon className="size-4" />
            <span>{t("revenue.back")}</span>
          </Button>
          <div className="h-6 w-px bg-border" />
          <h2 className="text-sm font-semibold tracking-tight text-foreground">
            {t("revenue.title")}
          </h2>
        </div>
      </div>

      <main className="mx-auto max-w-[1600px] space-y-4 px-6 py-5">
        <Tabs
          value={topView}
          onValueChange={(v) => setTopView(v as "time" | "product" | "voids")}
        >
          <TabsList>
            <TabsTrigger value="time">{t("revenue.view.time")}</TabsTrigger>
            <TabsTrigger value="product">
              {t("revenue.view.product")}
            </TabsTrigger>
            <TabsTrigger value="voids">{t("revenue.view.voids")}</TabsTrigger>
          </TabsList>

          <TabsContent value="time" className="mt-4 space-y-4">
        {/* KPI strip */}
        <KpiStrip
          data={data}
          isLoading={isLoading}
          moneyFmt={moneyFmt}
          numberFmt={numberFmt}
          t={t}
        />

        {/* Filter bar */}
        <Card className="px-4 py-3">
          <div className="flex flex-wrap items-center gap-4">
            {/* Granularity segmented control */}
            <Tabs
              value={granularity}
              onValueChange={(v) => setGranularity(v as RevenueGranularity)}
            >
              <TabsList>
                <TabsTrigger value="day">{t("revenue.tab.daily")}</TabsTrigger>
                <TabsTrigger value="month">
                  {t("revenue.tab.monthly")}
                </TabsTrigger>
                <TabsTrigger value="year">
                  {t("revenue.tab.yearly")}
                </TabsTrigger>
              </TabsList>
            </Tabs>

            {/* Period stepper: ← [year][month?] → — disabled when a custom
                range is active so the two controls don't fight each other. */}
            <div
              className={cn(
                "inline-flex items-center rounded-md border bg-background shadow-sm transition-opacity",
                customRange && "opacity-50",
              )}
            >
              <Button
                variant="ghost"
                size="sm"
                className="size-9 rounded-r-none px-0 hover:bg-muted"
                onClick={() => shiftPeriod(-1)}
                disabled={!!customRange}
                aria-label={t("revenue.prev")}
              >
                <ChevronLeftIcon className="size-4" />
              </Button>

              <div className="flex items-center gap-1 border-x px-2">
                <Select
                  value={String(year)}
                  onValueChange={(v) => setYear(Number(v))}
                  disabled={!!customRange}
                >
                  <SelectTrigger className="h-8 w-[88px] border-0 shadow-none focus:ring-0 focus:ring-offset-0">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {yearOptions(now.getFullYear()).map((y) => (
                      <SelectItem key={y} value={String(y)}>
                        {y}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>

                {granularity === "day" ? (
                  <>
                    <span className="text-muted-foreground">/</span>
                    <Select
                      value={String(month)}
                      onValueChange={(v) => setMonth(Number(v))}
                      disabled={!!customRange}
                    >
                      <SelectTrigger className="h-8 w-[120px] border-0 shadow-none focus:ring-0 focus:ring-offset-0">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {Array.from({ length: 12 }, (_, i) => i + 1).map(
                          (m) => (
                            <SelectItem key={m} value={String(m)}>
                              {t(`revenue.month_${m}`)}
                            </SelectItem>
                          ),
                        )}
                      </SelectContent>
                    </Select>
                  </>
                ) : null}
              </div>

              <Button
                variant="ghost"
                size="sm"
                className="size-9 rounded-l-none px-0 hover:bg-muted"
                onClick={() => shiftPeriod(1)}
                disabled={!!customRange}
                aria-label={t("revenue.next")}
              >
                <ChevronRightIcon className="size-4" />
              </Button>
            </div>

            {/* Range chip — click to pick a custom date range. Becomes a
                primary outline when a custom range is active so the
                difference vs preset year/month is obvious. */}
            <div className="ml-auto flex items-center gap-2">
              <Popover>
                <PopoverTrigger asChild>
                  <button
                    type="button"
                    className={cn(
                      "inline-flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-1.5 text-xs tabular-nums text-muted-foreground transition-colors hover:bg-muted",
                      customRange && "border-primary bg-primary/5 text-foreground",
                    )}
                    aria-label={t("revenue.range_pick")}
                  >
                    <CalendarIcon className="size-3.5" />
                    <span className="font-medium text-foreground">
                      {formatDate(from, locale)}
                    </span>
                    <span>→</span>
                    <span className="font-medium text-foreground">
                      {formatDate(to, locale)}
                    </span>
                  </button>
                </PopoverTrigger>
                <PopoverContent
                  align="end"
                  className="w-auto overflow-hidden p-0"
                >
                  <div className="flex">
                    {/* Presets sidebar */}
                    <div className="flex w-40 flex-col gap-1 border-r bg-muted/30 p-3">
                      <div className="mb-1 px-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {t("revenue.range_presets")}
                      </div>
                      {PRESETS.map((preset) => {
                        const range = preset.compute();
                        const isActive =
                          customRange &&
                          formatYmd(customRange.from) ===
                            formatYmd(range.from) &&
                          formatYmd(customRange.to) === formatYmd(range.to);
                        return (
                          <button
                            key={preset.key}
                            type="button"
                            onClick={() => setCustomRange(range)}
                            className={cn(
                              "rounded-md px-2 py-1.5 text-left text-xs transition-colors hover:bg-background",
                              isActive
                                ? "bg-primary/10 font-medium text-primary"
                                : "text-foreground",
                            )}
                          >
                            {t(preset.labelKey)}
                          </button>
                        );
                      })}
                    </div>

                    {/* Calendar + footer */}
                    <div className="flex flex-col">
                      {/* `relative` here so the Calendar's absolutely-
                          positioned prev/next nav buttons attach inside
                          the calendar box and don't bleed onto the
                          sidebar or out of the popover. */}
                      {/* Wrapper provides positioning ancestor for the
                          Calendar's absolutely-positioned prev/next nav,
                          and adds m-3 so those buttons (which sit at the
                          wrapper's corners) stay clear of the popover
                          edges. */}
                      <div className="relative w-fit m-3 [&_nav]:z-20 [&_nav>button]:!size-8 [&_nav>button]:!opacity-100 [&_nav>button]:!bg-background [&_nav>button]:!border [&_nav>button]:!rounded-md [&_nav>button]:!shadow-sm hover:[&_nav>button]:!bg-muted">
                        <Calendar
                          mode="range"
                          numberOfMonths={2}
                          showOutsideDays={false}
                          defaultMonth={customRange?.from ?? new Date()}
                          selected={
                            customRange
                              ? {
                                  from: customRange.from,
                                  to: customRange.to,
                                }
                              : undefined
                          }
                          onSelect={(
                            range: { from?: Date; to?: Date } | undefined,
                          ) => {
                            if (range?.from && range?.to) {
                              setCustomRange({
                                from: range.from,
                                to: range.to,
                              });
                            }
                          }}
                          disabled={{ after: new Date() }}
                        />
                      </div>
                      <div className="flex items-center justify-between gap-3 border-t bg-muted/30 px-4 py-2.5 text-xs">
                        <span className="tabular-nums text-muted-foreground">
                          {customRange ? (
                            <>
                              <span className="font-medium text-foreground">
                                {formatDate(from, locale)}
                              </span>
                              {" → "}
                              <span className="font-medium text-foreground">
                                {formatDate(to, locale)}
                              </span>
                            </>
                          ) : (
                            <span className="italic">
                              {t("revenue.range_empty")}
                            </span>
                          )}
                        </span>
                        {customRange ? (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 text-xs"
                            onClick={() => setCustomRange(null)}
                          >
                            <XIcon className="size-3.5" />
                            {t("revenue.range_reset")}
                          </Button>
                        ) : null}
                      </div>
                    </div>
                  </div>
                </PopoverContent>
              </Popover>

              {customRange ? (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 gap-1 text-xs"
                  onClick={() => setCustomRange(null)}
                >
                  <XIcon className="size-3.5" />
                  {t("revenue.range_reset")}
                </Button>
              ) : null}
            </div>
          </div>
        </Card>

        {error ? (
          <Card className="border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
            {(error as Error).message || t("common.error_unknown")}
          </Card>
        ) : null}

        {/* Main grid: chart + sidebar */}
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
          <Card className="xl:col-span-8 p-4">
            <RevenueChart
              data={data}
              granularity={granularity}
              isLoading={isLoading}
              moneyFmt={moneyFmt}
              numberFmt={numberFmt}
              locale={locale}
              t={t}
            />
          </Card>

          <div className="xl:col-span-4 space-y-4">
            <Card className="p-4">
              <SidebarHeader title={t("revenue.weekday_avg")} t={t} />
              <WeekdayBars
                data={data}
                isLoading={isLoading}
                moneyFmt={moneyFmt}
                t={t}
              />
            </Card>
            <Card className="p-4">
              <SidebarHeader title={t("revenue.payment_split")} t={t} />
              <PaymentSplit
                data={data}
                isLoading={isLoading}
                moneyFmt={moneyFmt}
                t={t}
              />
            </Card>
          </div>
        </div>

        {/* Detail table */}
        <Card className="p-0 overflow-hidden">
          <DetailTable
            data={data}
            granularity={granularity}
            isLoading={isLoading}
            moneyFmt={moneyFmt}
            numberFmt={numberFmt}
            locale={locale}
            t={t}
          />
        </Card>
          </TabsContent>

          <TabsContent value="product" className="mt-4 space-y-4">
            <ByProductView
              shopSlug={shopSlug}
              moneyFmt={moneyFmt}
              numberFmt={numberFmt}
              locale={locale}
              t={t}
            />
          </TabsContent>

          <TabsContent value="voids" className="mt-4 space-y-4">
            <VoidsView
              shopSlug={shopSlug}
              moneyFmt={moneyFmt}
              numberFmt={numberFmt}
              locale={locale}
              t={t}
            />
          </TabsContent>
        </Tabs>
      </main>
    </div>
  );
}

// ---------------------------------------------------------------------------
//  Subcomponents
// ---------------------------------------------------------------------------

type T = (key: string, params?: Record<string, string | number>) => string;

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100] as const;

/**
 * Pagination footer rendered inside the same Card as a detail table.
 * The total/visible counter sits on the left, page-size + first/prev/
 * next/last controls on the right. Disabled state on the buttons drops
 * out as soon as we hit the boundary so the user never has to read the
 * disabled-but-still-clickable affordance some libraries ship.
 *
 * Pagination state is owned by the caller so it can reset to page=1
 * when the underlying dataset changes (granularity flip, new date
 * range, new sort) — see DetailTable + ByProductTable below.
 */
function PaginationFooter({
  page,
  pageSize,
  total,
  onPageChange,
  onPageSizeChange,
  t,
}: {
  page: number;
  pageSize: number;
  total: number;
  onPageChange: (page: number) => void;
  onPageSizeChange?: (size: number) => void;
  t: T;
}) {
  const lastPage = Math.max(1, Math.ceil(total / pageSize));
  const from = total === 0 ? 0 : (page - 1) * pageSize + 1;
  const to = Math.min(page * pageSize, total);
  const atFirst = page <= 1;
  const atLast = page >= lastPage;

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 px-4 py-2.5 text-xs">
      <div className="tabular-nums text-muted-foreground">
        {t("revenue.pagination.showing", { from, to, total })}
      </div>
      <div className="flex items-center gap-1.5">
        {onPageSizeChange ? (
          <div className="mr-1 flex items-center gap-1.5">
            <span className="text-muted-foreground">
              {t("revenue.pagination.page_size")}
            </span>
            <Select
              value={String(pageSize)}
              onValueChange={(v) => onPageSizeChange(Number(v))}
            >
              <SelectTrigger className="h-7 w-[72px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PAGE_SIZE_OPTIONS.map((n) => (
                  <SelectItem key={n} value={String(n)}>
                    {n}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        ) : null}
        <Button
          variant="ghost"
          size="sm"
          className="size-7 p-0"
          disabled={atFirst}
          onClick={() => onPageChange(1)}
          aria-label={t("revenue.pagination.first")}
          title={t("revenue.pagination.first")}
        >
          <ChevronsLeftIcon className="size-3.5" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="size-7 p-0"
          disabled={atFirst}
          onClick={() => onPageChange(page - 1)}
          aria-label={t("revenue.pagination.prev")}
          title={t("revenue.pagination.prev")}
        >
          <ChevronLeftIcon className="size-3.5" />
        </Button>
        <span className="min-w-[64px] px-1 text-center tabular-nums">
          {t("revenue.pagination.page_of", { page, total: lastPage })}
        </span>
        <Button
          variant="ghost"
          size="sm"
          className="size-7 p-0"
          disabled={atLast}
          onClick={() => onPageChange(page + 1)}
          aria-label={t("revenue.pagination.next")}
          title={t("revenue.pagination.next")}
        >
          <ChevronRightIcon className="size-3.5" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="size-7 p-0"
          disabled={atLast}
          onClick={() => onPageChange(lastPage)}
          aria-label={t("revenue.pagination.last")}
          title={t("revenue.pagination.last")}
        >
          <ChevronsRightIcon className="size-3.5" />
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
//  ByProductView — 商品別売上 tab
// ---------------------------------------------------------------------------

/**
 * Ranks products / SKU variants by closed-order revenue across a date
 * range. Mirrors the Japanese 商品別売上 layout from the mobile mockup:
 * category filter + range + level toggle, then a table sorted by total
 * revenue with a share% column.
 *
 * State is local to this view (its own range + filter); the parent
 * tab's filter doesn't leak in because operators typically want to ask
 * different time-window questions when looking at product rankings.
 */
function ByProductView({
  shopSlug,
  moneyFmt,
  numberFmt,
  locale,
  t,
}: {
  shopSlug: string;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
}) {
  const now = useMemo(() => new Date(), []);
  const [range, setRange] = useState<{ from: Date; to: Date }>(() => {
    // Default to current month, like the mobile mockup ("2026/05/01 ~ 2026/05/31").
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    return { from: startOfDay(first), to: endOfDay(now) };
  });
  const [categoryId, setCategoryId] = useState<string | "all">("all");
  const [level, setLevel] = useState<"product" | "sku">("product");
  const [sort, setSort] = useState<"revenue" | "quantity">("revenue");

  // Server-side pagination state — passed as query params on every
  // fetch. Backend re-runs the COUNT + SLICE per page; we don't ship
  // the full dataset to the client. Page resets to 1 whenever filters
  // change (see effect below).
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);
  useEffect(() => {
    setPage(1);
  }, [level, sort, categoryId, range.from, range.to]);

  const from = formatYmd(range.from);
  const to = formatYmd(range.to);

  const { data, isLoading, error } = useRevenueByProduct(shopSlug, {
    from,
    to,
    level,
    sort,
    category_id: categoryId === "all" ? undefined : categoryId,
    page,
    per_page: pageSize,
  });

  const categories = data?.available_categories ?? [];

  return (
    <div className="space-y-4">
      {/* Filter bar */}
      <Card className="px-4 py-3">
        <div className="flex flex-wrap items-center gap-4">
          {/* Category select */}
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">
              {t("revenue.product.category")}
            </span>
            <Select value={categoryId} onValueChange={setCategoryId}>
              <SelectTrigger className="h-9 w-[200px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">
                  {t("revenue.product.all_categories")}
                </SelectItem>
                {categories.map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Date range picker — reuses the same Popover pattern as the
              time-view tab so operators get a consistent UX. */}
          <Popover>
            <PopoverTrigger asChild>
              <button
                type="button"
                className="inline-flex items-center gap-2 rounded-md border bg-background px-3 py-1.5 text-sm tabular-nums hover:bg-muted"
              >
                <CalendarIcon className="size-4 text-muted-foreground" />
                <span className="font-medium">{formatDate(from, locale)}</span>
                <span className="text-muted-foreground">→</span>
                <span className="font-medium">{formatDate(to, locale)}</span>
              </button>
            </PopoverTrigger>
            <PopoverContent
              align="start"
              className="w-auto overflow-hidden p-0"
            >
              <div className="flex">
                <div className="flex w-40 flex-col gap-1 border-r bg-muted/30 p-3">
                  <div className="mb-1 px-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {t("revenue.range_presets")}
                  </div>
                  {PRESETS.map((preset) => (
                    <button
                      key={preset.key}
                      type="button"
                      onClick={() => setRange(preset.compute())}
                      className="rounded-md px-2 py-1.5 text-left text-xs text-foreground transition-colors hover:bg-background"
                    >
                      {t(preset.labelKey)}
                    </button>
                  ))}
                </div>
                <div className="flex flex-col">
                  <div className="relative w-fit m-3 [&_nav]:z-20 [&_nav>button]:!size-8 [&_nav>button]:!opacity-100 [&_nav>button]:!bg-background [&_nav>button]:!border [&_nav>button]:!rounded-md [&_nav>button]:!shadow-sm hover:[&_nav>button]:!bg-muted">
                    <Calendar
                      mode="range"
                      numberOfMonths={2}
                      showOutsideDays={false}
                      defaultMonth={range.from}
                      selected={{ from: range.from, to: range.to }}
                      onSelect={(
                        r: { from?: Date; to?: Date } | undefined,
                      ) => {
                        if (r?.from && r?.to) {
                          setRange({
                            from: startOfDay(r.from),
                            to: endOfDay(r.to),
                          });
                        }
                      }}
                      disabled={{ after: new Date() }}
                    />
                  </div>
                </div>
              </div>
            </PopoverContent>
          </Popover>

          {/* Level toggle: 商品単位 / バリエーション単位 */}
          <Tabs
            value={level}
            onValueChange={(v) => setLevel(v as "product" | "sku")}
          >
            <TabsList>
              <TabsTrigger value="product">
                {t("revenue.product.level_product")}
              </TabsTrigger>
              <TabsTrigger value="sku">
                {t("revenue.product.level_sku")}
              </TabsTrigger>
            </TabsList>
          </Tabs>

          {/* Sort */}
          <div className="ml-auto flex items-center gap-2">
            <span className="text-sm text-muted-foreground">
              {t("revenue.product.sort")}
            </span>
            <Select
              value={sort}
              onValueChange={(v) => setSort(v as "revenue" | "quantity")}
            >
              <SelectTrigger className="h-9 w-[180px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="revenue">
                  {t("revenue.product.sort_revenue")}
                </SelectItem>
                <SelectItem value="quantity">
                  {t("revenue.product.sort_quantity")}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
      </Card>

      {error ? (
        <Card className="border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
          {(error as Error).message || t("common.error_unknown")}
        </Card>
      ) : null}

      {/* Total + meta strip */}
      {data ? (
        <div className="flex flex-wrap items-center gap-6 px-1 text-sm">
          <div>
            <div className="text-xs text-muted-foreground">
              {t("revenue.product.total_revenue")}
            </div>
            <div className="text-lg font-semibold tabular-nums">
              {moneyFmt.format(data.total_revenue)}
            </div>
          </div>
          <div>
            <div className="text-xs text-muted-foreground">
              {t("revenue.product.total_quantity")}
            </div>
            <div className="text-lg font-semibold tabular-nums">
              {numberFmt.format(data.total_quantity)}
            </div>
          </div>
          <div>
            <div className="text-xs text-muted-foreground">
              {t("revenue.product.product_count")}
            </div>
            <div className="text-lg font-semibold tabular-nums">
              {numberFmt.format(data.rows.length)}
            </div>
          </div>
          <div className="ml-auto text-xs text-muted-foreground">
            {t("revenue.updated_at")}
            {": "}
            {formatDateTime(new Date(data.generated_at), locale)}
          </div>
        </div>
      ) : null}

      {/* Product ranking table */}
      <Card className="overflow-hidden p-0">
        <ByProductTable
          data={data}
          isLoading={isLoading}
          moneyFmt={moneyFmt}
          numberFmt={numberFmt}
          onPageChange={setPage}
          onPageSizeChange={(size) => {
            setPageSize(size);
            setPage(1);
          }}
          t={t}
        />
      </Card>
    </div>
  );
}

function ByProductTable({
  data,
  isLoading,
  moneyFmt,
  numberFmt,
  onPageChange,
  onPageSizeChange,
  t,
}: {
  data?: import("@/services/revenue-service").RevenueByProduct;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  onPageChange: (page: number) => void;
  onPageSizeChange: (size: number) => void;
  t: T;
}) {
  if (isLoading) {
    return (
      <div className="p-10 text-center">
        <Spinner className="mx-auto size-5" />
      </div>
    );
  }
  if (!data || data.rows.length === 0) {
    return (
      <div className="p-10">
        <EmptyState t={t} />
      </div>
    );
  }

  // Server-side pagination: `rows` IS already the current page. Meta
  // gives us the absolute total + current/last page, which drives the
  // footer's `Showing 1–25 of 234` counter. `pageStart` is computed
  // from meta so the # column shows the row's absolute rank, not its
  // index within the page.
  const total = data.meta.total;
  const pageStart = (data.meta.current_page - 1) * data.meta.per_page;
  const visibleRows = data.rows;

  return (
    <>
      <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="border-b bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            <th className="px-4 py-3 text-left font-medium w-10">#</th>
            <th className="px-5 py-3 text-left font-medium">
              {t("revenue.product.col_name")}
            </th>
            <th className="px-5 py-3 text-left font-medium">
              {t("revenue.product.col_category")}
            </th>
            <th className="px-5 py-3 text-right font-medium">
              {t("revenue.product.col_quantity")}
            </th>
            {/* Merged column — revenue on top, share % stacked below
                separated by a dashed divider. Matches the Japanese
                mockup's 販売総売上 column where each cell carries both
                the absolute revenue value and its 構成比 share. */}
            <th className="px-5 py-3 text-right font-medium w-[180px]">
              <span>{t("revenue.product.col_revenue")}</span>
              <span className="ml-1 text-[10px] text-muted-foreground/70">
                [a]
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          {visibleRows.map((row, idx) => {
            const absoluteIdx = pageStart + idx;
            return (
              <tr
                key={row.id + "-" + absoluteIdx}
                className={cn(
                  "border-b transition-colors hover:bg-muted/30",
                  absoluteIdx % 2 === 1 && "bg-muted/10",
                )}
              >
                <td className="px-4 py-3 text-right text-xs tabular-nums text-muted-foreground">
                  {absoluteIdx + 1}
                </td>
                <td className="px-5 py-3">
                  <div className="font-medium">{row.name}</div>
                  {row.sku ? (
                    <div className="text-[11px] text-muted-foreground tabular-nums">
                      {row.sku}
                    </div>
                  ) : null}
                </td>
                <td className="px-5 py-3 text-xs text-muted-foreground">
                  {row.category_name ?? "—"}
                </td>
                <td className="px-5 py-3 text-right tabular-nums">
                  {numberFmt.format(row.quantity)}
                </td>
                <td className="px-5 py-3 text-right align-middle">
                  <div className="font-medium tabular-nums">
                    {moneyFmt.format(row.revenue)}
                  </div>
                  <div className="mt-1.5 border-t border-dashed border-muted-foreground/30 pt-1.5 text-xs tabular-nums text-muted-foreground">
                    {t("revenue.product.col_share")}{" "}
                    <span className="font-medium text-foreground">
                      {row.share_pct.toFixed(1)}%
                    </span>
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
        <tfoot className="border-t-2 bg-muted/40 text-sm font-semibold">
          <tr>
            <td
              colSpan={3}
              className="px-5 py-3 text-xs uppercase tracking-wide text-muted-foreground"
            >
              {t("revenue.table.total")}
            </td>
            <td className="px-5 py-3 text-right tabular-nums">
              {numberFmt.format(data.total_quantity)}
            </td>
            <td className="px-5 py-3 text-right align-middle">
              <div className="font-semibold tabular-nums">
                {moneyFmt.format(data.total_revenue)}
              </div>
              <div className="mt-1.5 border-t border-dashed border-muted-foreground/30 pt-1.5 text-xs tabular-nums text-muted-foreground">
                {t("revenue.product.col_share")}{" "}
                <span className="font-semibold text-foreground">100.0%</span>
              </div>
            </td>
          </tr>
        </tfoot>
      </table>
      </div>
      <PaginationFooter
        page={data.meta.current_page}
        pageSize={data.meta.per_page}
        total={total}
        onPageChange={onPageChange}
        onPageSizeChange={onPageSizeChange}
        t={t}
      />
    </>
  );
}

// ---------------------------------------------------------------------------
//  Voids view — huỷ món (item voids) + huỷ order (order voids) analytics
// ---------------------------------------------------------------------------

// Chart palette for the two void lenses — akane (茜) muted-red family,
// chroma ≤ 0.18 per the shibumi design system. Order voids read darker
// (a whole order lost); item voids lighter (a partial loss). The trend
// overlay is a neutral slate (same as RevenueChart) so it reads as "the
// same data, drawn as a smooth line" rather than competing with the bars.
const VOID_ORDER_COLOR = "var(--color-destructive, #d64545)";
const VOID_ITEM_COLOR = "#e2917c";
const VOID_TREND_COLOR = "#94a3b8";

/**
 * VoidsView — the "Huỷ" tab of the revenue report. Self-contained (owns
 * its own granularity + period window, like ByProductView) so switching
 * tabs never disturbs the time/product views' filters. Renders:
 *   - a KPI strip (order voids, item voids, total lost value, order-void rate)
 *   - a time-series chart: grouped bars (order vs item void counts) + a
 *     value line on a secondary axis
 *   - two reason breakdowns (order-level / item-level) in the sidebar
 *   - a "most-voided items" ranking table
 *
 * Data comes from GET /pos/revenue/voids — identical shape from the
 * workstation LAN handler and Cloud, so this view is source-agnostic.
 */
function VoidsView({
  shopSlug,
  moneyFmt,
  numberFmt,
  locale,
  t,
}: {
  shopSlug: string;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
}) {
  const now = useMemo(() => new Date(), []);
  const [granularity, setGranularity] = useState<RevenueGranularity>("day");
  const [year, setYear] = useState<number>(now.getFullYear());
  const [month, setMonth] = useState<number>(now.getMonth() + 1);
  const [customRange, setCustomRange] = useState<
    { from: Date; to: Date } | null
  >(null);

  const { from, to } = useMemo(() => {
    if (customRange) {
      return {
        from: formatYmd(customRange.from),
        to: formatYmd(customRange.to),
      };
    }
    return computeRange(granularity, year, month);
  }, [customRange, granularity, year, month]);

  const shiftPeriod = (delta: number) => {
    if (granularity === "year" || granularity === "month") {
      setYear((y) => y + delta);
      return;
    }
    let m = month + delta;
    let y = year;
    while (m < 1) {
      m += 12;
      y -= 1;
    }
    while (m > 12) {
      m -= 12;
      y += 1;
    }
    setMonth(m);
    setYear(y);
  };

  const { data, isLoading, error } = useRevenueVoids(shopSlug, {
    granularity,
    from,
    to,
  });

  return (
    <div className="space-y-4">
      <VoidKpiStrip
        data={data}
        isLoading={isLoading}
        moneyFmt={moneyFmt}
        numberFmt={numberFmt}
        t={t}
      />

      {/* Filter bar — granularity + period stepper (mirrors the time view). */}
      <Card className="px-4 py-3">
        <div className="flex flex-wrap items-center gap-4">
          <Tabs
            value={granularity}
            onValueChange={(v) => setGranularity(v as RevenueGranularity)}
          >
            <TabsList>
              <TabsTrigger value="day">{t("revenue.tab.daily")}</TabsTrigger>
              <TabsTrigger value="month">{t("revenue.tab.monthly")}</TabsTrigger>
              <TabsTrigger value="year">{t("revenue.tab.yearly")}</TabsTrigger>
            </TabsList>
          </Tabs>

          {/* Period stepper — disabled while a custom range is active so the
              two controls don't fight each other (mirrors the time view). */}
          <div
            className={cn(
              "inline-flex items-center rounded-md border bg-background shadow-sm transition-opacity",
              customRange && "opacity-50",
            )}
          >
            <Button
              variant="ghost"
              size="sm"
              className="size-9 rounded-r-none px-0 hover:bg-muted"
              onClick={() => shiftPeriod(-1)}
              disabled={!!customRange}
              aria-label={t("revenue.prev")}
            >
              <ChevronLeftIcon className="size-4" />
            </Button>
            <div className="flex items-center gap-1 border-x px-2">
              <Select
                value={String(year)}
                onValueChange={(v) => setYear(Number(v))}
                disabled={!!customRange}
              >
                <SelectTrigger className="h-8 w-[88px] border-0 shadow-none focus:ring-0 focus:ring-offset-0">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {yearOptions(now.getFullYear()).map((y) => (
                    <SelectItem key={y} value={String(y)}>
                      {y}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {granularity === "day" ? (
                <>
                  <span className="text-muted-foreground">/</span>
                  <Select
                    value={String(month)}
                    onValueChange={(v) => setMonth(Number(v))}
                    disabled={!!customRange}
                  >
                    <SelectTrigger className="h-8 w-[120px] border-0 shadow-none focus:ring-0 focus:ring-offset-0">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                        <SelectItem key={m} value={String(m)}>
                          {t(`revenue.month_${m}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </>
              ) : null}
            </div>
            <Button
              variant="ghost"
              size="sm"
              className="size-9 rounded-l-none px-0 hover:bg-muted"
              onClick={() => shiftPeriod(1)}
              disabled={!!customRange}
              aria-label={t("revenue.next")}
            >
              <ChevronRightIcon className="size-4" />
            </Button>
          </div>

          {/* Range chip — click to pick a custom date range (presets +
              calendar), or a preset year/month via the stepper above. */}
          <div className="ml-auto flex items-center gap-2">
            <Popover>
              <PopoverTrigger asChild>
                <button
                  type="button"
                  className={cn(
                    "inline-flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-1.5 text-xs tabular-nums text-muted-foreground transition-colors hover:bg-muted",
                    customRange &&
                      "border-primary bg-primary/5 text-foreground",
                  )}
                  aria-label={t("revenue.range_pick")}
                >
                  <CalendarIcon className="size-3.5" />
                  <span className="font-medium text-foreground">
                    {formatDate(from, locale)}
                  </span>
                  <span>→</span>
                  <span className="font-medium text-foreground">
                    {formatDate(to, locale)}
                  </span>
                </button>
              </PopoverTrigger>
              <PopoverContent align="end" className="w-auto overflow-hidden p-0">
                <div className="flex">
                  {/* Presets sidebar */}
                  <div className="flex w-40 flex-col gap-1 border-r bg-muted/30 p-3">
                    <div className="mb-1 px-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                      {t("revenue.range_presets")}
                    </div>
                    {PRESETS.map((preset) => {
                      const range = preset.compute();
                      const isActive =
                        customRange &&
                        formatYmd(customRange.from) === formatYmd(range.from) &&
                        formatYmd(customRange.to) === formatYmd(range.to);
                      return (
                        <button
                          key={preset.key}
                          type="button"
                          onClick={() => setCustomRange(range)}
                          className={cn(
                            "rounded-md px-2 py-1.5 text-left text-xs transition-colors hover:bg-background",
                            isActive
                              ? "bg-primary/10 font-medium text-primary"
                              : "text-foreground",
                          )}
                        >
                          {t(preset.labelKey)}
                        </button>
                      );
                    })}
                  </div>

                  {/* Calendar + footer */}
                  <div className="flex flex-col">
                    <div className="relative w-fit m-3 [&_nav]:z-20 [&_nav>button]:!size-8 [&_nav>button]:!opacity-100 [&_nav>button]:!bg-background [&_nav>button]:!border [&_nav>button]:!rounded-md [&_nav>button]:!shadow-sm hover:[&_nav>button]:!bg-muted">
                      <Calendar
                        mode="range"
                        numberOfMonths={2}
                        showOutsideDays={false}
                        defaultMonth={customRange?.from ?? new Date()}
                        selected={
                          customRange
                            ? { from: customRange.from, to: customRange.to }
                            : undefined
                        }
                        onSelect={(
                          range: { from?: Date; to?: Date } | undefined,
                        ) => {
                          if (range?.from && range?.to) {
                            setCustomRange({ from: range.from, to: range.to });
                          }
                        }}
                        disabled={{ after: new Date() }}
                      />
                    </div>
                    <div className="flex items-center justify-between gap-3 border-t bg-muted/30 px-4 py-2.5 text-xs">
                      <span className="tabular-nums text-muted-foreground">
                        {customRange ? (
                          <>
                            <span className="font-medium text-foreground">
                              {formatDate(from, locale)}
                            </span>
                            {" → "}
                            <span className="font-medium text-foreground">
                              {formatDate(to, locale)}
                            </span>
                          </>
                        ) : (
                          <span className="italic">
                            {t("revenue.range_empty")}
                          </span>
                        )}
                      </span>
                      {customRange ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 gap-1 text-xs"
                          onClick={() => setCustomRange(null)}
                        >
                          <XIcon className="size-3.5" />
                          {t("revenue.range_reset")}
                        </Button>
                      ) : null}
                    </div>
                  </div>
                </div>
              </PopoverContent>
            </Popover>

            {customRange ? (
              <Button
                variant="ghost"
                size="sm"
                className="h-8 gap-1 text-xs"
                onClick={() => setCustomRange(null)}
              >
                <XIcon className="size-3.5" />
                {t("revenue.range_reset")}
              </Button>
            ) : null}
          </div>
        </div>
      </Card>

      {error ? (
        <Card className="border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
          {(error as Error).message || t("common.error_unknown")}
        </Card>
      ) : null}

      {/* Main grid: time-series chart + reason breakdowns. */}
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
        <Card className="xl:col-span-8 p-4">
          <VoidChart
            data={data}
            granularity={granularity}
            isLoading={isLoading}
            moneyFmt={moneyFmt}
            numberFmt={numberFmt}
            locale={locale}
            t={t}
          />
        </Card>

        <div className="space-y-4 xl:col-span-4">
          <Card className="p-4">
            <SidebarHeader title={t("revenue.voids.order_reasons")} t={t} />
            <VoidReasonBars
              reasons={data?.order_reasons}
              isLoading={isLoading}
              tone="order"
              moneyFmt={moneyFmt}
              numberFmt={numberFmt}
              t={t}
            />
          </Card>
          <Card className="p-4">
            <SidebarHeader title={t("revenue.voids.item_reasons")} t={t} />
            <VoidReasonBars
              reasons={data?.item_reasons}
              isLoading={isLoading}
              tone="item"
              moneyFmt={moneyFmt}
              numberFmt={numberFmt}
              t={t}
            />
          </Card>
        </div>
      </div>

      {/* Most-voided items ranking. */}
      <Card className="overflow-hidden p-0">
        <VoidTopItems
          items={data?.top_items}
          isLoading={isLoading}
          moneyFmt={moneyFmt}
          numberFmt={numberFmt}
          t={t}
        />
      </Card>

      {/* Void log — which order, when, why, how much (drill-down). */}
      <VoidLog
        shopSlug={shopSlug}
        granularity={granularity}
        from={from}
        to={to}
        moneyFmt={moneyFmt}
        numberFmt={numberFmt}
        locale={locale}
        t={t}
      />

      {data ? (
        <div className="px-1 text-right text-xs text-muted-foreground">
          {t("revenue.updated_at")}
          {": "}
          {formatDateTime(new Date(data.generated_at), locale)}
        </div>
      ) : null}
    </div>
  );
}

function VoidKpiStrip({
  data,
  isLoading,
  moneyFmt,
  numberFmt,
  t,
}: {
  data?: RevenueVoids;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  t: T;
}) {
  const k = data?.kpis;
  const totalLost = k ? k.order_void_value + k.item_void_value : 0;
  const cards: Array<{
    label: string;
    value: string;
    sub?: string;
    accent: "order" | "item" | "neutral";
  }> = [
    {
      label: t("revenue.voids.kpi.order_voids"),
      value: k ? numberFmt.format(k.order_voids) : "—",
      sub: k
        ? t("revenue.voids.kpi.value_lost", {
            value: moneyFmt.format(k.order_void_value),
          })
        : undefined,
      accent: "order",
    },
    {
      label: t("revenue.voids.kpi.item_voids"),
      value: k ? numberFmt.format(k.item_voids) : "—",
      sub: k
        ? t("revenue.voids.kpi.value_lost", {
            value: moneyFmt.format(k.item_void_value),
          })
        : undefined,
      accent: "item",
    },
    {
      label: t("revenue.voids.kpi.total_lost"),
      value: k ? moneyFmt.format(totalLost) : "—",
      accent: "order",
    },
    {
      label: t("revenue.voids.kpi.rate"),
      value: k ? `${k.order_void_rate_pct}%` : "—",
      sub: t("revenue.voids.kpi.rate_hint"),
      accent: "neutral",
    },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {cards.map((card, idx) => (
        <Card key={idx} className="relative overflow-hidden p-4 pl-5">
          <span
            aria-hidden
            className="absolute inset-y-0 left-0 w-1"
            style={{
              background:
                card.accent === "item"
                  ? VOID_ITEM_COLOR
                  : card.accent === "neutral"
                    ? "var(--color-border, #d6d3d1)"
                    : VOID_ORDER_COLOR,
            }}
          />
          <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {card.label}
          </div>
          <div className="mt-2 text-2xl font-semibold tabular-nums">
            {isLoading ? <Spinner className="size-5" /> : card.value}
          </div>
          {card.sub && !isLoading ? (
            <div className="mt-1 text-xs tabular-nums text-muted-foreground">
              {card.sub}
            </div>
          ) : null}
        </Card>
      ))}
    </div>
  );
}

/**
 * useChartZoom — wheel-zoom + drag-pan + keyboard nav for a time-series
 * ComposedChart. Returns the visible `[winStart, winEnd]` index window into a
 * `fullLen`-long row array, the pointer/ref wiring to attach to the chart's
 * scroll container, and the imperative zoom/pan actions the control buttons
 * call. The consumer slices its own data by the window
 * (`rows.slice(winStart, winEnd + 1)`).
 *
 * All the anti-loop defences (functional-updater bail-out + lastAppliedRef
 * echo guard + rAF-batched pan) live here so every chart that opts in gets the
 * same well-behaved zoom without re-deriving the tricky bits. Mirrors the
 * behaviour originally inlined in RevenueChart.
 */
function useChartZoom(fullLen: number) {
  // `null` means "show all". When zoomed, an inclusive [start, end] index
  // range drives the wheel-zoom + drag-pan handlers. Reset whenever the
  // dataset length changes (new period, granularity flip, refetch).
  const [zoomWindow, setZoomWindow] = useState<
    { start: number; end: number } | null
  >(null);
  useEffect(() => {
    setZoomWindow(null);
  }, [fullLen]);

  const lastIdx = Math.max(0, fullLen - 1);
  const winStart = zoomWindow?.start ?? 0;
  const winEnd = zoomWindow?.end ?? lastIdx;
  const isZoomed = zoomWindow !== null && (winStart > 0 || winEnd < lastIdx);

  const dragRef = useRef<{
    startX: number;
    initStart: number;
    initEnd: number;
    width: number;
  } | null>(null);
  const [isDragging, setIsDragging] = useState(false);

  const clampWindow = (start: number, end: number) => {
    const s = Math.max(0, Math.min(start, lastIdx - 1));
    const e = Math.max(s + 1, Math.min(end, lastIdx));
    return { start: s, end: e };
  };

  // Two layers of defence against the recharts re-render loop: (1) the
  // functional updater returns the SAME reference when nothing changed so
  // React bails out; (2) lastAppliedRef records the last committed window so
  // an echoed update with the same indices is skipped.
  const lastAppliedRef = useRef<{ start: number; end: number } | null>(null);
  const safeSetZoom = useCallback(
    (next: { start: number; end: number } | null) => {
      lastAppliedRef.current = next ? { ...next } : null;
      setZoomWindow((prev) => {
        if (prev === null && next === null) return prev;
        if (
          prev &&
          next &&
          prev.start === next.start &&
          prev.end === next.end
        ) {
          return prev;
        }
        return next;
      });
    },
    [],
  );

  // Anchored wheel zoom — keeps the point under the cursor stable. Native
  // listener (not React onWheel) with `{ passive: false }` so preventDefault
  // actually cancels trackpad pinch-zoom of the whole page.
  const chartAreaRef = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    const el = chartAreaRef.current;
    if (!el) return;
    const onWheel = (e: WheelEvent) => {
      if (fullLen < 2) return;
      e.preventDefault();
      e.stopPropagation();
      const direction = e.deltaY > 0 ? 1 : -1; // +1 zoom out, -1 zoom in
      const span = winEnd - winStart;
      const factor = e.ctrlKey ? 0.03 : 0.04;
      const step = Math.max(1, Math.ceil(span * factor));
      const rect = el.getBoundingClientRect();
      const ratio = Math.min(
        1,
        Math.max(0, (e.clientX - rect.left) / rect.width),
      );
      let newStart: number;
      let newEnd: number;
      if (direction < 0) {
        newStart = winStart + Math.round(step * ratio);
        newEnd = winEnd - Math.round(step * (1 - ratio));
        if (newEnd - newStart < 1) {
          const mid = Math.floor((winStart + winEnd) / 2);
          newStart = Math.max(0, mid);
          newEnd = Math.min(lastIdx, mid + 1);
        }
      } else {
        newStart = winStart - Math.round(step * ratio);
        newEnd = winEnd + Math.round(step * (1 - ratio));
      }
      const clamped = clampWindow(newStart, newEnd);
      if (clamped.start <= 0 && clamped.end >= lastIdx) {
        safeSetZoom(null);
      } else {
        safeSetZoom(clamped);
      }
    };
    el.addEventListener("wheel", onWheel, { passive: false });
    return () => el.removeEventListener("wheel", onWheel);
  }, [fullLen, winStart, winEnd, lastIdx]);

  // Drag-to-pan — only while zoomed in. Pointer capture keeps a mouseup
  // outside the chart ending the drag cleanly.
  const handlePointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (!isZoomed) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    dragRef.current = {
      startX: e.clientX,
      initStart: winStart,
      initEnd: winEnd,
      width: e.currentTarget.getBoundingClientRect().width,
    };
    setIsDragging(true);
  };
  // Throttle pointer moves to one update per animation frame so the chart
  // doesn't thrash on high-frequency pointer streams.
  const panRafRef = useRef<number | null>(null);
  const pendingPanRef = useRef<{ start: number; end: number } | null>(null);
  const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = dragRef.current;
    if (!d) return;
    const span = d.initEnd - d.initStart + 1;
    // Subtract the Y-axis gutter (~56px) + right margin (~16px) so a
    // pixel-perfect drag maps to 1 index.
    const plotWidth = Math.max(50, d.width - 56 - 16);
    const pxPerIdx = plotWidth / span;
    if (pxPerIdx <= 0) return;
    const deltaIdx = Math.round(-((e.clientX - d.startX) * 1.4) / pxPerIdx);
    if (deltaIdx === 0) return;
    let s = d.initStart + deltaIdx;
    let en = d.initEnd + deltaIdx;
    if (s < 0) {
      en -= s;
      s = 0;
    }
    if (en > lastIdx) {
      s -= en - lastIdx;
      en = lastIdx;
    }
    pendingPanRef.current = { start: s, end: en };
    if (panRafRef.current === null) {
      panRafRef.current = requestAnimationFrame(() => {
        panRafRef.current = null;
        const next = pendingPanRef.current;
        pendingPanRef.current = null;
        if (next) safeSetZoom(next);
      });
    }
  };
  const handlePointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
    if (dragRef.current && e.currentTarget.hasPointerCapture(e.pointerId)) {
      e.currentTarget.releasePointerCapture(e.pointerId);
    }
    dragRef.current = null;
    setIsDragging(false);
    if (panRafRef.current !== null) {
      cancelAnimationFrame(panRafRef.current);
      panRafRef.current = null;
    }
    if (pendingPanRef.current) {
      const next = pendingPanRef.current;
      pendingPanRef.current = null;
      safeSetZoom(next);
    }
  };
  useEffect(
    () => () => {
      if (panRafRef.current !== null) cancelAnimationFrame(panRafRef.current);
    },
    [],
  );

  // Shift the visible window by half its span — the ‹ / › buttons + arrow
  // keys use this for reliable "browse forward / backward".
  const shiftWindow = (direction: -1 | 1) => {
    if (!isZoomed) return;
    const span = winEnd - winStart + 1;
    const step = Math.max(1, Math.round(span * 0.5));
    let s = winStart + direction * step;
    let en = winEnd + direction * step;
    if (s < 0) {
      en -= s;
      s = 0;
    }
    if (en > lastIdx) {
      s -= en - lastIdx;
      en = lastIdx;
    }
    safeSetZoom({ start: s, end: en });
  };

  // Keyboard nav — Left/Right shift the window, Esc resets. Only attaches
  // while zoomed so it doesn't fight other page shortcuts, and ignores keys
  // typed into form fields.
  useEffect(() => {
    if (!isZoomed) return;
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement | null;
      if (target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) return;
      if (e.key === "ArrowLeft") {
        e.preventDefault();
        shiftWindow(-1);
      } else if (e.key === "ArrowRight") {
        e.preventDefault();
        shiftWindow(1);
      } else if (e.key === "Escape") {
        safeSetZoom(null);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [isZoomed, winStart, winEnd, lastIdx]);

  const zoomBy = (direction: 1 | -1) => {
    if (fullLen < 2) return;
    const span = winEnd - winStart;
    const step = Math.max(1, Math.round(span * 0.25));
    if (direction < 0) {
      const newStart = winStart + Math.round(step / 2);
      const newEnd = winEnd - Math.round(step / 2);
      if (newEnd - newStart < 1) return;
      safeSetZoom(clampWindow(newStart, newEnd));
    } else {
      const newStart = winStart - Math.round(step / 2);
      const newEnd = winEnd + Math.round(step / 2);
      const c = clampWindow(newStart, newEnd);
      if (c.start <= 0 && c.end >= lastIdx) {
        safeSetZoom(null);
      } else {
        safeSetZoom(c);
      }
    }
  };

  return {
    winStart,
    winEnd,
    lastIdx,
    fullLen,
    isZoomed,
    isDragging,
    chartAreaRef,
    handlePointerDown,
    handlePointerMove,
    handlePointerUp,
    shiftWindow,
    zoomBy,
    resetZoom: () => safeSetZoom(null),
  };
}

/**
 * ChartZoomControls — the ‹ / › pan pair + [+] / [−] / [⤢] zoom pair used in a
 * chart header. Pure presentational wrapper around a `useChartZoom` return so
 * the two button clusters stay identical across charts.
 */
function ChartZoomControls({
  isZoomed,
  winStart,
  winEnd,
  lastIdx,
  fullLen,
  shiftWindow,
  zoomBy,
  resetZoom,
  t,
}: {
  isZoomed: boolean;
  winStart: number;
  winEnd: number;
  lastIdx: number;
  fullLen: number;
  shiftWindow: (direction: -1 | 1) => void;
  zoomBy: (direction: 1 | -1) => void;
  resetZoom: () => void;
  t: T;
}) {
  return (
    <>
      {/* Pan controls — primary way to navigate when zoomed in. */}
      <div className="inline-flex items-center rounded-md border bg-background">
        <Button
          variant="ghost"
          size="sm"
          className="size-8 rounded-r-none px-0"
          onClick={() => shiftWindow(-1)}
          disabled={!isZoomed || winStart <= 0}
          aria-label={t("revenue.chart.pan_left")}
          title={t("revenue.chart.pan_left")}
        >
          <ChevronLeftIcon className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="size-8 rounded-l-none border-l px-0"
          onClick={() => shiftWindow(1)}
          disabled={!isZoomed || winEnd >= lastIdx}
          aria-label={t("revenue.chart.pan_right")}
          title={t("revenue.chart.pan_right")}
        >
          <ChevronRightIcon className="size-4" />
        </Button>
      </div>

      {/* Zoom controls. */}
      <div className="inline-flex items-center rounded-md border bg-background">
        <Button
          variant="ghost"
          size="sm"
          className="size-8 rounded-r-none px-0"
          onClick={() => zoomBy(-1)}
          disabled={fullLen < 2 || winEnd - winStart <= 1}
          aria-label={t("revenue.chart.zoom_in")}
          title={t("revenue.chart.zoom_in")}
        >
          <PlusIcon className="size-3.5" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="size-8 rounded-none border-x px-0"
          onClick={() => zoomBy(1)}
          disabled={!isZoomed}
          aria-label={t("revenue.chart.zoom_out")}
          title={t("revenue.chart.zoom_out")}
        >
          <MinusIcon className="size-3.5" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="size-8 rounded-l-none px-0"
          onClick={resetZoom}
          disabled={!isZoomed}
          aria-label={t("revenue.chart.zoom_reset")}
          title={t("revenue.chart.zoom_reset")}
        >
          <MaximizeIcon className="size-3.5" />
        </Button>
      </div>
    </>
  );
}

function VoidChart({
  data,
  granularity,
  isLoading,
  moneyFmt,
  numberFmt,
  locale,
  t,
}: {
  data?: RevenueVoids;
  granularity: RevenueGranularity;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
}) {
  const [showTrend, setShowTrend] = useState(true);
  const rows = useMemo(
    () =>
      data ? data.series.map((p) => decorateVoidPoint(p, granularity, t)) : [],
    [data, granularity, t],
  );
  const hasAny = rows.some((r) => r.order_voids > 0 || r.item_voids > 0);

  const {
    winStart,
    winEnd,
    lastIdx,
    fullLen,
    isZoomed,
    isDragging,
    chartAreaRef,
    handlePointerDown,
    handlePointerMove,
    handlePointerUp,
    shiftWindow,
    zoomBy,
    resetZoom,
  } = useChartZoom(rows.length);

  return (
    <div className="space-y-3">
      {/* Legend + trend toggle + zoom/pan controls (mirrors RevenueChart). */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
        <span className="text-sm font-semibold text-foreground">
          {t("revenue.voids.chart.title")}
        </span>
        <span className="flex items-center gap-1.5">
          <span
            className="size-2.5 rounded-sm"
            style={{ background: VOID_ORDER_COLOR }}
          />
          {t("revenue.voids.chart.order_voids")}
        </span>
        <span className="flex items-center gap-1.5">
          <span
            className="size-2.5 rounded-sm"
            style={{ background: VOID_ITEM_COLOR }}
          />
          {t("revenue.voids.chart.item_voids")}
        </span>
        {showTrend ? (
          <span className="flex items-center gap-1.5">
            <span className="relative inline-flex h-2 w-5 items-center">
              <span
                className="h-0.5 w-full"
                style={{ background: VOID_TREND_COLOR }}
              />
              <span
                className="absolute left-1/2 size-1.5 -translate-x-1/2 rounded-full"
                style={{ background: VOID_TREND_COLOR }}
              />
            </span>
            {t("revenue.voids.chart.trend")}
          </span>
        ) : null}
        <div className="ml-auto flex items-center gap-3">
          <label className="flex items-center gap-2">
            <span>{t("revenue.voids.chart.show_trend")}</span>
            <Switch
              checked={showTrend}
              onCheckedChange={setShowTrend}
              className="h-5 w-9 border-0 p-0.5 data-[state=unchecked]:bg-slate-400 [&_[data-slot=switch-thumb]]:data-[state=checked]:translate-x-4"
            />
          </label>
          <ChartZoomControls
            isZoomed={isZoomed}
            winStart={winStart}
            winEnd={winEnd}
            lastIdx={lastIdx}
            fullLen={fullLen}
            shiftWindow={shiftWindow}
            zoomBy={zoomBy}
            resetZoom={resetZoom}
            t={t}
          />
        </div>
      </div>

      {isZoomed ? (
        <div className="flex items-center justify-between text-[11px] text-muted-foreground">
          <span>{t("revenue.chart.zoom_hint")}</span>
          <span className="tabular-nums">
            {rows[winStart]
              ? formatPeriodCompact(rows[winStart].period, granularity, locale, t)
              : ""}
            {" → "}
            {rows[winEnd]
              ? formatPeriodCompact(rows[winEnd].period, granularity, locale, t)
              : ""}
          </span>
        </div>
      ) : null}

      <div
        ref={chartAreaRef}
        className={cn(
          "h-[360px] select-none touch-none overscroll-contain",
          isZoomed && (isDragging ? "cursor-grabbing" : "cursor-grab"),
        )}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onPointerCancel={handlePointerUp}
      >
        {isLoading ? (
          <div className="flex h-full items-center justify-center">
            <Spinner />
          </div>
        ) : rows.length === 0 || !hasAny ? (
          <VoidEmptyState t={t} />
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart
              // Slice by the zoom window ourselves (no <Brush>) so wheel/
              // pan/keyboard zoom drives the visible range.
              data={rows.slice(winStart, winEnd + 1)}
              margin={{ top: 10, right: 16, left: 0, bottom: 8 }}
            >
              <CartesianGrid strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="label"
                tick={{ fontSize: 12 }}
                interval="preserveStartEnd"
              />
              <YAxis
                yAxisId="count"
                allowDecimals={false}
                tick={{ fontSize: 11 }}
                width={36}
                // Reserve a strip above the tallest stack so the trend dot at
                // the peak stays fully visible instead of clipping at the top.
                padding={{ top: 8, bottom: 0 }}
              />
              <Tooltip
                cursor={{ fill: "var(--color-muted, #f5f5f4)", opacity: 0.5 }}
                content={({ active, payload }) => {
                  if (!active || !payload || !payload.length) return null;
                  const row = payload[0]?.payload as
                    | (RevenueVoidSeriesPoint & {
                        label: string;
                        subLabel?: string;
                      })
                    | undefined;
                  if (!row) return null;
                  return (
                    <div className="rounded-md border bg-popover px-3 py-2 text-xs shadow-md">
                      <div className="font-medium">
                        {row.label}
                        {row.subLabel ? (
                          <span className="ml-1 text-muted-foreground">
                            ({row.subLabel})
                          </span>
                        ) : null}
                      </div>
                      <div className="mt-1 flex items-center gap-1.5 tabular-nums">
                        <span
                          className="size-2 rounded-sm"
                          style={{ background: VOID_ORDER_COLOR }}
                        />
                        {t("revenue.voids.chart.order_voids")}:{" "}
                        {numberFmt.format(row.order_voids)}
                      </div>
                      <div className="flex items-center gap-1.5 tabular-nums">
                        <span
                          className="size-2 rounded-sm"
                          style={{ background: VOID_ITEM_COLOR }}
                        />
                        {t("revenue.voids.chart.item_voids")}:{" "}
                        {numberFmt.format(row.item_voids)}
                      </div>
                      <div className="mt-0.5 tabular-nums text-muted-foreground">
                        {t("revenue.voids.chart.value")}:{" "}
                        {moneyFmt.format(row.void_value)}
                      </div>
                    </div>
                  );
                }}
              />
              {/* Stacked (shared stackId) so the two non-overlapping lenses
                  read as ONE centred column whose height is the day's total
                  void count. The trend line below rides `total_voids` on this
                  SAME axis, so each node lands exactly on the top of its stack
                  (đường xu hướng đi qua đỉnh các cột). Only the top segment is
                  rounded; the bottom sits flush for one clean rounded cap. */}
              <Bar
                yAxisId="count"
                dataKey="order_voids"
                stackId="voids"
                fill={VOID_ORDER_COLOR}
                radius={[0, 0, 0, 0]}
                maxBarSize={26}
              />
              <Bar
                yAxisId="count"
                dataKey="item_voids"
                stackId="voids"
                fill={VOID_ITEM_COLOR}
                radius={[4, 4, 0, 0]}
                maxBarSize={26}
              />
              {showTrend ? (
                // Same axis + dataKey as the stacked total, so every node sits
                // on a bar top and the segments connect top-to-top. `monotone`
                // is the only recharts smooth curve that cannot overshoot the
                // data (no dipping below 0 on a sharp drop). Style mirrors
                // RevenueChart's trend overlay for a consistent look.
                <Line
                  yAxisId="count"
                  type="monotone"
                  dataKey="total_voids"
                  stroke={VOID_TREND_COLOR}
                  strokeWidth={3}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeOpacity={0.9}
                  dot={{
                    r: 4,
                    fill: "#fff",
                    stroke: VOID_TREND_COLOR,
                    strokeWidth: 2,
                  }}
                  activeDot={{ r: 6, strokeWidth: 2 }}
                  isAnimationActive={false}
                />
              ) : null}
            </ComposedChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  );
}

function VoidReasonBars({
  reasons,
  isLoading,
  tone,
  moneyFmt,
  numberFmt,
  t,
}: {
  reasons?: RevenueVoidReason[];
  isLoading: boolean;
  tone: "order" | "item";
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  t: T;
}) {
  if (isLoading) return <Spinner className="mx-auto size-5" />;
  if (!reasons || reasons.length === 0) return <VoidEmptyState t={t} compact />;
  const max = Math.max(...reasons.map((r) => r.count), 1);
  const color = tone === "item" ? VOID_ITEM_COLOR : VOID_ORDER_COLOR;

  return (
    <div className="space-y-2.5">
      {reasons.map((row, idx) => (
        <div key={idx}>
          <div className="flex items-center justify-between gap-2 text-xs">
            <span className="min-w-0 flex-1 truncate" title={row.reason || ""}>
              {row.reason.trim() ? (
                row.reason
              ) : (
                <span className="italic text-muted-foreground">
                  {t("revenue.voids.no_reason")}
                </span>
              )}
            </span>
            <span className="shrink-0 tabular-nums text-muted-foreground">
              {numberFmt.format(row.count)} · {moneyFmt.format(row.value)}
            </span>
          </div>
          <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
            <div
              className="h-full"
              style={{ width: `${(row.count / max) * 100}%`, background: color }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function VoidTopItems({
  items,
  isLoading,
  moneyFmt,
  numberFmt,
  t,
}: {
  items?: RevenueVoidTopItem[];
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  t: T;
}) {
  if (isLoading) {
    return (
      <div className="p-8 text-center">
        <Spinner className="mx-auto size-5" />
      </div>
    );
  }
  if (!items || items.length === 0) {
    return (
      <div className="p-8">
        <VoidEmptyState t={t} />
      </div>
    );
  }
  const max = Math.max(...items.map((i) => i.count), 1);

  return (
    <div>
      <div className="border-b bg-muted/30 px-4 py-2.5 text-sm font-semibold">
        {t("revenue.voids.top_items")}
      </div>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
            <th className="w-10 px-4 py-2 text-left font-medium">#</th>
            <th className="px-4 py-2 text-left font-medium">
              {t("revenue.voids.col.item")}
            </th>
            <th className="px-4 py-2 text-right font-medium">
              {t("revenue.voids.col.count")}
            </th>
            <th className="px-4 py-2 text-right font-medium">
              {t("revenue.voids.col.value")}
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((row, idx) => (
            <tr key={idx} className="border-b last:border-0 hover:bg-muted/30">
              <td className="px-4 py-2.5 align-top tabular-nums text-muted-foreground">
                {idx + 1}
              </td>
              <td className="px-4 py-2.5">
                <div className="flex items-center gap-2">
                  <span className="font-medium">{row.name}</span>
                  {row.variant ? (
                    <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                      {row.variant}
                    </span>
                  ) : null}
                </div>
                <div className="mt-1.5 h-1 w-full max-w-[220px] overflow-hidden rounded-full bg-muted">
                  <div
                    className="h-full"
                    style={{
                      width: `${(row.count / max) * 100}%`,
                      background: VOID_ITEM_COLOR,
                    }}
                  />
                </div>
              </td>
              <td className="px-4 py-2.5 text-right align-top font-semibold tabular-nums">
                {numberFmt.format(row.count)}
              </td>
              <td className="px-4 py-2.5 text-right align-top tabular-nums">
                {moneyFmt.format(row.value)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function VoidEmptyState({ t, compact }: { t: T; compact?: boolean }) {
  return (
    <div
      className={cn(
        "flex h-full flex-col items-center justify-center gap-1 text-center text-sm text-muted-foreground",
        compact ? "py-6" : "py-10",
      )}
    >
      <span>{t("revenue.voids.empty")}</span>
    </div>
  );
}

/** A small dot+label pill telling whole-order voids apart from item voids. */
function VoidKindBadge({ kind, t }: { kind: "order" | "item"; t: T }) {
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border px-2 py-0.5 text-xs">
      <span
        className="size-1.5 rounded-full"
        style={{
          background: kind === "item" ? VOID_ITEM_COLOR : VOID_ORDER_COLOR,
        }}
      />
      {t(kind === "item" ? "revenue.voids.log.item" : "revenue.voids.log.order")}
    </span>
  );
}

/**
 * VoidLog — the drill-down table behind the aggregates: every individual
 * cancellation (which order, when, why, how much), newest first. Owns its own
 * type filter (all / order / item) + pagination; shares the tab's window
 * (granularity/from/to) so it always matches the charts above.
 */
function VoidLog({
  shopSlug,
  granularity,
  from,
  to,
  moneyFmt,
  numberFmt,
  locale,
  t,
}: {
  shopSlug: string;
  granularity: RevenueGranularity;
  from: string;
  to: string;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
}) {
  const [type, setType] = useState<RevenueVoidEventType>("all");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);

  // Reset to page 1 whenever the window, lens, or page size changes so the
  // user never lands on an out-of-range page.
  useEffect(() => {
    setPage(1);
  }, [type, from, to, granularity, pageSize]);

  const { data, isLoading, error } = useRevenueVoidEvents(shopSlug, {
    granularity,
    from,
    to,
    type,
    page,
    per_page: pageSize,
  });

  const rows = data?.rows ?? [];
  const total = data?.total ?? 0;

  return (
    <Card className="overflow-hidden p-0">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/30 px-4 py-2.5">
        <span className="text-sm font-semibold">
          {t("revenue.voids.log.title")}
        </span>
        <Tabs
          value={type}
          onValueChange={(v) => setType(v as RevenueVoidEventType)}
        >
          <TabsList>
            <TabsTrigger value="all">{t("revenue.voids.log.all")}</TabsTrigger>
            <TabsTrigger value="order">
              {t("revenue.voids.log.order")}
            </TabsTrigger>
            <TabsTrigger value="item">{t("revenue.voids.log.item")}</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {error ? (
        <div className="border-b bg-destructive/5 px-4 py-3 text-sm text-destructive">
          {(error as Error).message || t("common.error_unknown")}
        </div>
      ) : null}

      {isLoading ? (
        <div className="p-8 text-center">
          <Spinner className="mx-auto size-5" />
        </div>
      ) : rows.length === 0 ? (
        <div className="p-8">
          <VoidEmptyState t={t} />
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                <th className="px-4 py-2 text-left font-medium">
                  {t("revenue.voids.log.col.time")}
                </th>
                <th className="px-4 py-2 text-left font-medium">
                  {t("revenue.voids.log.col.kind")}
                </th>
                <th className="px-4 py-2 text-left font-medium">
                  {t("revenue.voids.log.col.order")}
                </th>
                <th className="px-4 py-2 text-left font-medium">
                  {t("revenue.voids.log.col.detail")}
                </th>
                <th className="px-4 py-2 text-left font-medium">
                  {t("revenue.voids.log.col.reason")}
                </th>
                <th className="px-4 py-2 text-right font-medium">
                  {t("revenue.voids.log.col.value")}
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, idx) => (
                <VoidLogRow
                  key={`${row.kind}-${row.order_id}-${idx}`}
                  row={row}
                  moneyFmt={moneyFmt}
                  numberFmt={numberFmt}
                  locale={locale}
                  t={t}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {total > 0 ? (
        <PaginationFooter
          page={page}
          pageSize={pageSize}
          total={total}
          onPageChange={setPage}
          onPageSizeChange={(size) => {
            setPageSize(size);
            setPage(1);
          }}
          t={t}
        />
      ) : null}
    </Card>
  );
}

function VoidLogRow({
  row,
  moneyFmt,
  numberFmt,
  locale,
  t,
}: {
  row: RevenueVoidEvent;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
}) {
  const provisional = isProvisionalCode(row.order_code);
  return (
    <tr className="border-b last:border-0 hover:bg-muted/30">
      <td className="whitespace-nowrap px-4 py-2.5 align-top tabular-nums text-muted-foreground">
        {formatDateTime(new Date(row.voided_at), locale)}
      </td>
      <td className="px-4 py-2.5 align-top">
        <VoidKindBadge kind={row.kind} t={t} />
      </td>
      <td className="whitespace-nowrap px-4 py-2.5 align-top">
        {provisional ? (
          <span className="italic text-muted-foreground">
            {t("revenue.voids.log.no_code")}
          </span>
        ) : (
          <span className="font-medium tabular-nums">{row.order_code}</span>
        )}
      </td>
      <td className="px-4 py-2.5 align-top">
        {row.kind === "item" ? (
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="font-medium">{row.item_name}</span>
            {row.variant ? (
              <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                {row.variant}
              </span>
            ) : null}
            {row.quantity > 1 ? (
              <span className="text-xs text-muted-foreground">
                ×{numberFmt.format(row.quantity)}
              </span>
            ) : null}
          </div>
        ) : (
          <span className="text-muted-foreground">
            {t("revenue.voids.log.whole_order", {
              count: numberFmt.format(row.item_count),
            })}
          </span>
        )}
      </td>
      <td className="px-4 py-2.5 align-top">
        {row.reason.trim() ? (
          <span className="text-foreground">{row.reason}</span>
        ) : (
          <span className="italic text-muted-foreground">
            {t("revenue.voids.no_reason")}
          </span>
        )}
      </td>
      <td className="whitespace-nowrap px-4 py-2.5 text-right align-top tabular-nums">
        {moneyFmt.format(row.value)}
      </td>
    </tr>
  );
}

/**
 * Decorate a void series point with an X-axis label (+ sublabel), mirroring
 * `decoratePoint` for the revenue series: day → "5" (Mon), month → "5月"
 * (2026), year → "2026".
 */
function decorateVoidPoint(
  point: RevenueVoidSeriesPoint,
  granularity: RevenueGranularity,
  t: T,
): RevenueVoidSeriesPoint & {
  label: string;
  subLabel?: string;
  total_voids: number;
} {
  // Height of the stacked column = the two non-overlapping lenses summed.
  // The trend line rides `total_voids` on the same axis, so each node sits
  // exactly on the top of its stack (đường xu hướng đi qua đỉnh các cột).
  const total_voids = point.order_voids + point.item_voids;
  if (granularity === "year") {
    return { ...point, total_voids, label: point.period };
  }
  if (granularity === "month") {
    const [y, m] = point.period.split("-");
    return {
      ...point,
      total_voids,
      label: t(`revenue.month_${Number(m)}`),
      subLabel: y,
    };
  }
  const parts = point.period.split("-");
  const dayNum = Number(parts[2] ?? "0");
  const date = new Date(point.period + "T00:00:00");
  const weekdayIdx = (date.getDay() + 6) % 7; // Mon=0..Sun=6
  return {
    ...point,
    total_voids,
    label: String(dayNum),
    subLabel: t(`revenue.weekday_${weekdayIdx}`),
  };
}

function KpiStrip({
  data,
  isLoading,
  moneyFmt,
  numberFmt,
  t,
}: {
  data?: RevenueSummary;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  t: T;
}) {
  const cards: Array<{
    label: string;
    value: string;
    delta?: number;
    sub?: string;
  }> = [
    {
      label: t("revenue.kpi.revenue"),
      value: data ? moneyFmt.format(data.kpis.revenue) : "—",
      delta: data?.kpis.delta_pct,
      sub: data ? t("revenue.kpi.vs_prev") : undefined,
    },
    {
      label: t("revenue.kpi.orders"),
      value: data ? numberFmt.format(data.kpis.orders) : "—",
    },
    {
      label: t("revenue.kpi.guests"),
      value: data ? numberFmt.format(data.kpis.guests) : "—",
    },
    {
      label: t("revenue.kpi.avg_per_guest"),
      value: data ? moneyFmt.format(data.kpis.avg_per_guest) : "—",
    },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {cards.map((card, idx) => (
        <Card key={idx} className="p-4">
          <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {card.label}
          </div>
          <div className="mt-2 text-2xl font-semibold tabular-nums">
            {isLoading ? <Spinner className="size-5" /> : card.value}
          </div>
          {typeof card.delta === "number" ? (
            <div
              className={cn(
                "mt-1 flex items-center gap-1 text-xs tabular-nums",
                card.delta >= 0 ? "text-emerald-600" : "text-rose-600",
              )}
            >
              {card.delta >= 0 ? (
                <TrendingUpIcon className="size-3.5" />
              ) : (
                <TrendingDownIcon className="size-3.5" />
              )}
              <span>
                {card.delta >= 0 ? "+" : ""}
                {card.delta}%
              </span>
              {card.sub ? (
                <span className="text-muted-foreground">{card.sub}</span>
              ) : null}
            </div>
          ) : null}
        </Card>
      ))}
    </div>
  );
}

function RevenueChart({
  data,
  granularity,
  isLoading,
  moneyFmt,
  numberFmt,
  locale,
  t,
}: {
  data?: RevenueSummary;
  granularity: RevenueGranularity;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
}) {
  const [showTrend, setShowTrend] = useState(true);
  const rows = useMemo(
    () => (data ? data.series.map((p) => decoratePoint(p, granularity, t)) : []),
    [data, granularity, t],
  );

  // ── Zoom / pan window ────────────────────────────────────────────────
  // `null` means "show all". When zoomed, we keep an inclusive [start, end]
  // index range into `rows` that drives both the Brush component and our
  // wheel-zoom + drag-pan handlers. Reset whenever the dataset length
  // changes (new period selected, granularity flip, refetch).
  const [zoomWindow, setZoomWindow] = useState<
    { start: number; end: number } | null
  >(null);
  useEffect(() => {
    setZoomWindow(null);
  }, [rows.length]);

  const fullLen = rows.length;
  const lastIdx = Math.max(0, fullLen - 1);
  const winStart = zoomWindow?.start ?? 0;
  const winEnd = zoomWindow?.end ?? lastIdx;
  const isZoomed = zoomWindow !== null && (winStart > 0 || winEnd < lastIdx);

  const dragRef = useRef<{
    startX: number;
    initStart: number;
    initEnd: number;
    width: number;
  } | null>(null);
  const [isDragging, setIsDragging] = useState(false);

  const clampWindow = (start: number, end: number) => {
    const s = Math.max(0, Math.min(start, lastIdx - 1));
    const e = Math.max(s + 1, Math.min(end, lastIdx));
    return { start: s, end: e };
  };

  // All zoom/pan paths funnel through here. Two layers of defence
  // against the recharts <Brush> re-render loop:
  //
  // (1) Functional updater compares prev vs next by member values and
  //     returns the SAME reference if nothing changed → React bails out
  //     of re-rendering. Without this, a dedup-set still allocates a
  //     new object → Brush sees "new" startIndex/endIndex props →
  //     Brush's internal useEffect calls setState in commit phase →
  //     onChange fires → we set again → loop.
  //
  // (2) `lastAppliedRef` records the values we last committed. When
  //     Brush echoes onChange right after our own commit (its internal
  //     traveller useEffect can fire with the same indices we just
  //     pushed), handleBrushChange consults this ref and skips. This
  //     handles the case where Brush's onChange object is NEW per call
  //     but represents the same window.
  const lastAppliedRef = useRef<{ start: number; end: number } | null>(null);
  // `source` discriminates updates that originate inside <Brush>
  const safeSetZoom = useCallback(
    (next: { start: number; end: number } | null) => {
      lastAppliedRef.current = next ? { ...next } : null;
      setZoomWindow((prev) => {
        if (prev === null && next === null) return prev;
        if (
          prev &&
          next &&
          prev.start === next.start &&
          prev.end === next.end
        ) {
          return prev;
        }
        return next;
      });
    },
    [],
  );

  // Anchored wheel zoom — keeps the point under the cursor stable while
  // expanding/contracting the window.
  //
  // Why a native listener (instead of React's onWheel)? React attaches
  // wheel handlers as PASSIVE by default since v17, which makes
  // `e.preventDefault()` a no-op. Trackpad pinch fires `wheel` with
  // `ctrlKey: true`, and without preventDefault the browser then zooms
  // the whole page on top of our chart zoom. Attaching with
  // `{ passive: false }` via addEventListener lets us cancel it cleanly.
  const chartAreaRef = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    const el = chartAreaRef.current;
    if (!el) return;
    const onWheel = (e: WheelEvent) => {
      if (fullLen < 2) return;
      e.preventDefault();
      e.stopPropagation();
      const direction = e.deltaY > 0 ? 1 : -1; // +1 zoom out, -1 zoom in
      const span = winEnd - winStart;
      // Wheel events fire 5–20× per gesture so even a small per-event
      // factor accumulates quickly. Trackpad pinch (ctrlKey) fires the
      // densest stream → lowest factor. Tuned by feel: 0.03 for pinch
      // matches a comfortable "1 small turn = 1–2 cột" cadence, 0.08 for
      // mouse wheel rolls at a similar rate per click. `Math.ceil` (not
      // round) so very large windows still move at least 1 index per
      // event but small windows don't double-step.
      const factor = e.ctrlKey ? 0.03 : 0.04;
      const step = Math.max(1, Math.ceil(span * factor));
      const rect = el.getBoundingClientRect();
      const ratio = Math.min(
        1,
        Math.max(0, (e.clientX - rect.left) / rect.width),
      );

      let newStart: number;
      let newEnd: number;
      if (direction < 0) {
        newStart = winStart + Math.round(step * ratio);
        newEnd = winEnd - Math.round(step * (1 - ratio));
        if (newEnd - newStart < 1) {
          const mid = Math.floor((winStart + winEnd) / 2);
          newStart = Math.max(0, mid);
          newEnd = Math.min(lastIdx, mid + 1);
        }
      } else {
        newStart = winStart - Math.round(step * ratio);
        newEnd = winEnd + Math.round(step * (1 - ratio));
      }

      const clamped = clampWindow(newStart, newEnd);
      if (clamped.start <= 0 && clamped.end >= lastIdx) {
        safeSetZoom(null);
      } else {
        safeSetZoom(clamped);
      }
    };
    el.addEventListener("wheel", onWheel, { passive: false });
    return () => el.removeEventListener("wheel", onWheel);
  }, [fullLen, winStart, winEnd, lastIdx]);

  // Drag-to-pan. Only active while zoomed in (no point panning the full
  // range). We capture pointer so a mouseup outside the chart still ends
  // the drag cleanly.
  const handlePointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (!isZoomed) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    dragRef.current = {
      startX: e.clientX,
      initStart: winStart,
      initEnd: winEnd,
      width: e.currentTarget.getBoundingClientRect().width,
    };
    setIsDragging(true);
  };
  // Throttle pointer moves to one update per animation frame. Pointer
  // events fire well above 60Hz on many devices; without batching, each
  // tick commits a new zoomWindow and rechart's <Brush> wakes up, runs
  // its internal useEffect, and reschedules another commit before React
  // has settled. rAF coalesces them into at most one state update per
  // paint, which is what the chart can actually render anyway.
  const panRafRef = useRef<number | null>(null);
  const pendingPanRef = useRef<{ start: number; end: number } | null>(null);
  const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = dragRef.current;
    if (!d) return;
    const span = d.initEnd - d.initStart + 1;
    // The wrapper width includes the Y axis label gutter (~56px on the
    // left) and the right margin (~16px). Subtracting them gives the
    // real plot-area width so a pixel-perfect drag matches 1 index.
    const plotWidth = Math.max(50, d.width - 56 - 16);
    const pxPerIdx = plotWidth / span;
    if (pxPerIdx <= 0) return;
    // Make drag 1.4× more sensitive so a small mouse movement moves a
    // meaningful number of indices — felt sluggish before.
    const deltaIdx = Math.round(-((e.clientX - d.startX) * 1.4) / pxPerIdx);
    if (deltaIdx === 0) return;
    let s = d.initStart + deltaIdx;
    let en = d.initEnd + deltaIdx;
    if (s < 0) {
      en -= s;
      s = 0;
    }
    if (en > lastIdx) {
      s -= en - lastIdx;
      en = lastIdx;
    }
    pendingPanRef.current = { start: s, end: en };
    if (panRafRef.current === null) {
      panRafRef.current = requestAnimationFrame(() => {
        panRafRef.current = null;
        const next = pendingPanRef.current;
        pendingPanRef.current = null;
        if (next) safeSetZoom(next);
      });
    }
  };
  const handlePointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
    if (dragRef.current && e.currentTarget.hasPointerCapture(e.pointerId)) {
      e.currentTarget.releasePointerCapture(e.pointerId);
    }
    dragRef.current = null;
    setIsDragging(false);
    // Flush any pending pan update so the drag's final pixel lands.
    if (panRafRef.current !== null) {
      cancelAnimationFrame(panRafRef.current);
      panRafRef.current = null;
    }
    if (pendingPanRef.current) {
      const next = pendingPanRef.current;
      pendingPanRef.current = null;
      safeSetZoom(next);
    }
  };
  // Clean up any in-flight rAF if the component unmounts mid-drag.
  useEffect(
    () => () => {
      if (panRafRef.current !== null) cancelAnimationFrame(panRafRef.current);
    },
    [],
  );

  // Shift the visible window by half its current span. Way more reliable
  // for "browse forward / backward" than free-drag, and surfaces as the
  // ‹ / › buttons + left/right arrow keys.
  const shiftWindow = (direction: -1 | 1) => {
    if (!isZoomed) return;
    const span = winEnd - winStart + 1;
    const step = Math.max(1, Math.round(span * 0.5));
    let s = winStart + direction * step;
    let en = winEnd + direction * step;
    if (s < 0) {
      en -= s;
      s = 0;
    }
    if (en > lastIdx) {
      s -= en - lastIdx;
      en = lastIdx;
    }
    safeSetZoom({ start: s, end: en });
  };

  // Keyboard nav — Left / Right shift the window, Shift+Left/Right shifts
  // by a full span (page-style), Esc resets. Only attaches while the chart
  // is zoomed so we don't fight other shortcuts on the page.
  useEffect(() => {
    if (!isZoomed) return;
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement | null;
      if (target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) return;
      if (e.key === "ArrowLeft") {
        e.preventDefault();
        shiftWindow(-1);
      } else if (e.key === "ArrowRight") {
        e.preventDefault();
        shiftWindow(1);
      } else if (e.key === "Escape") {
        safeSetZoom(null);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [isZoomed, winStart, winEnd, lastIdx]);

  const zoomBy = (direction: 1 | -1) => {
    if (fullLen < 2) return;
    const span = winEnd - winStart;
    const step = Math.max(1, Math.round(span * 0.25));
    if (direction < 0) {
      const newStart = winStart + Math.round(step / 2);
      const newEnd = winEnd - Math.round(step / 2);
      if (newEnd - newStart < 1) return;
      safeSetZoom(clampWindow(newStart, newEnd));
    } else {
      const newStart = winStart - Math.round(step / 2);
      const newEnd = winEnd + Math.round(step / 2);
      const c = clampWindow(newStart, newEnd);
      if (c.start <= 0 && c.end >= lastIdx) {
        safeSetZoom(null);
      } else {
        safeSetZoom(c);
      }
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <div className="text-sm font-semibold">{t("revenue.chart.title")}</div>
        <div className="flex items-center gap-3 text-xs text-muted-foreground">
          <span className="flex items-center gap-1.5">
            <span className="size-2.5 rounded-sm bg-primary" />
            {t("revenue.chart.revenue")}
          </span>
          {showTrend ? (
            <span className="flex items-center gap-1.5">
              <span className="relative inline-flex h-2 w-5 items-center">
                <span className="h-0.5 w-full bg-slate-400" />
                <span className="absolute left-1/2 size-1.5 -translate-x-1/2 rounded-full bg-slate-400" />
              </span>
              {t("revenue.chart.trend")}
            </span>
          ) : null}
          <label className="flex items-center gap-2">
            <span>{t("revenue.chart.show_trend")}</span>
            <Switch
              checked={showTrend}
              onCheckedChange={setShowTrend}
              className="h-5 w-9 p-0.5 border-0 data-[state=unchecked]:bg-slate-400 [&_[data-slot=switch-thumb]]:data-[state=checked]:translate-x-4"
            />
          </label>

          {/* Pan controls — primary way to navigate when zoomed in.
              Visually grouped + bigger than the zoom buttons so users
              spot them first. Each click shifts by half the window. */}
          <div className="inline-flex items-center rounded-md border bg-background">
            <Button
              variant="ghost"
              size="sm"
              className="size-8 rounded-r-none px-0"
              onClick={() => shiftWindow(-1)}
              disabled={!isZoomed || winStart <= 0}
              aria-label={t("revenue.chart.pan_left")}
              title={t("revenue.chart.pan_left")}
            >
              <ChevronLeftIcon className="size-4" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="size-8 rounded-l-none border-l px-0"
              onClick={() => shiftWindow(1)}
              disabled={!isZoomed || winEnd >= lastIdx}
              aria-label={t("revenue.chart.pan_right")}
              title={t("revenue.chart.pan_right")}
            >
              <ChevronRightIcon className="size-4" />
            </Button>
          </div>

          {/* Zoom controls. */}
          <div className="inline-flex items-center rounded-md border bg-background">
            <Button
              variant="ghost"
              size="sm"
              className="size-8 rounded-r-none px-0"
              onClick={() => zoomBy(-1)}
              disabled={fullLen < 2 || winEnd - winStart <= 1}
              aria-label={t("revenue.chart.zoom_in")}
              title={t("revenue.chart.zoom_in")}
            >
              <PlusIcon className="size-3.5" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="size-8 rounded-none border-x px-0"
              onClick={() => zoomBy(1)}
              disabled={!isZoomed}
              aria-label={t("revenue.chart.zoom_out")}
              title={t("revenue.chart.zoom_out")}
            >
              <MinusIcon className="size-3.5" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="size-8 rounded-l-none px-0"
              onClick={() => safeSetZoom(null)}
              disabled={!isZoomed}
              aria-label={t("revenue.chart.zoom_reset")}
              title={t("revenue.chart.zoom_reset")}
            >
              <MaximizeIcon className="size-3.5" />
            </Button>
          </div>
        </div>
      </div>
      {isZoomed ? (
        <div className="flex items-center justify-between text-[11px] text-muted-foreground">
          <span>{t("revenue.chart.zoom_hint")}</span>
          <span className="tabular-nums">
            {rows[winStart]
              ? formatPeriodCompact(rows[winStart].period, granularity, locale, t)
              : ""}
            {" → "}
            {rows[winEnd]
              ? formatPeriodCompact(rows[winEnd].period, granularity, locale, t)
              : ""}
          </span>
        </div>
      ) : null}
      <div
        ref={chartAreaRef}
        className={cn(
          "h-[400px] select-none touch-none overscroll-contain",
          isZoomed && (isDragging ? "cursor-grabbing" : "cursor-grab"),
        )}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        onPointerCancel={handlePointerUp}
      >
        {isLoading ? (
          <div className="flex h-full items-center justify-center">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState t={t} />
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart
              // Without <Brush> to clip the visible range, we slice the
              // data ourselves so wheel/pan/keyboard zoom still works.
              data={rows.slice(winStart, winEnd + 1)}
              margin={{ top: 10, right: 16, left: 0, bottom: 8 }}
            >
              <CartesianGrid strokeDasharray="3 3" vertical={false} />
              <XAxis
                dataKey="label"
                tick={{ fontSize: 12 }}
                interval="preserveStartEnd"
              />
              <YAxis
                tick={{ fontSize: 11 }}
                tickFormatter={(v) =>
                  v >= 1_000_000
                    ? `${(v / 1_000_000).toFixed(1)}M`
                    : v >= 1000
                      ? `${Math.round(v / 1000)}k`
                      : String(v)
                }
                width={56}
                // Reserve a tiny strip above the tallest bar so the
                // trend dot at the max point stays fully visible
                // instead of clipping at the chart's top edge.
                padding={{ top: 8, bottom: 0 }}
              />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload || !payload.length) return null;
                  const row = payload[0]?.payload as
                    | (RevenueSeriesPoint & { label: string; subLabel?: string })
                    | undefined;
                  if (!row) return null;
                  return (
                    <div className="rounded-md border bg-popover px-3 py-2 text-xs shadow-md">
                      <div className="font-medium">
                        {row.label}
                        {row.subLabel ? (
                          <span className="ml-1 text-muted-foreground">
                            ({row.subLabel})
                          </span>
                        ) : null}
                      </div>
                      <div className="mt-1 tabular-nums">
                        {t("revenue.chart.revenue")}: {moneyFmt.format(row.revenue)}
                      </div>
                      <div className="tabular-nums text-muted-foreground">
                        {t("revenue.chart.orders")}: {numberFmt.format(row.orders)}
                      </div>
                      <div className="tabular-nums text-muted-foreground">
                        {t("revenue.chart.guests")}: {numberFmt.format(row.guests)}
                      </div>
                    </div>
                  );
                }}
              />
              <Bar
                dataKey="revenue"
                fill="var(--color-primary, #38bdf8)"
                radius={[4, 4, 0, 0]}
                maxBarSize={42}
              />
              {showTrend ? (
                // Trend line shares the bars' Y axis and dataKey ("revenue"),
                // so each node sits exactly at the top of its bar and the
                // segments connect bar-top to bar-top. This is purely a
                // visual overlay — same data, easier to read direction at
                // a glance.
                // `monotone` is the only smooth curve recharts ships that
                // mathematically cannot overshoot the data points — it
                // preserves monotonicity between adjacent samples. We
                // switched away from `natural` (cubic spline) because on
                // datasets with a sharp dip near 0 (e.g. day 29 with
                // ~¥0 revenue), the spline would overshoot below the X
                // axis and visually escape the chart. Same problem at
                // the top: cells next to the period max would overshoot
                // above the highest Y tick. Visual softness now comes
                // from stroke width + round caps + lighter color.
                <Line
                  type="monotone"
                  dataKey="revenue"
                  stroke="#94a3b8"
                  strokeWidth={3}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeOpacity={0.9}
                  dot={{
                    r: 4,
                    fill: "#fff",
                    stroke: "#94a3b8",
                    strokeWidth: 2,
                  }}
                  activeDot={{ r: 6, strokeWidth: 2 }}
                  isAnimationActive={false}
                />
              ) : null}
            </ComposedChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  );
}

function SidebarHeader({ title }: { title: string; t: T }) {
  return <div className="mb-3 text-sm font-semibold">{title}</div>;
}

function WeekdayBars({
  data,
  isLoading,
  moneyFmt,
  t,
}: {
  data?: RevenueSummary;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  t: T;
}) {
  if (isLoading) return <Spinner className="mx-auto size-5" />;
  if (!data || !data.by_weekday.length) return <EmptyState t={t} compact />;
  const max = Math.max(...data.by_weekday.map((d) => d.avg_revenue), 1);

  return (
    <div className="space-y-1.5">
      {data.by_weekday.map((row) => (
        <div key={row.weekday} className="flex items-center gap-2 text-xs">
          <span className="w-8 text-muted-foreground">
            {t(`revenue.weekday_${row.weekday}`)}
          </span>
          <div className="relative h-5 flex-1 overflow-hidden rounded bg-muted">
            <div
              className="absolute inset-y-0 left-0 bg-primary/60"
              style={{ width: `${(row.avg_revenue / max) * 100}%` }}
            />
          </div>
          <span className="w-24 text-right tabular-nums">
            {moneyFmt.format(row.avg_revenue)}
          </span>
        </div>
      ))}
    </div>
  );
}

function PaymentSplit({
  data,
  isLoading,
  moneyFmt,
  t,
}: {
  data?: RevenueSummary;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  t: T;
}) {
  if (isLoading) return <Spinner className="mx-auto size-5" />;
  if (!data || !data.by_payment_method.length)
    return <EmptyState t={t} compact />;

  return (
    <div className="space-y-2">
      {data.by_payment_method.map((row, idx) => (
        <div key={idx}>
          <div className="flex items-center justify-between text-xs">
            <span>{row.name || row.code || t("revenue.payment_unknown")}</span>
            <span className="tabular-nums text-muted-foreground">
              {moneyFmt.format(row.amount)} · {row.share_pct}%
            </span>
          </div>
          <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
            <div
              className="h-full bg-primary"
              style={{ width: `${row.share_pct}%` }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

/**
 * Detail table — mirrors the Japanese "日別売上 / 月別売上" layout from
 * the mobile reference (see chat). 4 columns:
 *
 *   ┌──────────────┬───────────┬──────────┬──────────────┐
 *   │ 集計期間      │ 売上 [a]  │ 客数 [c] │ 客単価 (a÷c) │
 *   ├──────────────┼───────────┼──────────┼──────────────┤
 *   │ 5/1(金)      │ ¥63,846  │     63  │      ¥1,013  │
 *   │ ...                                                │
 *   └────────────────────────────────────────────────────┘
 *
 * Period column shows the day + weekday inline ("5/1(金)" / "5/1 (Sun)" /
 * "5月" for month granularity). The [a] / [c] / (a÷c) annotations tell
 * the operator which raw values feed the derived 客単価 column —
 * matching the mobile original verbatim is the point.
 *
 * Footer row sums revenue / guests across the visible window and
 * recomputes the average for the whole period, so the operator sees the
 * total alongside the daily detail without scrolling back up to the KPI
 * strip.
 */
function DetailTable({
  data,
  granularity,
  isLoading,
  moneyFmt,
  numberFmt,
  locale,
  t,
}: {
  data?: RevenueSummary;
  granularity: RevenueGranularity;
  isLoading: boolean;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
}) {
  if (isLoading) {
    return (
      <div className="p-8 text-center">
        <Spinner className="mx-auto size-5" />
      </div>
    );
  }
  if (!data || data.series.length === 0) {
    return (
      <div className="p-8">
        <EmptyState t={t} />
      </div>
    );
  }

  const rows = data.series.map((p) => decoratePoint(p, granularity, t));
  const totalRevenue = data.kpis.revenue;
  const totalGuests = data.kpis.guests;
  const totalAvg =
    totalGuests > 0 ? Math.round(totalRevenue / totalGuests) : 0;

  return (
    <DetailTableInner
      rows={rows}
      granularity={granularity}
      moneyFmt={moneyFmt}
      numberFmt={numberFmt}
      locale={locale}
      t={t}
      totalRevenue={totalRevenue}
      totalGuests={totalGuests}
      totalAvg={totalAvg}
    />
  );
}

/**
 * Inner table with its own page/pageSize state. Lives in its own
 * component so the `useState` calls are colocated with the rendering
 * and resetting on data change is straightforward (effect deps watch
 * granularity + dataset length).
 */
function DetailTableInner({
  rows,
  granularity,
  moneyFmt,
  numberFmt,
  locale,
  t,
  totalRevenue,
  totalGuests,
  totalAvg,
}: {
  rows: Array<RevenueSeriesPoint & { label: string; subLabel?: string }>;
  granularity: RevenueGranularity;
  moneyFmt: Intl.NumberFormat;
  numberFmt: Intl.NumberFormat;
  locale: string;
  t: T;
  totalRevenue: number;
  totalGuests: number;
  totalAvg: number;
}) {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);

  // Reset to page 1 when the underlying dataset shape changes — flipping
  // granularity (day↔month) or changing date range invalidates the
  // previous slice's row identities. Length is a cheap dependency that
  // captures both cases.
  useEffect(() => {
    setPage(1);
  }, [granularity, rows.length]);

  const total = rows.length;
  const lastPage = Math.max(1, Math.ceil(total / pageSize));
  const clampedPage = Math.min(page, lastPage);
  const visibleRows = rows.slice(
    (clampedPage - 1) * pageSize,
    clampedPage * pageSize,
  );

  // Saturday=5 / Sunday=6 in our Monday=0..Sunday=6 weekday scheme. Tint
  // them blue / red the way Japanese calendars do — operators read the
  // weekend rows at a glance without scanning the label.
  const weekendColor = (weekdayIdx: number) =>
    weekdayIdx === 5
      ? "text-sky-600"
      : weekdayIdx === 6
        ? "text-rose-600"
        : "";

  return (
    <>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
        <thead className="border-b bg-muted/40 text-xs text-muted-foreground">
          <tr>
            <th className="px-5 py-3 text-left font-medium">
              {granularity === "year"
                ? t("revenue.table.year")
                : granularity === "month"
                  ? t("revenue.table.month")
                  : t("revenue.table.period")}
            </th>
            <th className="px-5 py-3 text-right font-medium">
              <span>{t("revenue.table.revenue")}</span>
              <span className="ml-1 text-[10px] text-muted-foreground/70">
                [a]
              </span>
            </th>
            <th className="px-5 py-3 text-right font-medium">
              <span>{t("revenue.table.guests")}</span>
              <span className="ml-1 text-[10px] text-muted-foreground/70">
                [c]
              </span>
            </th>
            <th className="px-5 py-3 text-right font-medium">
              <span>{t("revenue.table.avg_per_guest")}</span>
              <span className="ml-1 text-[10px] text-muted-foreground/70">
                (a÷c)
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          {visibleRows.map((row, idx) => {
            const avg =
              row.guests > 0 ? Math.round(row.revenue / row.guests) : null;
            const weekdayIdx =
              granularity === "day" && row.period.length === 10
                ? (new Date(row.period + "T00:00:00").getDay() + 6) % 7
                : -1;
            return (
              <tr
                key={row.period}
                className={cn(
                  "border-b transition-colors hover:bg-muted/30",
                  idx % 2 === 1 && "bg-muted/10",
                )}
              >
                <td className="px-5 py-3 font-medium tabular-nums">
                  {formatPeriodCompact(row.period, granularity, locale, t)}
                  {weekdayIdx >= 0 ? (
                    <span
                      className={cn(
                        "ml-1 text-xs text-muted-foreground",
                        weekendColor(weekdayIdx),
                      )}
                    >
                      ({t(`revenue.weekday_${weekdayIdx}`)})
                    </span>
                  ) : null}
                </td>
                <td className="px-5 py-3 text-right tabular-nums">
                  {row.revenue > 0 ? (
                    moneyFmt.format(row.revenue)
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </td>
                <td className="px-5 py-3 text-right tabular-nums">
                  {row.guests > 0 ? (
                    numberFmt.format(row.guests)
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </td>
                <td className="px-5 py-3 text-right tabular-nums">
                  {avg !== null ? (
                    moneyFmt.format(avg)
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
        <tfoot className="border-t-2 bg-muted/40 text-sm font-semibold">
          <tr>
            <td className="px-5 py-3 uppercase tracking-wide text-xs text-muted-foreground">
              {t("revenue.table.total")}
            </td>
            <td className="px-5 py-3 text-right tabular-nums">
              {moneyFmt.format(totalRevenue)}
            </td>
            <td className="px-5 py-3 text-right tabular-nums">
              {numberFmt.format(totalGuests)}
            </td>
            <td className="px-5 py-3 text-right tabular-nums">
              {totalGuests > 0 ? (
                moneyFmt.format(totalAvg)
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </td>
          </tr>
        </tfoot>
      </table>
      </div>
      <PaginationFooter
        page={clampedPage}
        pageSize={pageSize}
        total={total}
        onPageChange={setPage}
        onPageSizeChange={(size) => {
          setPageSize(size);
          setPage(1);
        }}
        t={t}
      />
    </>
  );
}

/**
 * Compact period label used in the time table. Day granularity drops
 * the year to keep the cell tight (operators rarely span years in a
 * daily report). Month granularity uses the spelled month name from
 * i18n. Order follows locale convention:
 *   ja → MM/DD,  YYYY年M月
 *   en → MM/DD,  M月-style "Jun 2026"
 *   vi → DD/MM,  Tháng M YYYY
 */
function formatPeriodCompact(
  period: string,
  granularity: RevenueGranularity,
  locale: string,
  t: T,
): string {
  const parts = period.split("-");
  if (granularity === "year") {
    if (locale === "ja") return `${period}年`;
    return period;
  }
  if (granularity === "month") {
    const y = parts[0] ?? "";
    const m = Number(parts[1] ?? "0");
    const monthName = t(`revenue.month_${m}`);
    if (locale === "ja") return `${y}年${monthName}`;
    if (locale === "vi") return `${monthName} ${y}`;
    return `${monthName} ${y}`;
  }
  const month = Number(parts[1] ?? "0");
  const day = Number(parts[2] ?? "0");
  // Vietnam reads day/month; JA + EN read month/day in compact form.
  return locale === "vi" ? `${day}/${month}` : `${month}/${day}`;
}

function EmptyState({ t, compact }: { t: T; compact?: boolean }) {
  return (
    <div
      className={cn(
        "flex h-full flex-col items-center justify-center text-sm text-muted-foreground",
        compact ? "py-6" : "py-10",
      )}
    >
      <span>{t("revenue.empty")}</span>
    </div>
  );
}

// ---------------------------------------------------------------------------
//  Helpers
// ---------------------------------------------------------------------------

const PRESETS: Array<{
  key: string;
  labelKey: string;
  compute: () => { from: Date; to: Date };
}> = [
  {
    key: "today",
    labelKey: "revenue.preset.today",
    compute: () => {
      const d = new Date();
      return { from: startOfDay(d), to: endOfDay(d) };
    },
  },
  {
    key: "yesterday",
    labelKey: "revenue.preset.yesterday",
    compute: () => {
      const d = new Date();
      d.setDate(d.getDate() - 1);
      return { from: startOfDay(d), to: endOfDay(d) };
    },
  },
  {
    key: "last7",
    labelKey: "revenue.preset.last7",
    compute: () => {
      const to = new Date();
      const from = new Date();
      from.setDate(from.getDate() - 6);
      return { from: startOfDay(from), to: endOfDay(to) };
    },
  },
  {
    key: "last30",
    labelKey: "revenue.preset.last30",
    compute: () => {
      const to = new Date();
      const from = new Date();
      from.setDate(from.getDate() - 29);
      return { from: startOfDay(from), to: endOfDay(to) };
    },
  },
  {
    key: "thisMonth",
    labelKey: "revenue.preset.thisMonth",
    compute: () => {
      const now = new Date();
      const from = new Date(now.getFullYear(), now.getMonth(), 1);
      return { from, to: endOfDay(now) };
    },
  },
  {
    key: "lastMonth",
    labelKey: "revenue.preset.lastMonth",
    compute: () => {
      const now = new Date();
      const from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      const to = new Date(now.getFullYear(), now.getMonth(), 0);
      return { from, to: endOfDay(to) };
    },
  },
  {
    key: "ytd",
    labelKey: "revenue.preset.ytd",
    compute: () => {
      const now = new Date();
      const from = new Date(now.getFullYear(), 0, 1);
      return { from, to: endOfDay(now) };
    },
  },
];

function startOfDay(d: Date): Date {
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  return x;
}

function endOfDay(d: Date): Date {
  const x = new Date(d);
  x.setHours(23, 59, 59, 999);
  return x;
}

function computeRange(
  granularity: RevenueGranularity,
  year: number,
  month: number,
): { from: string; to: string } {
  if (granularity === "year") {
    // 5-year trailing window anchored at the picked year. Matches the
    // backend's default span so the chart fits comfortably without
    // empty leading buckets.
    return {
      from: `${year - 4}-01-01`,
      to: `${year}-12-31`,
    };
  }
  if (granularity === "month") {
    return {
      from: `${year}-01-01`,
      to: `${year}-12-31`,
    };
  }
  const lastDay = new Date(year, month, 0).getDate();
  const m = String(month).padStart(2, "0");
  return {
    from: `${year}-${m}-01`,
    to: `${year}-${m}-${String(lastDay).padStart(2, "0")}`,
  };
}

function yearOptions(currentYear: number): number[] {
  const years: number[] = [];
  for (let y = currentYear + 1; y >= currentYear - 5; y--) {
    years.push(y);
  }
  return years;
}

function decoratePoint(
  point: RevenueSeriesPoint,
  granularity: RevenueGranularity,
  t: T,
): RevenueSeriesPoint & { label: string; subLabel?: string } {
  if (granularity === "year") {
    // period = "2026". No sublabel — year is the headline tick.
    return {
      ...point,
      label: point.period,
    };
  }
  if (granularity === "month") {
    const [y, m] = point.period.split("-");
    return {
      ...point,
      label: t(`revenue.month_${Number(m)}`),
      subLabel: y,
    };
  }
  const parts = point.period.split("-");
  const dayNum = Number(parts[2] ?? "0");
  const date = new Date(point.period + "T00:00:00");
  const weekdayIdx = (date.getDay() + 6) % 7; // Mon=0..Sun=6
  return {
    ...point,
    label: String(dayNum),
    subLabel: t(`revenue.weekday_${weekdayIdx}`),
  };
}

