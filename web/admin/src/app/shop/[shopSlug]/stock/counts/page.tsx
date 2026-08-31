"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { Plus, Search } from "lucide-react";
import {
  Button,
  Input,
  MultiCombobox,
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
import { useStockCounts } from "@/hooks/api/use-stock-counts";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { StockCountScope, StockCountStatus } from "@/services/stock-count-service";
import { useTranslation } from "@/providers/app-provider";
import { StockCountListTable } from "./components/stock-count-table";
import { CreateStockCountDialog } from "./components/create-stock-count-dialog";

const ANY_WAREHOUSE = "__any_warehouse__";
const ANY_SCOPE = "__any_scope__";

function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

export default function StockCountsPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const STATUS_OPTIONS = useMemo(
    () => [
      { value: StockCountStatus.Draft, label: t("status.draft") },
      { value: StockCountStatus.InProgress, label: t("status.in_progress") },
      { value: StockCountStatus.PendingApproval, label: t("status.pending") },
      { value: StockCountStatus.Approved, label: t("status.approved") },
      { value: StockCountStatus.Cancelled, label: t("status.cancelled") },
    ],
    [t]
  );

  const [search, setSearch] = useState("");
  const [warehouseId, setWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [scope, setScope] = useState<string>(ANY_SCOPE);
  const [statuses, setStatuses] = useState<StockCountStatus[]>([]);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const [createOpen, setCreateOpen] = useState(false);

  const debouncedSearch = useDebounce(search, 300);

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const filters = useMemo(
    () => ({
      page,
      search: debouncedSearch || undefined,
      warehouse_id: warehouseId === ANY_WAREHOUSE ? undefined : warehouseId,
      scope: scope === ANY_SCOPE ? undefined : (scope as StockCountScope),
      status: statuses.length > 0 ? statuses : undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }),
    [page, debouncedSearch, warehouseId, scope, statuses, dateFrom, dateTo]
  );

  const { data, isLoading, error, refetch, isFetching } = useStockCounts(shopSlug, filters);
  const counts = data?.data ?? [];
  const meta = data?.meta;

  return (
    <>
      <PageHeader
        title={t("shop.stock.counts.title")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" onClick={() => setCreateOpen(true)}>
          <Plus className="mr-1.5 size-3.5" />
          {t("shop.stock.counts.new")}
        </Button>
        <HelpPanel
          title={t("shop.stock.counts.title")}
          subtitle={t("help.panel.shop_stock_counts.subtitle")}
          purpose={t("help.panel.shop_stock_counts.purpose")}
          usage={[
            t("help.panel.shop_stock_counts.usage.1"),
            t("help.panel.shop_stock_counts.usage.2"),
            t("help.panel.shop_stock_counts.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_stock_counts.checks.1"),
            t("help.panel.shop_stock_counts.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_stock_counts.glossary.scope.term"),
              description: t("help.panel.shop_stock_counts.glossary.scope.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
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
            value={scope}
            onValueChange={(v) => {
              setScope(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-32 text-sm">
              <SelectValue placeholder={t("shop.stock.counts.filter.any_scope")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_SCOPE}>{t("shop.stock.counts.filter.any_scope")}</SelectItem>
              <SelectItem value={StockCountScope.Full}>
                {t("shop.stock.counts.filter.full")}
              </SelectItem>
              <SelectItem value={StockCountScope.Partial}>
                {t("shop.stock.counts.filter.partial")}
              </SelectItem>
            </SelectContent>
          </Select>

          <MultiCombobox
            options={STATUS_OPTIONS}
            value={statuses}
            onChange={(v) => {
              setStatuses(v as StockCountStatus[]);
              setPage(1);
            }}
            placeholder={t("common.all_statuses")}
            searchPlaceholder={t("common.filter_statuses")}
            className="h-8 w-48 text-sm"
          />

          <Input
            type="date"
            value={dateFrom}
            onChange={(e) => {
              setDateFrom(e.target.value);
              setPage(1);
            }}
            className="h-8 w-36 text-sm"
            aria-label="From date"
          />
          <span className="text-xs text-muted-foreground">–</span>
          <Input
            type="date"
            value={dateTo}
            onChange={(e) => {
              setDateTo(e.target.value);
              setPage(1);
            }}
            className="h-8 w-36 text-sm"
            aria-label="To date"
          />

          <div className="relative w-56">
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={t("shop.stock.counts.search_placeholder")}
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
            {t("shop.stock.counts.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <StockCountListTable shopSlug={shopSlug} data={counts} />
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

      <CreateStockCountDialog shopSlug={shopSlug} open={createOpen} onOpenChange={setCreateOpen} />
    </>
  );
}
