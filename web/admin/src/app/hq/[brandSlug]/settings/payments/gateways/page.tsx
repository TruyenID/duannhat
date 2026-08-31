"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { EllipsisVertical, Plus } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { SettingsTabsNav } from "../../components/settings-tabs-nav";
import { ConnectionHealthBadge } from "../components/connection-health-badge";
import { PaymentsSettingsShell } from "../components/payments-settings-nav";
import { usePaymentGatewayConnections } from "@/hooks/api/use-payment-gateways";
import type { PaymentGatewayConnectionSummary } from "@/services/payment-gateway-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { getPaymentGatewayEnvironmentLabel } from "@/types/models/enum/PaymentGatewayEnvironment";
import type { PaymentGatewayEnvironment } from "@/types/models/enum/PaymentGatewayEnvironment";

const FILTER_DEFAULTS = {
  search: "",
  health: "all",
  environment: "all",
  per_page: "25",
};

export default function HqPaymentGatewaysPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

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
      health: urlFilters.health === "all" ? undefined : urlFilters.health,
      environment: urlFilters.environment === "all" ? undefined : urlFilters.environment,
    }),
    [page, urlFilters, debouncedSearch]
  );

  const { data, isLoading, refetch, isFetching } = usePaymentGatewayConnections(
    brandSlug,
    apiFilters
  );
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

  const columns: ColumnDef<PaymentGatewayConnectionSummary>[] = useMemo(
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
        id: "provider",
        header: t("hq.payments.field.provider"),
        cell: ({ row }) => (
          <Link
            href={`/hq/${brandSlug}/settings/payments/gateways/${row.original.id}`}
            className="font-medium text-primary hover:underline"
          >
            {row.original.provider.name}
          </Link>
        ),
      },
      {
        id: "environment",
        header: t("hq.payments.field.environment"),
        cell: ({ row }) =>
          getPaymentGatewayEnvironmentLabel(
            row.original.environment as PaymentGatewayEnvironment,
            locale
          ),
      },
      {
        id: "merchant",
        header: t("hq.payments.field.merchant_account"),
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
            {row.original.merchant_account_id}
          </code>
        ),
      },
      {
        id: "health",
        header: t("common.status"),
        cell: ({ row }) => <ConnectionHealthBadge health={row.original.health} />,
      },
      {
        id: "validated",
        header: t("hq.payments.col.validated"),
        cell: ({ row }) =>
          row.original.last_validated_at ? (
            <span className="text-xs text-muted-foreground">
              {formatDateTime(row.original.last_validated_at, locale, timezone)}
            </span>
          ) : (
            <span className="text-xs text-muted-foreground">—</span>
          ),
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-7">
                <EllipsisVertical className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem asChild>
                <Link href={`/hq/${brandSlug}/settings/payments/gateways/${row.original.id}`}>
                  {t("common.view")}
                </Link>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        ),
      },
    ],
    [t, brandSlug, locale, timezone, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.payments.gateways.title")}
        description={t("hq.payments.gateways.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1.5 text-xs" asChild>
          <Link href={`/hq/${brandSlug}/settings/payments/gateways/new`}>
            <Plus className="size-3.5" />
            {t("hq.payments.gateways.connect")}
          </Link>
        </Button>
      </PageHeader>

      <PageContent>
        <SettingsTabsNav brandSlug={brandSlug} />
        <PaymentsSettingsShell brandSlug={brandSlug}>
          <ListPageToolbar
            search={search}
            onSearchChange={setSearch}
            searchPlaceholder={t("hq.payments.gateways.search_placeholder")}
            hasActiveFilters={hasActiveFilters}
            onClearFilters={() => {
              resetFilters();
              setSearch("");
            }}
            isLoading={isLoading && data === undefined}
          >
            <Select value={urlFilters.health} onValueChange={(v) => setFilter("health", v)}>
              <SelectTrigger className="h-8 w-40 text-xs">
                <SelectValue placeholder={t("hq.payments.filter.health")} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{t("common.all")}</SelectItem>
                <SelectItem value="ready">{t("hq.payments.health.ready")}</SelectItem>
                <SelectItem value="pending_verification">
                  {t("hq.payments.health.pending")}
                </SelectItem>
                <SelectItem value="restricted">{t("hq.payments.health.restricted")}</SelectItem>
              </SelectContent>
            </Select>
            <Select
              value={urlFilters.environment}
              onValueChange={(v) => setFilter("environment", v)}
            >
              <SelectTrigger className="h-8 w-36 text-xs">
                <SelectValue placeholder={t("hq.payments.field.environment")} />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{t("common.all")}</SelectItem>
                <SelectItem value="sandbox">{t("hq.payments.env.sandbox")}</SelectItem>
                <SelectItem value="live">{t("hq.payments.env.live")}</SelectItem>
              </SelectContent>
            </Select>
          </ListPageToolbar>

          {isLoading && data === undefined ? (
            <DataTableSkeleton columns={6} />
          ) : (
            <DataTable columns={columns} data={rows} emptyMessage={t("hq.payments.gateways.empty")} />
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
