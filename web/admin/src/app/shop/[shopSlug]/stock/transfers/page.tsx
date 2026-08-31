"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
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
import { useStockTransfers } from "@/hooks/api/use-stock-transfers";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { StockTransferStatus } from "@/services/stock-transfer-service";
import { useTranslation } from "@/providers/app-provider";
import { StockTransferListTable } from "./components/stock-transfer-table";

const ANY_WAREHOUSE = "__any_warehouse__";

function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

export default function StockTransfersPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const STATUS_OPTIONS = useMemo(
    () => [
      { value: StockTransferStatus.Draft, label: t("status.draft") },
      { value: StockTransferStatus.Pending, label: t("status.pending") },
      { value: StockTransferStatus.Approved, label: t("status.approved") },
      { value: StockTransferStatus.InTransit, label: t("status.in_transit") },
      { value: StockTransferStatus.Completed, label: t("status.completed") },
      { value: StockTransferStatus.Cancelled, label: t("status.cancelled") },
    ],
    [t]
  );

  const [search, setSearch] = useState("");
  const [sourceWarehouseId, setSourceWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [destWarehouseId, setDestWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [statuses, setStatuses] = useState<StockTransferStatus[]>([]);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebounce(search, 300);

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const filters = useMemo(
    () => ({
      page,
      search: debouncedSearch || undefined,
      source_warehouse_id: sourceWarehouseId === ANY_WAREHOUSE ? undefined : sourceWarehouseId,
      destination_warehouse_id: destWarehouseId === ANY_WAREHOUSE ? undefined : destWarehouseId,
      status: statuses.length > 0 ? statuses : undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }),
    [page, debouncedSearch, sourceWarehouseId, destWarehouseId, statuses, dateFrom, dateTo]
  );

  const { data, isLoading, error, refetch, isFetching } = useStockTransfers(shopSlug, filters);
  const transfers = data?.data ?? [];
  const meta = data?.meta;

  return (
    <>
      <PageHeader
        title={t("shop.stock.transfers.title")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" asChild>
          <Link href={`/shop/${shopSlug}/stock/transfers/new`}>
            <Plus className="mr-1.5 size-3.5" />
            {t("shop.stock.transfers.new")}
          </Link>
        </Button>
        <HelpPanel
          title={t("shop.stock.transfers.title")}
          subtitle={t("help.panel.shop_stock_transfers.subtitle")}
          purpose={t("help.panel.shop_stock_transfers.purpose")}
          usage={[
            t("help.panel.shop_stock_transfers.usage.1"),
            t("help.panel.shop_stock_transfers.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_stock_transfers.checks.1"),
            t("help.panel.shop_stock_transfers.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_stock_transfers.glossary.status_chain.term"),
              description: t("help.panel.shop_stock_transfers.glossary.status_chain.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Select
            value={sourceWarehouseId}
            onValueChange={(v) => {
              setSourceWarehouseId(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-48 text-sm">
              <SelectValue placeholder={t("shop.stock.transfers.filter.source_warehouse")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_WAREHOUSE}>
                {t("shop.stock.transfers.filter.any_source")}
              </SelectItem>
              {warehouses.map((w) => (
                <SelectItem key={`src-${w.id}`} value={w.id}>
                  {w.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select
            value={destWarehouseId}
            onValueChange={(v) => {
              setDestWarehouseId(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-48 text-sm">
              <SelectValue placeholder={t("shop.stock.transfers.filter.dest_warehouse")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_WAREHOUSE}>
                {t("shop.stock.transfers.filter.any_dest")}
              </SelectItem>
              {warehouses.map((w) => (
                <SelectItem key={`dst-${w.id}`} value={w.id}>
                  {w.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <MultiCombobox
            options={STATUS_OPTIONS}
            value={statuses}
            onChange={(v) => {
              setStatuses(v as StockTransferStatus[]);
              setPage(1);
            }}
            placeholder={t("common.all_statuses")}
            searchPlaceholder={t("common.filter_statuses")}
            className="h-8 w-44 text-sm"
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
              placeholder={t("shop.stock.transfers.search_placeholder")}
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
            {t("shop.stock.transfers.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <StockTransferListTable shopSlug={shopSlug} data={transfers} />
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
