"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, BarChart3, Package, TrendingDown } from "lucide-react";
import {
  Badge,
  Button,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useDisposalWasteReport } from "@/hooks/api/use-disposals";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";
import type { LocaleCode } from "@/i18n";
import { DisposalReason } from "@/types/models/enum/DisposalReason";

const ANY_WAREHOUSE = "__any_warehouse__";

const REASON_COLOR: Record<string, string> = {
  [DisposalReason.Expired]:
    "bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-100",
  [DisposalReason.Overproduction]:
    "bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-100",
  [DisposalReason.Damaged]:
    "bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-100",
  [DisposalReason.Quality]:
    "bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-100",
  [DisposalReason.Contaminated]:
    "bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-100",
  [DisposalReason.Other]: "bg-slate-100 text-slate-700",
};

function formatNumber(n: number, fractionDigits = 0): string {
  return n.toLocaleString(undefined, {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  });
}

function formatCurrency(n: number): string {
  return n.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  });
}

const LOCALE_TO_INTL: Record<LocaleCode, string> = { vi: "vi-VN", en: "en-US", ja: "ja-JP" };

function formatDayMonth(iso: string, locale: LocaleCode): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(LOCALE_TO_INTL[locale], {
    month: "2-digit",
    day: "2-digit",
  }).format(d);
}

function defaultRange(): { from: string; to: string } {
  const today = new Date();
  const from = new Date(today);
  from.setDate(from.getDate() - 29);
  const fmt = (d: Date) => d.toISOString().slice(0, 10);
  return { from: fmt(from), to: fmt(today) };
}

export default function WasteReportPage() {
  const { t, locale } = useTranslation();
  const REASON_LABEL: Record<string, string> = {
    [DisposalReason.Expired]: t("shop.stock.disposals.waste_report.reason.expired"),
    [DisposalReason.Overproduction]: t("shop.stock.disposals.waste_report.reason.overproduction"),
    [DisposalReason.Damaged]: t("shop.stock.disposals.waste_report.reason.damaged"),
    [DisposalReason.Quality]: t("shop.stock.disposals.waste_report.reason.quality"),
    [DisposalReason.Contaminated]: t("shop.stock.disposals.waste_report.reason.contaminated"),
    [DisposalReason.Other]: t("shop.stock.disposals.waste_report.reason.other"),
  };
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const initialRange = useMemo(() => defaultRange(), []);
  const [warehouseId, setWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [dateFrom, setDateFrom] = useState<string>(initialRange.from);
  const [dateTo, setDateTo] = useState<string>(initialRange.to);

  const filters = useMemo(
    () => ({
      warehouse_id: warehouseId === ANY_WAREHOUSE ? undefined : warehouseId,
      date_from: dateFrom,
      date_to: dateTo,
      limit: 10,
    }),
    [warehouseId, dateFrom, dateTo]
  );

  const { data, isLoading, error } = useDisposalWasteReport(shopSlug, filters);
  const report = data?.data;

  // Tách ra biến riêng thay vì `report?.daily_trend` trong mảng phụ thuộc:
  // React Compiler suy ra `report.daily_trend` còn nguồn ghi `report?.daily_trend`,
  // hai thứ đó không khớp nên nó BỎ QUA tối ưu cả component. Rút ra một biến thì
  // suy luận và khai báo trùng nhau.
  const dailyTrend = report?.daily_trend;
  const maxDaily = useMemo(() => {
    if (!dailyTrend?.length) return 0;
    return dailyTrend.reduce((m, p) => (p.total_value > m ? p.total_value : m), 0);
  }, [dailyTrend]);

  return (
    <>
      <PageHeader title={t("shop.stock.disposals.waste_report.title")}>
        <Button
          variant="outline"
          size="sm"
          onClick={() => router.push(`/shop/${shopSlug}/stock/disposals`)}
        >
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <Select value={warehouseId} onValueChange={setWarehouseId}>
            <SelectTrigger className="h-8 w-48 text-sm">
              <SelectValue placeholder={t("shop.stock.disposals.waste_report.all_warehouses")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_WAREHOUSE}>{t("shop.stock.disposals.waste_report.all_warehouses")}</SelectItem>
              {warehouses.map((w) => (
                <SelectItem key={w.id} value={w.id}>
                  {w.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Input
            type="date"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            className="h-8 w-36 text-sm"
            aria-label={t("shop.stock.disposals.waste_report.from_aria")}
          />
          <span className="text-xs text-muted-foreground">–</span>
          <Input
            type="date"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            className="h-8 w-36 text-sm"
            aria-label={t("shop.stock.disposals.waste_report.to_aria")}
          />
        </div>

        {isLoading && (
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
          </div>
        )}

        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">
            {t("shop.stock.disposals.waste_report.load_error")}
          </div>
        )}

        {report && (
          <>
            <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
              <SummaryCard
                label={t("shop.stock.disposals.waste_report.summary.total_value")}
                value={formatCurrency(report.summary.total_value)}
                Icon={TrendingDown}
                tone="red"
              />
              <SummaryCard
                label={t("shop.stock.disposals.waste_report.summary.transactions")}
                value={formatNumber(report.summary.total_transactions)}
                Icon={BarChart3}
                tone="slate"
              />
              <SummaryCard
                label={t("shop.stock.disposals.waste_report.summary.items")}
                value={formatNumber(report.summary.total_items)}
                Icon={Package}
                tone="slate"
              />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              <div className="rounded-md border bg-card p-4">
                <h3 className="mb-3 text-sm font-medium">{t("shop.stock.disposals.waste_report.by_reason_title")}</h3>
                {report.by_reason.length === 0 ? (
                  <p className="py-6 text-center text-xs text-muted-foreground">
                    {t("shop.stock.disposals.waste_report.empty_range")}
                  </p>
                ) : (
                  <table className="w-full text-sm">
                    <thead className="text-xs text-muted-foreground">
                      <tr>
                        <th className="px-2 py-1.5 text-left">{t("shop.stock.disposals.waste_report.col.reason")}</th>
                        <th className="px-2 py-1.5 text-right">{t("shop.stock.disposals.waste_report.col.txns")}</th>
                        <th className="px-2 py-1.5 text-right">{t("shop.stock.disposals.waste_report.col.items")}</th>
                        <th className="px-2 py-1.5 text-right">{t("shop.stock.disposals.waste_report.col.value")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {report.by_reason.map((r) => (
                        <tr
                          key={r.disposal_reason}
                          className="border-t border-muted/40"
                        >
                          <td className="px-2 py-1.5">
                            <Badge
                              className={
                                REASON_COLOR[r.disposal_reason] ??
                                "bg-slate-100 text-slate-700"
                              }
                            >
                              {REASON_LABEL[r.disposal_reason] ??
                                r.disposal_reason}
                            </Badge>
                          </td>
                          <td className="px-2 py-1.5 text-right tabular-nums">
                            {formatNumber(r.transaction_count)}
                          </td>
                          <td className="px-2 py-1.5 text-right tabular-nums">
                            {formatNumber(r.item_count)}
                          </td>
                          <td className="px-2 py-1.5 text-right font-medium tabular-nums text-red-600 dark:text-red-400">
                            {formatCurrency(r.total_value)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>

              <div className="rounded-md border bg-card p-4">
                <h3 className="mb-3 text-sm font-medium">{t("shop.stock.disposals.waste_report.daily_trend_title")}</h3>
                {report.daily_trend.length === 0 || maxDaily === 0 ? (
                  <p className="py-6 text-center text-xs text-muted-foreground">
                    {t("shop.stock.disposals.waste_report.empty_range")}
                  </p>
                ) : (
                  <div className="flex h-40 items-end gap-1">
                    {report.daily_trend.map((p) => {
                      const pct = Math.max(2, (p.total_value / maxDaily) * 100);
                      return (
                        <div
                          key={p.date}
                          className="flex flex-1 flex-col items-center gap-1"
                          title={`${p.date}: ${formatCurrency(p.total_value)}`}
                        >
                          <div
                            className="w-full rounded-sm bg-red-300 dark:bg-red-800"
                            style={{ height: `${pct}%` }}
                          />
                          <div className="text-[10px] text-muted-foreground">
                            {formatDayMonth(p.date, locale)}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>

            <div className="mt-4 rounded-md border bg-card p-4">
              <h3 className="mb-3 text-sm font-medium">{t("shop.stock.disposals.waste_report.top_items_title")}</h3>
              {report.top_items.length === 0 ? (
                <p className="py-6 text-center text-xs text-muted-foreground">
                  {t("shop.stock.disposals.waste_report.empty_range")}
                </p>
              ) : (
                <table className="w-full text-sm">
                  <thead className="text-xs text-muted-foreground">
                    <tr>
                      <th className="px-2 py-1.5 text-left">{t("shop.stock.disposals.waste_report.col.item")}</th>
                      <th className="px-2 py-1.5 text-left">{t("shop.stock.disposals.waste_report.col.sku")}</th>
                      <th className="px-2 py-1.5 text-left">{t("shop.stock.disposals.waste_report.col.main_reason")}</th>
                      <th className="px-2 py-1.5 text-right">{t("shop.stock.disposals.waste_report.col.quantity")}</th>
                      <th className="px-2 py-1.5 text-right">{t("shop.stock.disposals.waste_report.col.value")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.top_items.map((it, i) => (
                      <tr key={i} className="border-t border-muted/40">
                        <td className="px-2 py-1.5">{it.item_name ?? "—"}</td>
                        <td className="px-2 py-1.5 font-mono text-xs">
                          {it.item_sku ?? "—"}
                        </td>
                        <td className="px-2 py-1.5">
                          <Badge
                            className={
                              REASON_COLOR[it.main_reason] ??
                              "bg-slate-100 text-slate-700"
                            }
                          >
                            {REASON_LABEL[it.main_reason] ?? it.main_reason}
                          </Badge>
                        </td>
                        <td className="px-2 py-1.5 text-right tabular-nums">
                          {formatNumber(it.total_quantity, 2)}{" "}
                          {it.unit ?? ""}
                        </td>
                        <td className="px-2 py-1.5 text-right font-medium tabular-nums text-red-600 dark:text-red-400">
                          {formatCurrency(it.total_value)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </>
        )}
      </PageContent>
    </>
  );
}

function SummaryCard({
  label,
  value,
  Icon,
  tone,
}: {
  label: string;
  value: string;
  Icon: React.ComponentType<{ className?: string }>;
  tone: "red" | "slate";
}) {
  const toneClass =
    tone === "red"
      ? "text-red-600 dark:text-red-400"
      : "text-slate-700 dark:text-slate-200";
  return (
    <div className="flex items-center gap-3 rounded-md border bg-card p-4">
      <Icon className={`size-8 ${toneClass}`} />
      <div>
        <div className="text-xs uppercase tracking-wide text-muted-foreground">
          {label}
        </div>
        <div className={`text-2xl font-semibold tabular-nums ${toneClass}`}>
          {value}
        </div>
      </div>
    </div>
  );
}
