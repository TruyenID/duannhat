"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { EllipsisVertical, Plus, Trash2, RotateCcw, Pencil, Power } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { StatusBadge } from "@godxjp/ui";
import { Badge } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import {
  useProductTypes,
  useDeleteProductType,
  useRestoreProductType,
  useBulkDeleteProductTypes,
  useToggleProductTypeStatus,
} from "@/hooks/api/use-product-types";
import type { ProductType } from "@/services/product-type-service";
import type { ProductTypeProductForm } from "@/types/models/enum/ProductTypeProductForm";
import { ProductTypeFormDialog } from "./components/product-type-form-dialog";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

type FormFilter = "all" | ProductTypeProductForm;

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  form: "all",
  trashed: "",
  per_page: "25",
};

export default function ProductTypesPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  // URL-synced filter state
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

  const activeFilter = urlFilters.status as "all" | "active" | "inactive";
  const formFilter = urlFilters.form as FormFilter;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  // UI state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<ProductType | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<ProductType | null>(null);

  // Filters -> API params
  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      product_form: formFilter === "all" ? undefined : formFilter,
      with_trashed: showTrashed,
      sort: "-created_at",
    }),
    [page, urlFilters.per_page, debouncedSearch, activeFilter, formFilter, showTrashed]
  );

  const { data: response, isLoading, refetch, isFetching } = useProductTypes(brandSlug, apiFilters);
  const items = useMemo<ProductType[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 100,
    from: null,
    to: null,
  };

  // Reset bulk selection when filters change
  useEffect(() => {
    setSelected(new Set());
  }, [apiFilters]);

  // Mutations
  const deleteOne = useDeleteProductType(brandSlug);
  const restore = useRestoreProductType(brandSlug);
  const bulkDelete = useBulkDeleteProductTypes(brandSlug);
  const toggleStatus = useToggleProductTypeStatus(brandSlug);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    setSelected((prev) => {
      if (prev.size === items.length) return new Set();
      return new Set(items.map((i) => i.id));
    });
  }

  async function handleBulkDelete() {
    const ids = Array.from(selected);
    await bulkDelete.mutateAsync(ids);
    setSelected(new Set());
    setConfirmBulk(false);
  }

  const columns: ColumnDef<ProductType>[] = useMemo(
    () => [
      {
        id: "select",
        size: 36,
        header: () => (
          <Checkbox
            checked={items.length > 0 && selected.size === items.length}
            onCheckedChange={toggleSelectAll}
            aria-label="Select all"
          />
        ),
        cell: ({ row }) => (
          <Checkbox
            checked={selected.has(row.original.id)}
            onCheckedChange={() => toggleSelect(row.original.id)}
            aria-label="Select row"
            disabled={!!row.original.deleted_at}
          />
        ),
      },
      {
        id: "stt",
        header: t("hq.products.col.stt"),
        size: 50,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{(meta.from ?? 1) + row.index}</span>
        ),
      },
      {
        accessorKey: "code",
        header: t("common.code"),
        size: 120,
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{row.original.code || "—"}</code>
        ),
      },
      {
        accessorKey: "name",
        header: t("common.name"),
        size: 280,
        cell: ({ row }) => (
          <div className="flex flex-col gap-0.5">
            <button
              type="button"
              className="text-left font-medium text-primary hover:underline"
              onClick={() => setEditing(row.original)}
            >
              {row.original.name}
            </button>
            {row.original.description && (
              <span className="line-clamp-1 text-xs text-muted-foreground">
                {row.original.description}
              </span>
            )}
          </div>
        ),
      },
      {
        accessorKey: "product_form",
        header: t("hq.product_types.col.form"),
        size: 110,
        cell: ({ row }) => {
          const form = row.original.product_form;
          return (
            <Badge
              variant="outline"
              className={`h-5 px-1.5 text-xs font-medium ${
                form === "physical"
                  ? "border-transparent bg-blue-50 text-blue-700"
                  : "border-transparent bg-green-50 text-green-700"
              }`}
            >
              {form === "physical"
                ? t("hq.product_types.form.physical")
                : t("hq.product_types.form.digital")}
            </Badge>
          );
        },
      },
      {
        id: "has_recipe",
        header: t("hq.product_types.col.recipe"),
        size: 80,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.has_recipe ? t("hq.product_types.col.yes") : "—"}
          </span>
        ),
      },
      {
        id: "inventory",
        header: t("hq.product_types.col.inventory"),
        size: 90,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.is_inventory_tracked ? t("hq.product_types.col.tracked") : "—"}
          </span>
        ),
      },
      {
        id: "status",
        header: t("common.status"),
        size: 100,
        cell: ({ row }) => (
          <StatusBadge
            status={
              row.original.deleted_at ? "deleted" : row.original.is_active ? "active" : "inactive"
            }
          />
        ),
      },
      {
        accessorKey: "updated_at",
        header: t("common.updated"),
        size: 130,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {formatDate(row.original.updated_at, locale, timezone)}
          </span>
        ),
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => {
          const pt = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {pt.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(pt.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem onClick={() => setEditing(pt)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => toggleStatus.mutate(pt.id)}>
                      <Power className="mr-2 size-3.5" />{" "}
                      {pt.is_active ? t("common.deactivate") : t("common.activate")}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(pt)}
                    >
                      <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
                    </DropdownMenuItem>
                  </>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
      },
    ],
    [items, selected, deleteOne, restore, toggleStatus, t, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.product_types.title")}
        description={t("hq.product_types.header.total", { n: meta.total })}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("common.new")}
        </Button>
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.product_types.search_placeholder")}
          showTrashed={showTrashed}
          onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && response === undefined}
          selectedCount={selected.size}
          bulkActions={
            <Button
              variant="destructive"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={() => setConfirmBulk(true)}
            >
              <Trash2 className="size-3.5" />
              {t("common.delete_selected", { n: selected.size })}
            </Button>
          }
        >
          <Select value={formFilter} onValueChange={(v) => setFilter("form", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.product_types.filter.all_forms")}</SelectItem>
              <SelectItem value="physical">{t("hq.product_types.form.physical")}</SelectItem>
              <SelectItem value="digital">{t("hq.product_types.form.digital")}</SelectItem>
            </SelectContent>
          </Select>

          <Select value={activeFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-36 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.product_types.filter.all_status")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={10} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.product_types.empty")} />
        )}

        <Pagination
          meta={meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      {/* Create dialog */}
      <ProductTypeFormDialog
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
        productType={null}
      />

      {/* Edit dialog */}
      <ProductTypeFormDialog
        brandSlug={brandSlug}
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        productType={editing}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle ? t("hq.product_types.delete_confirm", { name: confirmSingle.name }) : ""
        }
        onConfirm={() => {
          if (confirmSingle) {
            const id = confirmSingle.id;
            deleteOne.mutate(id);
            setSelected((prev) => {
              const next = new Set(prev);
              next.delete(id);
              return next;
            });
            setConfirmSingle(null);
          }
        }}
        isPending={deleteOne.isPending}
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        title={t("hq.product_types.bulk_delete_title", { n: selected.size })}
        description={t("hq.product_types.bulk_delete_desc")}
        onConfirm={handleBulkDelete}
        isPending={bulkDelete.isPending}
      />
    </>
  );
}
