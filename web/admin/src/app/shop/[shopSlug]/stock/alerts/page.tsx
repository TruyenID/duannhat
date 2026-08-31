"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { AlertTriangle, PackageX, Search, TrendingDown } from "lucide-react";
import {
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
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { useStockAlerts, useStockAlertSummary } from "@/hooks/api/use-stock-alerts";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { StockAlertStatus, StockAlertType, type StockAlert } from "@/services/stock-alert-service";
import { useTranslation } from "@/providers/app-provider";
import { StockAlertListTable } from "./components/stock-alert-table";
import { StockLevelThresholdSheet } from "./components/stock-level-threshold-sheet";

const ANY_WAREHOUSE = "__any_warehouse__";
const ANY_STATUS = "__any_status__";
const ANY_TYPE = "__any_type__";

function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

function SummaryCard({
  label,
  value,
  Icon,
  tone,
}: {
  label: string;
  value: number;
  Icon: React.ComponentType<{ className?: string }>;
  tone: "red" | "amber" | "slate";
}) {
  const toneClass = {
    red: "text-red-600 dark:text-red-400",
    amber: "text-amber-600 dark:text-amber-400",
    slate: "text-slate-700 dark:text-slate-200",
  }[tone];
  return (
    <div className="flex items-center gap-3 rounded-md border bg-card p-4">
      <Icon className={`size-8 ${toneClass}`} />
      <div>
        <div className="text-xs tracking-wide text-muted-foreground uppercase">{label}</div>
        <div className={`text-2xl font-semibold tabular-nums ${toneClass}`}>{value}</div>
      </div>
    </div>
  );
}

export default function StockAlertsPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const [warehouseId, setWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [status, setStatus] = useState<string>(ANY_STATUS);
  const [alertType, setAlertType] = useState<string>(ANY_TYPE);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebounce(search, 300);

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const summaryQuery = useStockAlertSummary(shopSlug);
  const summary = summaryQuery.data?.data;

  const filters = useMemo(
    () => ({
      page,
      search: debouncedSearch || undefined,
      warehouse_id: warehouseId === ANY_WAREHOUSE ? undefined : warehouseId,
      status: status === ANY_STATUS ? undefined : (status as StockAlertStatus),
      alert_type: alertType === ANY_TYPE ? undefined : (alertType as StockAlertType),
    }),
    [page, debouncedSearch, warehouseId, status, alertType]
  );

  const { data, isLoading, error, refetch, isFetching } = useStockAlerts(shopSlug, filters);
  const alerts = data?.data ?? [];
  const meta = data?.meta;

  // Plan-024 — inline threshold-edit sheet state. Opens when the user
  // clicks "Configure threshold" on an alert row. The sheet's mutation
  // hook invalidates the alerts query cache on success, so a successful
  // re-evaluation that auto-resolves the alert removes the row from
  // the active filter view.
  const [thresholdSheetAlert, setThresholdSheetAlert] = useState<StockAlert | null>(null);

  function alertItemName(alert: StockAlert): string {
    return (
      alert.productSku?.name ??
      alert.productSku?.sku ??
      alert.material?.name ??
      alert.material?.sku ??
      "—"
    );
  }

  /**
   * Plan-024 UX-4 — resolve a sensible unit for the threshold sheet.
   * Priority: alert snapshot → material yield_unit → null (the sheet
   * then renders just the number without a stray "unit" placeholder).
   *
   * The historical hard-coded "piece" fallback at write-time is masked
   * here when a better source is available; we accept that legacy
   * alerts will keep showing "piece" since rewriting them is out of
   * plan-024 scope.
   */
  function alertUnit(alert: StockAlert): string | null {
    const fromAlert = alert.unit?.trim();
    if (fromAlert && fromAlert !== "piece") return fromAlert;
    const materialUnit = (alert.material as { yield_unit?: string | null } | null | undefined)
      ?.yield_unit;
    if (materialUnit?.trim()) return materialUnit.trim();
    // SKUs don't have a yield_unit, but they're countable; fall back to a
    // sensible default rather than the generic "piece" snapshot.
    if (fromAlert) return fromAlert;
    return null;
  }

  return (
    <>
      <PageHeader
        title={t("shop.stock.alerts.title")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("shop.stock.alerts.title")}
          subtitle={t("help.panel.shop_stock_alerts.subtitle")}
          purpose={t("help.panel.shop_stock_alerts.purpose")}
          usage={[
            t("help.panel.shop_stock_alerts.usage.1"),
            t("help.panel.shop_stock_alerts.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_stock_alerts.checks.1"),
            t("help.panel.shop_stock_alerts.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_stock_alerts.glossary.alert_types.term"),
              description: t("help.panel.shop_stock_alerts.glossary.alert_types.desc"),
            },
            {
              term: t("help.panel.shop_stock_alerts.glossary.resolved.term"),
              description: t("help.panel.shop_stock_alerts.glossary.resolved.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <SummaryCard
            label={t("shop.stock.alerts.summary.total_active")}
            value={summary?.total_active ?? 0}
            Icon={AlertTriangle}
            tone={summary && summary.total_active > 0 ? "red" : "slate"}
          />
          <SummaryCard
            label={t("shop.stock.alerts.summary.low_stock")}
            value={summary?.low_stock_count ?? 0}
            Icon={TrendingDown}
            tone={summary && summary.low_stock_count > 0 ? "amber" : "slate"}
          />
          <SummaryCard
            label={t("shop.stock.alerts.summary.out_of_stock")}
            value={summary?.out_of_stock_count ?? 0}
            Icon={PackageX}
            tone={summary && summary.out_of_stock_count > 0 ? "red" : "slate"}
          />
        </div>

        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Select
            value={warehouseId}
            onValueChange={(v) => {
              setWarehouseId(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-48 text-sm">
              <SelectValue placeholder={t("common.all_warehouses")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_WAREHOUSE}>{t("common.all_warehouses")}</SelectItem>
              {warehouses.map((w) => (
                <SelectItem key={w.id} value={w.id}>
                  {w.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select
            value={alertType}
            onValueChange={(v) => {
              setAlertType(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-40 text-sm">
              <SelectValue placeholder={t("shop.stock.alerts.filter.all_types")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_TYPE}>{t("shop.stock.alerts.filter.all_types")}</SelectItem>
              <SelectItem value={StockAlertType.LowStock}>
                {t("shop.stock.alerts.filter.low_stock")}
              </SelectItem>
              <SelectItem value={StockAlertType.OutOfStock}>
                {t("shop.stock.alerts.filter.out_of_stock")}
              </SelectItem>
            </SelectContent>
          </Select>

          <Select
            value={status}
            onValueChange={(v) => {
              setStatus(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-36 text-sm">
              <SelectValue placeholder={t("common.all_statuses")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_STATUS}>{t("common.all_statuses")}</SelectItem>
              <SelectItem value={StockAlertStatus.Active}>
                {t("shop.stock.alerts.filter.active")}
              </SelectItem>
              <SelectItem value={StockAlertStatus.Resolved}>
                {t("shop.stock.alerts.filter.resolved")}
              </SelectItem>
            </SelectContent>
          </Select>

          <div className="relative w-64">
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={t("shop.stock.alerts.search_placeholder")}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              className="h-8 pl-8 text-sm"
            />
          </div>
        </div>

        {isLoading && (
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
            <span className="ml-2 text-sm text-muted-foreground">{t("common.loading")}</span>
          </div>
        )}

        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">
            {t("shop.stock.alerts.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <StockAlertListTable
              data={alerts}
              onConfigureThreshold={(alert) => setThresholdSheetAlert(alert)}
            />
            {meta && meta.last_page > 1 && (
              <div className="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                <span>
                  {t("common.showing", {
                    from: meta.from ?? 0,
                    to: meta.to ?? 0,
                    total: meta.total,
                  })}
                </span>
                <div className="flex gap-1">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => p - 1)}
                  >
                    {t("common.previous")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= meta.last_page}
                    onClick={() => setPage((p) => p + 1)}
                  >
                    {t("common.next")}
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </PageContent>

      {thresholdSheetAlert && thresholdSheetAlert.stock_level_id && (
        <StockLevelThresholdSheet
          open={!!thresholdSheetAlert}
          onOpenChange={(open) => {
            if (!open) setThresholdSheetAlert(null);
          }}
          shopSlug={shopSlug}
          stockLevelId={thresholdSheetAlert.stock_level_id}
          itemName={alertItemName(thresholdSheetAlert)}
          warehouseName={thresholdSheetAlert.warehouse?.name ?? "—"}
          currentQuantity={Number(thresholdSheetAlert.current_quantity ?? 0)}
          unit={alertUnit(thresholdSheetAlert)}
          initialMinStock={thresholdSheetAlert.min_stock?.toString() ?? ""}
          initialMaxStock={null}
          initialAlertEnabled={true}
        />
      )}
    </>
  );
}
