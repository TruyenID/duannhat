"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Plus } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";

import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { Pagination } from "@/components/shared/pagination";
import { useRecalls } from "@/hooks/api/use-recalls";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type { Recall, RecallStatus } from "@/services/recall-service";
import {
  Button,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  StatusBadge,
} from "@godxjp/ui";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  per_page: "25",
};

export default function HqRecallsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;

  // Filter state synced to the URL (reload keeps the active filter).
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

  const statusFilter = urlFilters.status as "all" | RecallStatus;

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      status: statusFilter === "all" ? undefined : statusFilter,
    }),
    [page, urlFilters.per_page, debouncedSearch, statusFilter]
  );

  const { data, isLoading, isFetching, refetch } = useRecalls(brandSlug, apiFilters);
  const items = useMemo<Recall[]>(() => data?.data ?? [], [data]);
  const meta = data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: 0,
    to: 0,
  };

  const columns: ColumnDef<Recall>[] = [
    {
      id: "stt",
      header: t("hq.products.col.stt"),
      size: 50,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{(meta.from ?? 1) + row.index}</span>
      ),
    },
    {
      accessorKey: "recall_code",
      header: t("recall.col_code"),
      cell: ({ row }) => (
        <Link
          href={`/hq/${brandSlug}/recalls/${row.original.id}`}
          className="font-medium text-primary hover:underline"
        >
          {row.original.recall_code}
        </Link>
      ),
    },
    {
      accessorKey: "root_lot",
      header: t("recall.col_root_lot"),
      cell: ({ row }) => row.original.root_lot?.lot_code ?? row.original.root_lot_id,
    },
    {
      accessorKey: "reason",
      header: t("recall.col_reason"),
      cell: ({ row }) => (
        <span className="line-clamp-2 max-w-md text-xs">{row.original.reason}</span>
      ),
    },
    {
      accessorKey: "affected_lots_count",
      header: t("recall.col_affected_lots"),
      cell: ({ row }) => <span className="tabular-nums">{row.original.affected_lots_count}</span>,
    },
    {
      accessorKey: "affected_orders_count",
      header: t("recall.col_affected_orders"),
      cell: ({ row }) => <span className="tabular-nums">{row.original.affected_orders_count}</span>,
    },
    {
      accessorKey: "status",
      header: t("recall.col_status"),
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      accessorKey: "initiated_at",
      header: t("recall.col_initiated_at"),
      cell: ({ row }) =>
        row.original.initiated_at ? (
          <span className="text-xs text-muted-foreground">
            {formatDateTime(row.original.initiated_at, locale, timezone)}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("recall.list_title")}
        description={t("recall.list_subtitle")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Link href={`/hq/${brandSlug}/recalls/new`}>
          <Button>
            <Plus className="mr-2 size-4" />
            {t("recall.new_button")}
          </Button>
        </Link>
      </PageHeader>
      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("recall.search_placeholder")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && data === undefined}
        >
          <Select value={statusFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all")}</SelectItem>
              <SelectItem value="draft">{t("recall.status.draft")}</SelectItem>
              <SelectItem value="active">{t("recall.status.active")}</SelectItem>
              <SelectItem value="completed">{t("recall.status.completed")}</SelectItem>
              <SelectItem value="cancelled">{t("recall.status.cancelled")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={columns.length} rows={6} />
        ) : (
          <DataTable columns={columns} data={items} />
        )}

        <Pagination
          meta={meta}
          page={page}
          onPageChange={setPage}
          rowsPerPageOptions={[10, 25, 50]}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>
    </>
  );
}
