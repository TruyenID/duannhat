"use client";
import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { EllipsisVertical, Eye } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { useHqCustomers } from "@/hooks/api/use-customers";
import type { Customer } from "@/services/customer-service";
import { HelpPanel } from "@/components/shared/help-panel";
import { BranchFilterSelect } from "../orders/components/branch-filter-select";
import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";

const FILTER_DEFAULTS = {
  search: "",
  branch: "all",
  trashed: "",
  per_page: "25",
};

export default function HqCustomersPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { brandSlug } = useParams<{ brandSlug: string }>();
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

  const branchId = urlFilters.branch;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const apiFilters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      branch_id: branchId === "all" ? undefined : branchId,
      with_trashed: showTrashed,
      page,
      // #1960 — the row-count selector reached the URL but never the request.
      // `<Pagination>` read `urlFilters.per_page` and rendered the chosen value,
      // so the control LOOKED applied while the query still asked for the
      // backend default of 25. Same shape as the `page` bug: state that exists,
      // renders, and is dropped one layer before it matters.
      per_page: Number(urlFilters.per_page) || 25,
    }),
    [debouncedSearch, branchId, showTrashed, page, urlFilters.per_page]
  );
  const { data, isLoading, error, refetch, isFetching } = useHqCustomers(brandSlug, apiFilters);
  const customers = data?.data ?? [];
  const meta = data?.meta;

  const columns: ColumnDef<Customer>[] = [
    {
      id: "stt",
      header: t("hq.products.col.stt"),
      size: 50,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{(meta?.from ?? 1) + row.index}</span>
      ),
    },
    {
      id: "name",
      header: t("common.name"),
      size: 200,
      cell: ({ row }) => (
        <Link
          href={`/hq/${brandSlug}/customers/${row.original.id}`}
          className="font-medium text-primary hover:underline"
        >
          {[row.original.first_name, row.original.last_name].filter(Boolean).join(" ")}
        </Link>
      ),
    },
    {
      // #1712 — danh sách này trải khắp brand, nên mỗi dòng phải tự nói nó
      // thuộc chi nhánh nào; bộ lọc ở toolbar không thay được cột này vì mặc
      // định nó để "Tất cả chi nhánh".
      id: "branch",
      header: t("hq.customers.col.branch"),
      size: 160,
      cell: ({ row }) => <span className="text-sm">{row.original.branch?.name ?? "—"}</span>,
    },
    {
      id: "phone",
      header: t("common.phone"),
      size: 140,
      cell: ({ row }) => <span className="text-sm">{row.original.phone ?? "—"}</span>,
    },
    {
      id: "email",
      header: t("common.email"),
      size: 200,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.email ?? "—"}</span>
      ),
    },
    {
      // #1700 — số dư điểm. Backend tính bằng `withSum` trên sổ cái (không có
      // cột số dư nào cả), và trả 0 chứ không null cho khách chưa có bút toán.
      id: "point_balance",
      header: t("hq.customers.col.points"),
      size: 90,
      cell: ({ row }) => (
        <span className="text-sm tabular-nums">
          {(row.original.point_balance ?? 0).toLocaleString()}
        </span>
      ),
    },
    {
      id: "created_at",
      header: t("common.created"),
      size: 120,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.created_at, locale, timezone)}
        </span>
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
              <Link href={`/hq/${brandSlug}/customers/${row.original.id}`}>
                <Eye className="mr-2 size-3.5" /> {t("common.view")}
              </Link>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("hq.customers.title")}
        description={meta ? `${meta.total} total` : undefined}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("hq.customers.title")}
          subtitle={t("help.panel.hq_customers.subtitle")}
          purpose={t("help.panel.hq_customers.purpose")}
          usage={[t("help.panel.hq_customers.usage.1"), t("help.panel.hq_customers.usage.2")]}
          checks={[t("help.panel.hq_customers.checks.1"), t("help.panel.hq_customers.checks.2")]}
          glossary={[
            {
              term: t("help.panel.hq_customers.glossary.points.term"),
              description: t("help.panel.hq_customers.glossary.points.desc"),
            },
            {
              term: t("help.panel.hq_customers.glossary.branch.term"),
              description: t("help.panel.hq_customers.glossary.branch.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.customers.search_placeholder")}
          showTrashed={showTrashed}
          onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && data === undefined}
        >
          <BranchFilterSelect
            brandSlug={brandSlug}
            value={branchId}
            onValueChange={(v) => {
              setFilter("branch", v);
            }}
          />
        </ListPageToolbar>

        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">
            {t("hq.customers.load_error")}
          </div>
        )}

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={7} />
        ) : (
          <DataTable columns={columns} data={customers} emptyMessage={t("hq.customers.empty")} />
        )}

        <Pagination
          meta={
            meta ?? {
              current_page: 1,
              last_page: 1,
              total: 0,
              per_page: Number(urlFilters.per_page) || 25,
            }
          }
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>
    </>
  );
}
