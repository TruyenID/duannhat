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
import { PageContent } from "@/components/layout/page-content";
import { useMaterialBatches } from "@/hooks/api/use-material-batches";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { MaterialBatchStatus } from "@/services/material-batch-service";
import { useTranslation } from "@/providers/app-provider";
import { HelpPanel } from "@/components/shared/help-panel";
import { MaterialBatchListTable } from "./components/material-batch-table";

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

export default function ProductionBatchesPage() {
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
      status: status === ANY_STATUS ? undefined : (status as MaterialBatchStatus),
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }),
    [page, debouncedSearch, warehouseId, status, dateFrom, dateTo]
  );

  const { data, isLoading, error, refetch, isFetching } = useMaterialBatches(shopSlug, filters);
  const batches = data?.data ?? [];
  const meta = data?.meta;

  return (
    <>
      <PageHeader
        title={t("shop.production.batches.title")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" asChild>
          <Link href={`/shop/${shopSlug}/production/batches/new`}>
            <Plus className="mr-1.5 size-3.5" />
            {t("shop.production.batches.new")}
          </Link>
        </Button>
        <HelpPanel
          title={t("shop.production.batches.title")}
          subtitle={t("help.panel.shop_production_batches.subtitle")}
          purpose={t("help.panel.shop_production_batches.purpose")}
          usage={[
            t("help.panel.shop_production_batches.usage.1"),
            t("help.panel.shop_production_batches.usage.2"),
            t("help.panel.shop_production_batches.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_production_batches.checks.1"),
            t("help.panel.shop_production_batches.checks.2"),
            t("help.panel.shop_production_batches.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_production_batches.glossary.status_chain.term"),
              description: t("help.panel.shop_production_batches.glossary.status_chain.desc"),
            },
            {
              term: t("help.panel.shop_production_batches.glossary.multiplier.term"),
              description: t("help.panel.shop_production_batches.glossary.multiplier.desc"),
            },
            {
              term: t("help.panel.shop_production_batches.glossary.yield.term"),
              description: t("help.panel.shop_production_batches.glossary.yield.desc"),
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
              <SelectItem value={MaterialBatchStatus.Draft}>{t("status.draft")}</SelectItem>
              <SelectItem value={MaterialBatchStatus.Pending}>{t("status.pending")}</SelectItem>
              <SelectItem value={MaterialBatchStatus.Approved}>{t("status.approved")}</SelectItem>
              <SelectItem value={MaterialBatchStatus.InProgress}>
                {t("status.in_progress")}
              </SelectItem>
              <SelectItem value={MaterialBatchStatus.Completed}>{t("status.completed")}</SelectItem>
              <SelectItem value={MaterialBatchStatus.Cancelled}>{t("status.cancelled")}</SelectItem>
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
              placeholder={t("shop.production.batches.search_placeholder")}
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
            {t("shop.production.batches.load_error")}
          </div>
        )}

        {!isLoading && !error && (
          <>
            <MaterialBatchListTable shopSlug={shopSlug} data={batches} />
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
