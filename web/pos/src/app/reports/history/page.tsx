/**
 * OrderHistoryPage — order + payment history for ALL tables in one place.
 *
 * URL: /shop/:shopSlug/reports/history
 *
 * The counterpart to the per-table TableHistoryView: a full-page master–detail
 * where the left column lists every order across every table (all statuses)
 * within a chosen day / month / year, grouped by calendar day and tagged with
 * the table (or "Mang đi" / "Tại quầy"), and the right column tells the selected
 * order's full story — lifecycle, items (added / voided / promo), per-rate
 * totals, and payment history — reusing the exact same renderer as the
 * per-table view (order-history-shared.tsx).
 *
 * The list is an infinite query: a busy month/year loads incrementally via
 * "Xem thêm" instead of one giant page. LAN-first (workstation) with a Cloud
 * fallback, both returning proper pagination meta.
 */

import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
  ArrowLeftIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  RefreshCwIcon,
} from "lucide-react";
import {
  Button,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Tabs,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import { Spinner } from "@/components/ui/spinner";
import { PosHeader } from "@/app/pos/components/pos-header";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import { formatDate, formatYmd } from "@/lib/format-date";
import { setCurrentShopSlug } from "@/lib/shop-context";
import { useShop } from "@/hooks/api/use-shop";
import { useAllOrdersHistory } from "@/hooks/api/use-orders";
import { formatCurrency } from "@/app/pos/lib/totals";
import {
  OrderDetail,
  OrderRow,
  num,
} from "@/app/pos/components/order-history-shared";
import type { CustomerOrder } from "@/app/pos/types";

type Gran = "day" | "month" | "year";

// Inclusive [from, to] YYYY-MM-DD window for the anchor + granularity.
function computeWindow(gran: Gran, anchor: Date): { from: string; to: string } {
  const y = anchor.getFullYear();
  const m = anchor.getMonth();
  if (gran === "day") {
    const s = formatYmd(anchor);
    return { from: s, to: s };
  }
  if (gran === "month") {
    return {
      from: formatYmd(new Date(y, m, 1)),
      to: formatYmd(new Date(y, m + 1, 0)),
    };
  }
  return { from: formatYmd(new Date(y, 0, 1)), to: formatYmd(new Date(y, 11, 31)) };
}

export function OrderHistoryPage() {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();
  const navigate = useNavigate();
  const { t, locale } = useTranslation();

  useEffect(() => {
    setCurrentShopSlug(shopSlug);
  }, [shopSlug]);

  const { data: shopResponse } = useShop(shopSlug);
  const shop = shopResponse?.data;

  const now = useMemo(() => new Date(), []);
  const [gran, setGran] = useState<Gran>("day");
  const [anchor, setAnchor] = useState<Date>(
    () => new Date(now.getFullYear(), now.getMonth(), now.getDate()),
  );
  const [selectedId, setSelectedId] = useState<string | null>(null);

  const year = anchor.getFullYear();
  const month = anchor.getMonth() + 1; // 1-12
  const day = anchor.getDate();
  const daysInMonth = new Date(year, month, 0).getDate();
  const years = useMemo(
    () => Array.from({ length: 6 }, (_, i) => now.getFullYear() - 5 + i),
    [now],
  );

  const { from, to } = useMemo(() => computeWindow(gran, anchor), [gran, anchor]);

  // Reset the selection when the window changes so the detail never shows an
  // order outside the current filter.
  useEffect(() => {
    setSelectedId(null);
  }, [from, to]);

  const query = useAllOrdersHistory(shopSlug, { dateFrom: from, dateTo: to });
  const orders = useMemo<CustomerOrder[]>(
    () => (query.data?.pages ?? []).flatMap((p) => p.data),
    [query.data],
  );
  const total = query.data?.pages?.[0]?.meta.total ?? orders.length;

  // Group by calendar day (orders are newest-first across pages, so plain
  // concatenation + Map insertion order preserves that).
  const groups = useMemo(() => {
    const byDay = new Map<string, CustomerOrder[]>();
    for (const o of orders) {
      const when = o.created_at ?? o.opened_at;
      const key = when ? formatYmd(new Date(when)) : "—";
      (byDay.get(key) ?? byDay.set(key, []).get(key)!).push(o);
    }
    return [...byDay.entries()].map(([key, list]) => {
      const when = list[0]?.created_at ?? list[0]?.opened_at;
      return { key, label: when ? formatDate(when, locale) : "—", orders: list };
    });
  }, [orders, locale]);

  const detailId = useMemo(() => {
    if (selectedId && orders.some((o) => o.id === selectedId)) return selectedId;
    return orders[0]?.id ?? null;
  }, [selectedId, orders]);

  // Total paid across the window — only meaningful once every page is loaded,
  // so it's shown only when there's nothing left to fetch.
  const loadedRevenue = useMemo(
    () => orders.reduce((sum, o) => sum + num(o.paid_amount), 0),
    [orders],
  );
  const fullyLoaded = !query.hasNextPage;

  // Step the anchor by the active unit; "next" is disabled once the window
  // reaches today so we don't page into empty future dates.
  const step = (delta: number) => {
    const d = new Date(anchor);
    if (gran === "day") d.setDate(d.getDate() + delta);
    else if (gran === "month") d.setMonth(d.getMonth() + delta);
    else d.setFullYear(d.getFullYear() + delta);
    setAnchor(d);
  };
  const atCurrent =
    gran === "day"
      ? year === now.getFullYear() &&
        month === now.getMonth() + 1 &&
        day >= now.getDate()
      : gran === "month"
        ? year > now.getFullYear() ||
          (year === now.getFullYear() && month >= now.getMonth() + 1)
        : year >= now.getFullYear();

  const setYear = (yy: number) =>
    setAnchor(new Date(yy, month - 1, Math.min(day, new Date(yy, month, 0).getDate())));
  const setMonth = (mm: number) =>
    setAnchor(new Date(year, mm - 1, Math.min(day, new Date(year, mm, 0).getDate())));
  const setDay = (dd: number) => setAnchor(new Date(year, month - 1, dd));

  return (
    <div className="flex h-dvh flex-col bg-muted/30 text-foreground">
      <PosHeader shopName={shop?.name ?? ""} helpTopic="order-history" />

      {/* Title + filter bar */}
      <div className="shrink-0 border-b bg-card/60">
        <div className="mx-auto flex max-w-[1600px] flex-wrap items-center gap-3 px-3 py-2.5 sm:px-6">
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate(`/shop/${shopSlug}`)}
            className="h-9 gap-1.5 rounded-md px-3 shadow-sm"
          >
            <ArrowLeftIcon className="size-4" />
            <span>{t("common.back")}</span>
          </Button>
          <div className="h-6 w-px bg-border" />
          <h2 className="text-sm font-semibold tracking-tight">
            {t("pos.order_history.title")}
          </h2>
          <span className="text-xs text-muted-foreground">
            {t("pos.order_history.summary", { count: total })}
            {fullyLoaded && total > 0
              ? ` · ${t("pos.order_history.paid_total", { amount: formatCurrency(loadedRevenue) })}`
              : ""}
          </span>

          {/* Granularity + period stepper */}
          <div className="ml-auto flex flex-wrap items-center gap-2">
            <Tabs value={gran} onValueChange={(v) => setGran(v as Gran)}>
              <TabsList>
                <TabsTrigger value="day">{t("pos.order_history.gran_day")}</TabsTrigger>
                <TabsTrigger value="month">{t("pos.order_history.gran_month")}</TabsTrigger>
                <TabsTrigger value="year">{t("pos.order_history.gran_year")}</TabsTrigger>
              </TabsList>
            </Tabs>

            <div className="inline-flex items-center rounded-md border bg-background shadow-sm">
              <Button
                variant="ghost"
                size="sm"
                className="size-9 rounded-r-none px-0 hover:bg-muted"
                onClick={() => step(-1)}
                aria-label={t("pos.order_history.prev")}
              >
                <ChevronLeftIcon className="size-4" />
              </Button>
              <div className="flex items-center gap-1 border-x px-2">
                <Select value={String(year)} onValueChange={(v) => setYear(Number(v))}>
                  <SelectTrigger className="h-8 w-[84px] border-0 shadow-none focus:ring-0 focus:ring-offset-0">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {years.map((yy) => (
                      <SelectItem key={yy} value={String(yy)}>
                        {yy}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {gran !== "year" && (
                  <>
                    <span className="text-muted-foreground">/</span>
                    <Select value={String(month)} onValueChange={(v) => setMonth(Number(v))}>
                      <SelectTrigger className="h-8 w-[116px] border-0 shadow-none focus:ring-0 focus:ring-offset-0">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {Array.from({ length: 12 }, (_, i) => i + 1).map((mm) => (
                          <SelectItem key={mm} value={String(mm)}>
                            {t(`revenue.month_${mm}`)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </>
                )}
                {gran === "day" && (
                  <>
                    <span className="text-muted-foreground">/</span>
                    <Select value={String(day)} onValueChange={(v) => setDay(Number(v))}>
                      <SelectTrigger className="h-8 w-[68px] border-0 shadow-none focus:ring-0 focus:ring-offset-0">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {Array.from({ length: daysInMonth }, (_, i) => i + 1).map((dd) => (
                          <SelectItem key={dd} value={String(dd)}>
                            {dd}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </>
                )}
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="size-9 rounded-l-none px-0 hover:bg-muted"
                onClick={() => step(1)}
                disabled={atCurrent}
                aria-label={t("pos.order_history.next")}
              >
                <ChevronRightIcon className="size-4" />
              </Button>
            </div>

            <Button
              variant="ghost"
              size="sm"
              className="size-9 px-0"
              onClick={() => query.refetch()}
              aria-label={t("pos.order_history.refresh")}
              title={t("pos.order_history.refresh")}
            >
              <RefreshCwIcon className={cn("size-4", query.isFetching && "animate-spin")} />
            </Button>
          </div>
        </div>
      </div>

      {/* Master–detail */}
      <div className="mx-auto flex min-h-0 w-full max-w-[1600px] flex-1">
        {/* Order list, grouped by day */}
        <div
          className={cn(
            "w-full shrink-0 overflow-y-auto border-r bg-background lg:w-[360px]",
            selectedId && "hidden lg:block",
          )}
        >
          {query.isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Spinner />
            </div>
          ) : orders.length === 0 ? (
            <div className="px-6 py-16 text-center text-sm text-muted-foreground">
              {t("pos.order_history.no_orders")}
            </div>
          ) : (
            <>
              {groups.map((group) => (
                <div key={group.key}>
                  <div className="sticky top-0 z-[1] border-b border-border/60 bg-background/95 px-4 py-2 text-xs font-medium text-muted-foreground backdrop-blur">
                    {group.label}
                  </div>
                  <ul className="divide-y divide-border/50">
                    {group.orders.map((order) => (
                      <li key={order.id}>
                        <OrderRow
                          order={order}
                          active={order.id === detailId}
                          onSelect={() => setSelectedId(order.id)}
                          showPlace
                          t={t}
                          locale={locale}
                        />
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
              {query.hasNextPage && (
                <div className="p-3">
                  <Button
                    variant="outline"
                    size="sm"
                    className="w-full"
                    onClick={() => query.fetchNextPage()}
                    disabled={query.isFetchingNextPage}
                  >
                    {query.isFetchingNextPage ? (
                      <Spinner className="size-4" />
                    ) : (
                      t("pos.order_history.load_more", {
                        remaining: Math.max(0, total - orders.length),
                      })
                    )}
                  </Button>
                </div>
              )}
            </>
          )}
        </div>

        {/* Detail */}
        <div
          className={cn(
            "min-w-0 flex-1 overflow-y-auto bg-background",
            !selectedId && "hidden lg:block",
          )}
        >
          {detailId ? (
            <OrderDetail
              shopSlug={shopSlug}
              orderId={detailId}
              onBack={() => setSelectedId(null)}
              allowReprint
              t={t}
              locale={locale}
            />
          ) : (
            <div className="flex h-full items-center justify-center px-6 text-center text-sm text-muted-foreground">
              {t("pos.order_history.select_order")}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
