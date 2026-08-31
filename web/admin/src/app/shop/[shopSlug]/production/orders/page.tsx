"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { Plus, Search } from "lucide-react";
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
import { useProductionOrders } from "@/hooks/api/use-production-orders";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { ProductionOrderStatus } from "@/services/production-order-service";
import { useTranslation } from "@/providers/app-provider";
import { ProductionOrderListTable } from "./components/production-order-table";

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

export default function ProductionOrdersPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const [search, setSearch] = useState("");
  const [warehouseId, setWarehouseId] = useState<string>(ANY_WAREHOUSE);
  const [status, setStatus] = useState<string>(ANY_STATUS);
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
      warehouse_id: warehouseId === ANY_WAREHOUSE ? undefined : warehouseId,
      status: status === ANY_STATUS ? undefined : (status as ProductionOrderStatus),
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }),
    [page, debouncedSearch, warehouseId, status, dateFrom, dateTo]
  );

  const { data, isLoading, error, refetch, isFetching } = useProductionOrders(shopSlug, filters);
  const orders = data?.data ?? [];
  const meta = data?.meta;

  return (
    <>
      <PageHeader
        title={t("shop.production.orders.title")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" asChild>
          <Link href={`/shop/${shopSlug}/production/orders/new`}>
            <Plus className="mr-1.5 size-3.5" />
            {t("shop.production.orders.new")}
          </Link>
        </Button>
        <HelpPanel
          title={t("shop.production.orders.title")}
          subtitle={t("help.panel.shop_production_orders.subtitle")}
          purpose={t("help.panel.shop_production_orders.purpose")}
          usage={[
            t("help.panel.shop_production_orders.usage.1"),
            t("help.panel.shop_production_orders.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_production_orders.checks.1"),
            t("help.panel.shop_production_orders.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_production_orders.glossary.vs_batches.term"),
              description: t("help.panel.shop_production_orders.glossary.vs_batches.desc"),
            },
            {
              term: t("help.panel.shop_production_orders.glossary.output_variant.term"),
              description: t("help.panel.shop_production_orders.glossary.output_variant.desc"),
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
              <SelectItem value={ProductionOrderStatus.Draft}>{t("status.draft")}</SelectItem>
              <SelectItem value={ProductionOrderStatus.Pending}>{t("status.pending")}</SelectItem>
              <SelectItem value={ProductionOrderStatus.Approved}>{t("status.approved")}</SelectItem>
              <SelectItem value={ProductionOrderStatus.InProgress}>
                {t("status.in_progress")}
              </SelectItem>
              <SelectItem value={ProductionOrderStatus.Completed}>
                {t("status.completed")}
              </SelectItem>
              <SelectItem value={ProductionOrderStatus.Cancelled}>
                {t("status.cancelled")}
              </SelectItem>
            </SelectContent>
          </Select>

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
              placeholder={t("shop.production.orders.search_placeholder")}
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
            {t("shop.production.orders.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <ProductionOrderListTable shopSlug={shopSlug} data={orders} />
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
