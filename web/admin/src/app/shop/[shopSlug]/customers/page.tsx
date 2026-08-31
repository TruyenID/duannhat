"use client";
import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { Plus } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { useCustomers, useDeleteCustomer, useRestoreCustomer } from "@/hooks/api/use-customers";
import { CustomerListTable } from "./components/customer-list-table";
import { buttonVariants } from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { useTranslation } from "@/providers/app-provider";

const FILTER_DEFAULTS = {
  search: "",
  trashed: "",
  per_page: "25",
};

export default function CustomersPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
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
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]);

  const showTrashed = urlFilters.trashed === "1";
  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const apiFilters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      page,
      per_page: Number(urlFilters.per_page) || 25,
      with_trashed: showTrashed,
    }),
    [debouncedSearch, page, urlFilters.per_page, showTrashed]
  );

  const { data, isLoading, refetch, isFetching } = useCustomers(shopSlug, apiFilters);
  const deleteMutation = useDeleteCustomer(shopSlug);
  const restoreMutation = useRestoreCustomer(shopSlug);

  const [confirmDeleteId, setConfirmDeleteId] = useState<string | null>(null);

  const customers = data?.data ?? [];
  const meta = data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: null,
    to: null,
  };

  return (
    <>
      <PageHeader
        title={t("shop.customers.title")}
        description={`${meta.total} total`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Link
          href={`/shop/${shopSlug}/customers/new`}
          className={buttonVariants({ size: "sm" }) + " h-7 gap-1 text-xs"}
        >
          <Plus className="size-3.5" />
          {t("shop.customers.new")}
        </Link>
        <HelpPanel
          title={t("shop.customers.title")}
          subtitle={t("help.panel.shop_customers.subtitle")}
          purpose={t("help.panel.shop_customers.purpose")}
          usage={[t("help.panel.shop_customers.usage.1"), t("help.panel.shop_customers.usage.2")]}
          checks={[
            t("help.panel.shop_customers.checks.1"),
            t("help.panel.shop_customers.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_customers.glossary.points.term"),
              description: t("help.panel.shop_customers.glossary.points.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("shop.customers.search_placeholder")}
          showTrashed={showTrashed}
          onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && data === undefined}
        />

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={7} />
        ) : (
          <CustomerListTable
            data={customers}
            shopSlug={shopSlug}
            fromIndex={meta.from ?? 1}
            emptyMessage={
              debouncedSearch ? t("shop.customers.empty_search") : t("shop.customers.empty")
            }
            onDelete={(id) => setConfirmDeleteId(id)}
            onRestore={(id) => restoreMutation.mutate(id)}
          />
        )}

        <Pagination
          meta={meta}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <DeleteConfirmDialog
        open={!!confirmDeleteId}
        onOpenChange={(open) => {
          if (!open) setConfirmDeleteId(null);
        }}
        description={t("shop.customers.delete_confirm")}
        onConfirm={() => {
          if (confirmDeleteId) {
            deleteMutation.mutate(confirmDeleteId);
            setConfirmDeleteId(null);
          }
        }}
        isPending={deleteMutation.isPending}
      />
    </>
  );
}
