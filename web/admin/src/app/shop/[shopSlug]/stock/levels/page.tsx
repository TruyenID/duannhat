"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { Search } from "lucide-react";
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
import { PageContent } from "@/components/layout/page-content";
import { useStockLevels } from "@/hooks/api/use-stock-levels";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import type { StockStatus } from "@/services/stock-level-service";
import { useTranslation } from "@/providers/app-provider";
import { HelpPanel } from "@/components/shared/help-panel";
import { StockLevelListTable } from "./components/stock-level-table";

// Sentinel values for the "all" option in each <Select>. Radix Select
// forbids the empty string as an item value, so a unique sentinel is used
// and then translated back to `undefined` before sending to the API.
const ANY_WAREHOUSE = "__any_warehouse__";
const ANY_STATUS = "__any_status__";

function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

export default function StockLevelsPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const [search, setSearch] = useState("");
  const [warehouseId, setWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [status, setStatus] = useState<string>(ANY_STATUS);
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebounce(search, 300);

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const filters = useMemo(
    () => ({
      page,
      search: debouncedSearch || undefined,
      warehouse_id: warehouseId === ANY_WAREHOUSE ? undefined : warehouseId,
      stock_status: status === ANY_STATUS ? undefined : (status as StockStatus),
    }),
    [page, debouncedSearch, warehouseId, status]
  );

  const { data, isLoading, error, refetch, isFetching } = useStockLevels(shopSlug, filters);
  const levels = data?.data ?? [];
  const meta = data?.meta;

  return (
    <>
      <PageHeader
        title={t("shop.stock.levels.title")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("shop.stock.levels.title")}
          subtitle={t("help.panel.shop_stock_levels.subtitle")}
          purpose={t("help.panel.shop_stock_levels.purpose")}
          usage={[
            t("help.panel.shop_stock_levels.usage.1"),
            t("help.panel.shop_stock_levels.usage.2"),
            t("help.panel.shop_stock_levels.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_stock_levels.checks.1"),
            t("help.panel.shop_stock_levels.checks.2"),
            t("help.panel.shop_stock_levels.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_stock_levels.glossary.min_max.term"),
              description: t("help.panel.shop_stock_levels.glossary.min_max.desc"),
            },
            {
              term: t("help.panel.shop_stock_levels.glossary.lot.term"),
              description: t("help.panel.shop_stock_levels.glossary.lot.desc"),
            },
            {
              term: t("help.panel.shop_stock_levels.glossary.type.term"),
              description: t("help.panel.shop_stock_levels.glossary.type.desc"),
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
            <SelectTrigger className="h-8 w-52 text-sm">
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
            value={status}
            onValueChange={(v) => {
              setStatus(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-40 text-sm">
              <SelectValue placeholder={t("common.all_statuses")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_STATUS}>{t("common.all_statuses")}</SelectItem>
              <SelectItem value="normal">{t("shop.stock.levels.filter.in_stock")}</SelectItem>
              <SelectItem value="low">{t("shop.stock.levels.filter.low")}</SelectItem>
              <SelectItem value="out">{t("shop.stock.levels.filter.out")}</SelectItem>
            </SelectContent>
          </Select>

          <div className="relative w-64">
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={t("shop.stock.levels.search_placeholder")}
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
            {t("shop.stock.levels.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <StockLevelListTable data={levels} />
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
    </>
  );
}
