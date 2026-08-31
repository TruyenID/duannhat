"use client";
import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { Ban, EllipsisVertical, Eye, Plus } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { VoidOrderDialog, canVoidOrder } from "@/components/shared/void-order-dialog";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useOrders, useVoidOrder } from "@/hooks/api/use-orders";
import type {
  CustomerOrder,
  CustomerOrderStatus,
  CustomerOrderType,
} from "@/services/order-service";
import { OrderStatusBadge } from "@/components/shared/order-status-badge";
import { HelpPanel } from "@/components/shared/help-panel";
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
  Spinner,
} from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import { formatCurrency } from "@/lib/currency";

// Every backend CustomerOrderStatusEnum case, in lifecycle order. The filter
// used to offer `completed` / `cancelled`, which are not enum values at all —
// picking either sent an unknown status to the API and always returned zero
// rows. Labels reuse the same `shop.orders.status.*` keys as the badge.
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
  type: "all",
  date_from: "",
  date_to: "",
  per_page: "25",
};

// Dates are stored in the URL as `yyyy-MM-dd` strings — pure-string state
// is what `useSearchFilters` expects, and the format round-trips cleanly
// through the API (no TZ ambiguity at midnight).
function parseDate(s: string): Date | undefined {
  if (!s) return undefined;
  const d = new Date(`${s}T00:00:00`);
  return Number.isNaN(d.getTime()) ? undefined : d;
}

function formatDate(d: Date | undefined): string {
  if (!d) return "";
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

export default function OrdersPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const voidMut = useVoidOrder(shopSlug);
  const [voidTarget, setVoidTarget] = useState<CustomerOrder | null>(null);

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
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]);

  const status = urlFilters.status as CustomerOrderStatus | "all";
  const orderType = urlFilters.type as CustomerOrderType | "all";
  const dateFrom = parseDate(urlFilters.date_from);
  const dateTo = parseDate(urlFilters.date_to);
  const perPage = Number(urlFilters.per_page) || 25;

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const filters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      status: status === "all" ? undefined : status,
      order_type: orderType === "all" ? undefined : orderType,
      date_from: urlFilters.date_from || undefined,
      date_to: urlFilters.date_to || undefined,
      page,
      per_page: perPage,
    }),
    [debouncedSearch, status, orderType, urlFilters.date_from, urlFilters.date_to, page, perPage]
  );

  // Poll the order list (paginated, so bounded). Dine-in orders are closed by
  // the kiosk/customer-web when fully paid, so admin-web must refresh to
  // reflect the → "Hoàn thành" transition without a manual refresh (previously
  // only the pending/confirmed views polled).
  const { data, isLoading, error, refetch, isFetching } = useOrders(shopSlug, filters, {
    refetchInterval: 15_000,
  });
  const orders = data?.data ?? [];
  const meta = data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: perPage,
    from: null,
    to: null,
  };

  const columns: ColumnDef<CustomerOrder>[] = [
    {
      id: "order_code",
      header: t("shop.orders.col.code"),
      size: 140,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/orders/${row.original.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.order_code}
        </Link>
      ),
    },
    {
      id: "status",
      header: t("common.status"),
      size: 110,
      cell: ({ row }) => <OrderStatusBadge status={row.original.status} />,
    },
    {
      id: "type",
      header: t("shop.orders.col.type"),
      size: 90,
      cell: ({ row }) => (
        <span className="text-xs capitalize">{row.original.order_type.replace("_", " ")}</span>
      ),
    },
    {
      id: "customer",
      header: t("shop.orders.col.customer"),
      size: 160,
      cell: ({ row }) => {
        const c = row.original.customer;
        if (!c) return <span className="text-muted-foreground">{t("common.walk_in")}</span>;
        return (
          <span className="text-sm">{[c.first_name, c.last_name].filter(Boolean).join(" ")}</span>
        );
      },
    },
    {
      id: "total",
      header: t("shop.orders.col.total"),
      size: 100,
      cell: ({ row }) => (
        <span className="tabular-nums">
          {formatCurrency(row.original.total_amount, locale, row.original.currency)}
        </span>
      ),
    },
    {
      id: "created_at",
      header: t("common.created"),
      size: 120,
      cell: ({ row }) => formatDateTime(row.original.created_at, locale, timezone),
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
              <DropdownMenuItem onClick={() => router.push(`/shop/${shopSlug}/orders/${order.id}`)}>
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
      <PageHeader title={t("shop.orders.title")} onRefresh={refetch} isRefreshing={isFetching}>
        <Button size="sm" asChild>
          <Link href={`/shop/${shopSlug}/orders/new`}>
            <Plus className="mr-1.5 size-3.5" />
            {t("shop.orders.new")}
          </Link>
        </Button>
        <HelpPanel
          title={t("shop.orders.title")}
          subtitle={t("help.panel.shop_orders.subtitle")}
          purpose={t("help.panel.shop_orders.purpose")}
          usage={[
            t("help.panel.shop_orders.usage.1"),
            t("help.panel.shop_orders.usage.2"),
            t("help.panel.shop_orders.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_orders.checks.1"),
            t("help.panel.shop_orders.checks.2"),
            t("help.panel.shop_orders.checks.3"),
            t("help.panel.shop_orders.checks.4"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_orders.glossary.status_flow.term"),
              description: t("help.panel.shop_orders.glossary.status_flow.desc"),
            },
            {
              term: t("help.panel.shop_orders.glossary.walk_in.term"),
              description: t("help.panel.shop_orders.glossary.walk_in.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("shop.orders.search_placeholder")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && data === undefined}
        >
          <Select value={status} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all_statuses")}</SelectItem>
              {ORDER_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`shop.orders.status.${s}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={orderType} onValueChange={(v) => setFilter("type", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all_types")}</SelectItem>
              <SelectItem value="dine_in">{t("shop.orders.filter.dine_in")}</SelectItem>
              <SelectItem value="takeaway">{t("shop.orders.filter.takeaway")}</SelectItem>
            </SelectContent>
          </Select>
          <DatePicker
            value={dateFrom}
            onChange={(d) => setFilter("date_from", formatDate(d))}
            placeholder="From"
            className="h-8 w-35 text-xs"
          />
          <DatePicker
            value={dateTo}
            onChange={(d) => setFilter("date_to", formatDate(d))}
            placeholder="To"
            className="h-8 w-35 text-xs"
          />
        </ListPageToolbar>
        {isLoading && data === undefined && (
          <div className="flex items-center justify-center py-12">
            <Spinner className="size-5" />
            <span className="ml-2 text-sm text-muted-foreground">{t("common.loading")}</span>
          </div>
        )}
        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">
            {t("shop.orders.load_error")}
          </div>
        )}
        {!isLoading && !error && (
          <DataTable
            columns={columns}
            data={orders}
            emptyMessage={
              status === "all" ? t("shop.orders.empty") : t("shop.orders.empty_status", { status })
            }
          />
        )}

        <Pagination
          meta={meta}
          page={page}
          onPageChange={setPage}
          perPage={perPage}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
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
