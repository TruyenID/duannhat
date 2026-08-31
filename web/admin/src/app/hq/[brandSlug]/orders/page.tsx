"use client";
import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { format } from "date-fns";
import { Ban, EllipsisVertical, Eye } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination, type PaginationMeta } from "@/components/shared/pagination";
import { VoidOrderDialog, canVoidOrder } from "@/components/shared/void-order-dialog";
import { OrderStatusBadge } from "@/components/shared/order-status-badge";
import { HelpPanel } from "@/components/shared/help-panel";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { useHqOrders, useHqVoidOrder } from "@/hooks/api/use-orders";
import type {
  CustomerOrder,
  CustomerOrderStatus,
  CustomerOrderType,
} from "@/services/order-service";
import { BranchFilterSelect } from "./components/branch-filter-select";
import { MixedCurrencyStatCard, RevenueStatCard } from "./components/revenue-stat-card";
import {
  Button,
  DatePicker,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import { formatCurrency } from "@/lib/currency";

// Every backend CustomerOrderStatusEnum case, in lifecycle order. The filter
// used to omit awaiting_confirmation / confirmed / expired, so takeaway
// counter-pay orders in those states could not be filtered for at all.
const ORDER_STATUSES: CustomerOrderStatus[] = [
  "pending",
  "awaiting_confirmation",
  "confirmed",
  "open",
  "dining",
  "checkout",
  "paying",
  "closed",
  "voided",
  "expired",
];

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  order_type: "all",
  branch_id: "all",
  date_from: "",
  date_to: "",
  per_page: "25",
};

export default function HqOrdersPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const router = useRouter();
  const voidMut = useHqVoidOrder(brandSlug);
  const [voidTarget, setVoidTarget] = useState<CustomerOrder | null>(null);

  const { filters, page, setFilter, setPage, resetFilters } = useSearchFilters(FILTER_DEFAULTS);

  const [search, setSearch] = useState(filters.search);
  const debouncedSearch = useDebounce(search, 300);

  useEffect(() => {
    if (debouncedSearch !== filters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

  const perPage = Number(filters.per_page) || 25;

  const [dateFrom, setDateFrom] = useState<Date | undefined>(() =>
    filters.date_from ? new Date(filters.date_from) : undefined
  );
  const [dateTo, setDateTo] = useState<Date | undefined>(() =>
    filters.date_to ? new Date(filters.date_to) : undefined
  );

  const hasActiveFilters = Object.entries(filters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const apiFilters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      branch_id: filters.branch_id === "all" ? undefined : filters.branch_id || undefined,
      status: (filters.status === "all" ? undefined : filters.status) as
        CustomerOrderStatus | undefined,
      order_type: (filters.order_type === "all" ? undefined : filters.order_type) as
        CustomerOrderType | undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      page,
      per_page: perPage,
    }),
    [
      debouncedSearch,
      filters.branch_id,
      filters.status,
      filters.order_type,
      filters.date_from,
      filters.date_to,
      page,
      perPage,
    ]
  );

  const { data, isLoading, error, refetch, isFetching } = useHqOrders(brandSlug, apiFilters);
  const orders = data?.data ?? [];
  const meta: PaginationMeta = data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: perPage,
    from: null,
    to: null,
  };
  const agg = data?.aggregate;

  // #1961 — the aggregate's currency comes from the SERVER, and it may say
  // "more than one".
  //
  // This used to read `orders[0]?.currency` with a comment assuming "the list
  // is normally scoped to one branch". The branch filter defaults to ALL
  // branches, so on a brand running both a Hanoi and a Tokyo shop the default
  // view labelled a VND+JPY sum with whichever symbol the first row happened to
  // carry. The number itself was already meaningless — the backend sums
  // `total_amount` across currencies — so printing a confident symbol on it was
  // the worse half of the bug, not the whole of it.
  //
  // The page cannot decide this locally: it holds ONE PAGE of orders while the
  // aggregate covers the whole filtered set, so a page of VND rows says nothing
  // about JPY rows further in.
  const aggCurrencies = agg?.currencies ?? [];
  const aggCurrency = aggCurrencies.length === 1 ? aggCurrencies[0] : undefined;
  const aggMixedCurrency = aggCurrencies.length > 1;

  const columns: ColumnDef<CustomerOrder>[] = [
    {
      id: "stt",
      header: t("hq.products.col.stt"),
      size: 50,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{(meta.from ?? 1) + row.index}</span>
      ),
    },
    {
      id: "order_code",
      header: t("hq.orders.col.code"),
      size: 140,
      cell: ({ row }) => (
        <Link
          href={`/hq/${brandSlug}/orders/${row.original.id}`}
          className="font-medium text-primary hover:underline"
        >
          {row.original.order_code}
        </Link>
      ),
    },
    {
      id: "order_type",
      header: t("hq.orders.col.type"),
      size: 100,
      cell: ({ row }) =>
        row.original.order_type === "dine_in"
          ? t("hq.orders.type.dine_in")
          : t("hq.orders.type.takeaway"),
    },
    {
      id: "customer",
      header: t("hq.orders.col.customer"),
      size: 160,
      cell: ({ row }) => {
        const c = row.original.customer;
        return c ? [c.first_name, c.last_name].filter(Boolean).join(" ") : t("common.walk_in");
      },
    },
    {
      id: "status",
      header: t("common.status"),
      size: 110,
      cell: ({ row }) => <OrderStatusBadge status={row.original.status} />,
    },
    {
      id: "total",
      header: t("hq.orders.col.total"),
      size: 100,
      cell: ({ row }) => (
        <span className="text-sm tabular-nums">
          {formatCurrency(row.original.total_amount, locale, row.original.currency)}
        </span>
      ),
    },
    {
      id: "created_at",
      header: t("common.date"),
      size: 140,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDateTime(row.original.created_at, locale, timezone)}
        </span>
      ),
    },
    {
      id: "actions",
      header: t("common.action"),
      size: 50,
      cell: ({ row }) => {
        const order = row.original;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-7">
                <EllipsisVertical className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => router.push(`/hq/${brandSlug}/orders/${order.id}`)}>
                <Eye className="mr-2 size-3.5" /> {t("common.view")}
              </DropdownMenuItem>
              {canVoidOrder(order.status) && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    className="text-destructive"
                    onClick={() => setVoidTarget(order)}
                  >
                    <Ban className="mr-2 size-3.5" /> {t("common.cancel")}
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader
        title={t("hq.orders.title")}
        description={meta.total > 0 ? t("common.total_items", { n: meta.total }) : undefined}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("hq.orders.title")}
          subtitle={t("help.panel.hq_orders.subtitle")}
          purpose={t("help.panel.hq_orders.purpose")}
          usage={[
            t("help.panel.hq_orders.usage.1"),
            t("help.panel.hq_orders.usage.2"),
            t("help.panel.hq_orders.usage.3"),
          ]}
          checks={[
            t("help.panel.hq_orders.checks.1"),
            t("help.panel.hq_orders.checks.2"),
            t("help.panel.hq_orders.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.hq_orders.glossary.total.term"),
              description: t("help.panel.hq_orders.glossary.total.desc"),
            },
            {
              term: t("help.panel.hq_orders.glossary.walk_in.term"),
              description: t("help.panel.hq_orders.glossary.walk_in.desc"),
            },
            {
              term: t("help.panel.hq_orders.glossary.status_flow.term"),
              description: t("help.panel.hq_orders.glossary.status_flow.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        {agg && (
          <div className="mb-4 grid grid-cols-3 gap-3">
            {aggMixedCurrency ? (
              <MixedCurrencyStatCard
                label={t("hq.orders.stat.total_revenue")}
                currencies={aggCurrencies}
                hint={t("hq.orders.stat.mixed_currency_hint")}
              />
            ) : (
              <RevenueStatCard
                label={t("hq.orders.stat.total_revenue")}
                value={agg.total_revenue}
                locale={locale}
                currencyCode={aggCurrency}
              />
            )}
            <RevenueStatCard
              label={t("hq.orders.stat.order_count")}
              value={agg.order_count}
              format="number"
            />
            {aggMixedCurrency ? (
              <MixedCurrencyStatCard
                label={t("hq.orders.stat.avg_order_value")}
                currencies={aggCurrencies}
                hint={t("hq.orders.stat.mixed_currency_hint")}
              />
            ) : (
              <RevenueStatCard
                label={t("hq.orders.stat.avg_order_value")}
                value={agg.avg_order_value}
                locale={locale}
                currencyCode={aggCurrency}
              />
            )}
          </div>
        )}

        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.orders.search_placeholder")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
            setDateFrom(undefined);
            setDateTo(undefined);
          }}
          isLoading={isLoading && data === undefined}
        >
          <Select value={filters.status} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-44 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all_statuses")}</SelectItem>
              {ORDER_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`hq.orders.status.${s}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={filters.order_type} onValueChange={(v) => setFilter("order_type", v)}>
            <SelectTrigger className="h-8 w-36 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all_types")}</SelectItem>
              <SelectItem value="dine_in">{t("hq.orders.type.dine_in")}</SelectItem>
              <SelectItem value="takeaway">{t("hq.orders.type.takeaway")}</SelectItem>
            </SelectContent>
          </Select>

          <BranchFilterSelect
            brandSlug={brandSlug}
            value={filters.branch_id}
            onValueChange={(v) => setFilter("branch_id", v)}
          />

          <DatePicker
            value={dateFrom}
            onChange={(d) => {
              setDateFrom(d);
              setFilter("date_from", d ? format(d, "yyyy-MM-dd") : "");
            }}
            placeholder={t("hq.orders.filter.date_from")}
            className="h-8 w-35 text-xs"
          />
          <DatePicker
            value={dateTo}
            onChange={(d) => {
              setDateTo(d);
              setFilter("date_to", d ? format(d, "yyyy-MM-dd") : "");
            }}
            placeholder={t("hq.orders.filter.date_to")}
            className="h-8 w-35 text-xs"
          />
        </ListPageToolbar>

        {isLoading && data === undefined ? (
          <DataTableSkeleton />
        ) : error ? (
          <div className="py-12 text-center text-sm text-red-500">{t("hq.orders.load_error")}</div>
        ) : (
          <>
            <DataTable columns={columns} data={orders} emptyMessage={t("hq.orders.empty")} />
            <Pagination
              meta={meta}
              page={page}
              onPageChange={setPage}
              perPage={perPage}
              onPerPageChange={(v) => {
                setFilter("per_page", String(v));
                setPage(1);
              }}
            />
          </>
        )}
      </PageContent>

      <VoidOrderDialog
        orderCode={voidTarget?.order_code ?? null}
        onOpenChange={(open) => {
          if (!open) setVoidTarget(null);
        }}
        isPending={voidMut.isPending}
        onConfirm={(voidReason) => {
          if (!voidTarget) return;
          voidMut.mutate(
            { id: voidTarget.id, voidReason },
            { onSuccess: () => setVoidTarget(null) }
          );
        }}
      />
    </>
  );
}
