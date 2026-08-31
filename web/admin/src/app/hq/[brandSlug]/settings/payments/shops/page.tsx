"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { SettingsTabsNav } from "../../components/settings-tabs-nav";
import { ConnectionHealthBadge } from "../components/connection-health-badge";
import { PaymentsSettingsShell } from "../components/payments-settings-nav";
import { usePaymentCoverage } from "@/hooks/api/use-payment-gateways";
import type { PaymentCoverageRow } from "@/services/payment-gateway-service";
import { useTranslation } from "@/providers/app-provider";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

const FILTER_DEFAULTS = {
  search: "",
  readiness: "all",
  per_page: "25",
};

export default function HqPaymentShopsCoveragePage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  const {
    filters: urlFilters,
    page,
    setFilter,
    setPage,
    resetFilters,
  } = useSearchFilters(FILTER_DEFAULTS);
  const [search, setSearch] = useState(urlFilters.search);
  const debouncedSearch = useDebounce(search, 300);

  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) setFilter("search", debouncedSearch);
  }, [debouncedSearch]);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      readiness: urlFilters.readiness === "all" ? undefined : urlFilters.readiness,
    }),
    [page, urlFilters, debouncedSearch]
  );

  const { data, isLoading, refetch, isFetching } = usePaymentCoverage(brandSlug, apiFilters);
  const rows = useMemo(() => data?.data ?? [], [data]);
  const meta = data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: null,
    to: null,
  };

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const columns: ColumnDef<PaymentCoverageRow>[] = useMemo(
    () => [
      {
        id: "stt",
        header: t("hq.products.col.stt"),
        size: 50,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{(meta.from ?? 1) + row.index}</span>
        ),
      },
      {
        id: "shop",
        header: t("hq.payments.shops.col.shop"),
        cell: ({ row }) => (
          <Link
            href={`/shop/${row.original.shop_slug}/settings`}
            className="font-medium text-primary hover:underline"
          >
            {row.original.shop_name}
          </Link>
        ),
      },
      {
        id: "management",
        header: t("hq.payments.shops.col.management"),
        cell: ({ row }) => t(`hq.payments.shops.management.${row.original.management_model}`),
      },
      {
        id: "readiness",
        header: t("hq.payments.shops.col.readiness"),
        cell: ({ row }) => (
          <Badge
            variant={
              row.original.readiness === "ready"
                ? "default"
                : row.original.readiness === "blocked"
                  ? "destructive"
                  : "secondary"
            }
          >
            {t(`hq.payments.shops.readiness.${row.original.readiness}`)}
          </Badge>
        ),
      },
      {
        id: "connection",
        header: t("hq.payments.shops.col.connection"),
        cell: ({ row }) =>
          row.original.connection_display ? (
            <div className="space-y-1">
              <span className="text-xs">{row.original.connection_display}</span>
              {row.original.connection_health ? (
                <ConnectionHealthBadge health={row.original.connection_health} />
              ) : null}
            </div>
          ) : (
            <span className="text-xs text-muted-foreground">—</span>
          ),
      },
      {
        id: "options",
        header: t("hq.payments.shops.col.options"),
        cell: ({ row }) => (
          <span className="tabular-nums text-xs">
            {row.original.options_effective}/{row.original.options_total}
          </span>
        ),
      },
    ],
    [t, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.payments.shops.title")}
        description={t("hq.payments.shops.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={brandSlug} />
        <PaymentsSettingsShell brandSlug={brandSlug}>
          <ListPageToolbar
            search={search}
            onSearchChange={setSearch}
            searchPlaceholder={t("hq.payments.shops.search_placeholder")}
            hasActiveFilters={hasActiveFilters}
            onClearFilters={() => {
              resetFilters();
              setSearch("");
            }}
            isLoading={isLoading && data === undefined}
          >
            <Select value={urlFilters.readiness} onValueChange={(v) => setFilter("readiness", v)}>
              <SelectTrigger className="h-8 w-40 text-xs">
                <SelectValue placeholder={t("hq.payments.shops.col.readiness")} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{t("common.all")}</SelectItem>
                <SelectItem value="ready">{t("hq.payments.shops.readiness.ready")}</SelectItem>
                <SelectItem value="setup_required">
                  {t("hq.payments.shops.readiness.setup_required")}
                </SelectItem>
                <SelectItem value="action_required">
                  {t("hq.payments.shops.readiness.action_required")}
                </SelectItem>
              </SelectContent>
            </Select>
          </ListPageToolbar>

          {isLoading && data === undefined ? (
            <DataTableSkeleton columns={6} />
          ) : (
            <DataTable columns={columns} data={rows} emptyMessage={t("hq.payments.shops.empty")} />
          )}

          <Pagination
            meta={meta}
            page={page}
            onPageChange={setPage}
            perPage={Number(urlFilters.per_page) || 25}
            onPerPageChange={(v) => setFilter("per_page", String(v))}
          />
        </PaymentsSettingsShell>
      </PageContent>
    </>
  );
}
