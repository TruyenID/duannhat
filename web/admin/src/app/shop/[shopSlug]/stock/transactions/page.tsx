"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowDownToLine, ArrowUpFromLine, Search } from "lucide-react";
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
import { useStockTransactions } from "@/hooks/api/use-stock-transactions";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { StockTransactionStatus, StockTransactionType } from "@/services/stock-transaction-service";
import { useTranslation } from "@/providers/app-provider";
import { StockTransactionListTable } from "./components/stock-transaction-table";

// Sentinels for Radix Select's "any" options (Radix forbids an empty value).
const ANY_WAREHOUSE = "__any_warehouse__";
const ANY_TYPE = "__any_type__";

function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

export default function StockTransactionsPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const STATUS_OPTIONS = useMemo(
    () => [
      { value: StockTransactionStatus.Draft, label: t("status.draft") },
      { value: StockTransactionStatus.Pending, label: t("status.pending") },
      { value: StockTransactionStatus.Approved, label: t("status.approved") },
      { value: StockTransactionStatus.Completed, label: t("status.completed") },
      { value: StockTransactionStatus.Cancelled, label: t("status.cancelled") },
    ],
    [t]
  );

  const [search, setSearch] = useState("");
  const [warehouseId, setWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [type, setType] = useState<string>(ANY_TYPE);
  const [statuses, setStatuses] = useState<StockTransactionStatus[]>([]);
  const [dateFrom, setDateFrom] = useState<string>("");
  const [dateTo, setDateTo] = useState<string>("");
  const [page, setPage] = useState(1);

  const debouncedSearch = useDebounce(search, 300);

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const filters = useMemo(
    () => ({
      page,
      search: debouncedSearch || undefined,
      warehouse_id: warehouseId === ANY_WAREHOUSE ? undefined : warehouseId,
      type: type === ANY_TYPE ? undefined : (type as StockTransactionType),
      status: statuses.length > 0 ? statuses : undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }),
    [page, debouncedSearch, warehouseId, type, statuses, dateFrom, dateTo]
  );

  const { data, isLoading, error, refetch, isFetching } = useStockTransactions(shopSlug, filters);
  const transactions = data?.data ?? [];
  const meta = data?.meta;

  return (
    <>
      <PageHeader
        title={t("shop.stock.transactions.title")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700" asChild>
          <Link
            href={`/shop/${shopSlug}/stock/transactions/new?type=${StockTransactionType.StockIn}`}
          >
            <ArrowDownToLine className="mr-1.5 size-3.5" />
            {t("shop.stock.transactions.stock_in")}
          </Link>
        </Button>
        <Button size="sm" className="bg-orange-600 text-white hover:bg-orange-700" asChild>
          <Link
            href={`/shop/${shopSlug}/stock/transactions/new?type=${StockTransactionType.StockOut}`}
          >
            <ArrowUpFromLine className="mr-1.5 size-3.5" />
            {t("shop.stock.transactions.stock_out")}
          </Link>
        </Button>
        <HelpPanel
          title={t("shop.stock.transactions.title")}
          subtitle={t("help.panel.shop_stock_transactions.subtitle")}
          purpose={t("help.panel.shop_stock_transactions.purpose")}
          usage={[
            t("help.panel.shop_stock_transactions.usage.1"),
            t("help.panel.shop_stock_transactions.usage.2"),
            t("help.panel.shop_stock_transactions.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_stock_transactions.checks.1"),
            t("help.panel.shop_stock_transactions.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_stock_transactions.glossary.status_chain.term"),
              description: t("help.panel.shop_stock_transactions.glossary.status_chain.desc"),
            },
            {
              term: t("help.panel.shop_stock_transactions.glossary.items.term"),
              description: t("help.panel.shop_stock_transactions.glossary.items.desc"),
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
            value={type}
            onValueChange={(v) => {
              setType(v);
              setPage(1);
            }}
          >
            <SelectTrigger className="h-8 w-32 text-sm">
              <SelectValue placeholder={t("common.all_types")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ANY_TYPE}>{t("common.all_types")}</SelectItem>
              <SelectItem value={StockTransactionType.StockIn}>
                {t("shop.stock.transactions.filter.type_stock_in")}
              </SelectItem>
              <SelectItem value={StockTransactionType.StockOut}>
                {t("shop.stock.transactions.filter.type_stock_out")}
              </SelectItem>
            </SelectContent>
          </Select>

          <MultiCombobox
            options={STATUS_OPTIONS}
            value={statuses}
            onChange={(v) => {
              setStatuses(v as StockTransactionStatus[]);
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
              placeholder={t("shop.stock.transactions.search_placeholder")}
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
            {t("shop.stock.transactions.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <StockTransactionListTable shopSlug={shopSlug} data={transactions} />
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
