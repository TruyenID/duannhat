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
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@godxjp/ui";
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
  useTaxTypes,
  useDeleteTaxType,
  useRestoreTaxType,
  useBulkDeleteTaxTypes,
  useToggleTaxTypeStatus,
} from "@/hooks/api/use-tax-types";
import type { TaxType, TaxTypeInUseMeta } from "@/services/tax-type-service";
import { ApiError } from "@/lib/api";
import { TaxTypeFormDialog } from "./components/tax-type-form-dialog";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  trashed: "",
  per_page: "25",
};

export default function TaxTypesPage() {
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
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  // UI state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<TaxType | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<TaxType | null>(null);
  // 409 TAX_TYPE_IN_USE — surfaces usage counts + "Deactivate instead".
  const [inUse, setInUse] = useState<{ taxType: TaxType; meta: TaxTypeInUseMeta } | null>(null);

  // Filters -> API params
  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      with_trashed: showTrashed,
      sort: "-created_at",
    }),
    [page, urlFilters.per_page, debouncedSearch, activeFilter, showTrashed]
  );

  const { data: response, isLoading, refetch, isFetching } = useTaxTypes(brandSlug, apiFilters);
  const items = useMemo<TaxType[]>(() => response?.data ?? [], [response]);
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
  const deleteOne = useDeleteTaxType(brandSlug);
  const restore = useRestoreTaxType(brandSlug);
  const bulkDelete = useBulkDeleteTaxTypes(brandSlug);
  const toggleStatus = useToggleTaxTypeStatus(brandSlug);

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

  // Single delete goes through mutateAsync so we can intercept the 409
  // TAX_TYPE_IN_USE and swap the confirm dialog for the usage breakdown.
  async function handleConfirmSingle() {
    if (!confirmSingle) return;
    const target = confirmSingle;
    setConfirmSingle(null);
    try {
      await deleteOne.mutateAsync(target.id);
      setSelected((prev) => {
        const next = new Set(prev);
        next.delete(target.id);
        return next;
      });
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        const body = err.body as { code?: string; meta?: TaxTypeInUseMeta };
        if (body?.code === "TAX_TYPE_IN_USE" && body.meta) {
          setInUse({ taxType: target, meta: body.meta });
        }
      }
      // Other errors already surfaced via the hook's onError toast.
    }
  }

  const columns: ColumnDef<TaxType>[] = useMemo(
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
        size: 240,
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="text-left font-medium text-primary hover:underline"
              onClick={() => setEditing(row.original)}
            >
              {row.original.name}
            </button>
            {row.original.is_default && (
              <Badge
                variant="outline"
                className="h-5 border-transparent bg-blue-50 px-1.5 text-xs font-medium text-blue-700"
              >
                {t("hq.tax_types.col.default")}
              </Badge>
            )}
          </div>
        ),
      },
      {
        id: "rates",
        header: t("hq.tax_types.col.rates"),
        size: 180,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground tabular-nums">
            {t("hq.tax_types.rate_display", { rate: String(row.original.rate) })}
          </span>
        ),
      },
      {
        id: "products",
        header: t("hq.tax_types.col.products"),
        size: 90,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground tabular-nums">
            {row.original.products_count ?? 0}
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
          const tt = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {tt.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(tt.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem onClick={() => setEditing(tt)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => toggleStatus.mutate(tt.id)}>
                      <Power className="mr-2 size-3.5" />{" "}
                      {tt.is_active ? t("common.deactivate") : t("common.activate")}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(tt)}
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

  // Whether a default already exists among loaded rows — feeds the dialog's
  // "replaces current default" warning.
  const hasExistingDefault = items.some((i) => i.is_default && !i.deleted_at);

  return (
    <>
      <PageHeader
        title={t("hq.tax_types.title")}
        description={t("hq.tax_types.header.total", { n: meta.total })}
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
          searchPlaceholder={t("hq.tax_types.search_placeholder")}
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
          <Select value={activeFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-36 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.tax_types.filter.all_status")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={9} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.tax_types.empty")} />
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
      <TaxTypeFormDialog
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
        taxType={null}
        hasExistingDefault={hasExistingDefault}
      />

      {/* Edit dialog */}
      <TaxTypeFormDialog
        brandSlug={brandSlug}
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        taxType={editing}
        hasExistingDefault={hasExistingDefault}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle ? t("hq.tax_types.delete_confirm", { name: confirmSingle.name }) : ""
        }
        onConfirm={handleConfirmSingle}
        isPending={deleteOne.isPending}
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        title={t("hq.tax_types.bulk_delete_title", { n: selected.size })}
        description={t("hq.tax_types.bulk_delete_desc")}
        onConfirm={handleBulkDelete}
        isPending={bulkDelete.isPending}
      />

      {/* 409 TAX_TYPE_IN_USE — show usage breakdown + deactivate-instead exit. */}
      <AlertDialog open={!!inUse} onOpenChange={(o) => !o && setInUse(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.tax_types.in_use.title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {inUse ? t("hq.tax_types.in_use.description", { name: inUse.taxType.name }) : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          {inUse && (
            <ul className="space-y-1.5 rounded-md border bg-muted/40 p-3 text-sm">
              <li className="flex items-center justify-between">
                <span className="text-muted-foreground">{t("hq.tax_types.in_use.products")}</span>
                <span className="font-medium tabular-nums">{inUse.meta.products}</span>
              </li>
              <li className="flex items-center justify-between">
                <span className="text-muted-foreground">
                  {t("hq.tax_types.in_use.menu_products")}
                </span>
                <span className="font-medium tabular-nums">{inUse.meta.menu_products}</span>
              </li>
              <li className="flex items-center justify-between">
                <span className="text-muted-foreground">
                  {t("hq.tax_types.in_use.branch_defaults")}
                </span>
                <span className="font-medium tabular-nums">{inUse.meta.branch_defaults}</span>
              </li>
              {(inUse.meta.brand_default ?? 0) > 0 && (
                <li className="flex items-center justify-between">
                  <span className="text-muted-foreground">
                    {t("hq.tax_types.in_use.brand_default")}
                  </span>
                  <span className="font-medium tabular-nums">{inUse.meta.brand_default}</span>
                </li>
              )}
            </ul>
          )}
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (inUse) toggleStatus.mutate(inUse.taxType.id);
                setInUse(null);
              }}
            >
              {t("hq.tax_types.in_use.deactivate_instead")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
