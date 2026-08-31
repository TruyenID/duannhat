"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import Link from "next/link";
import {
  ArrowUpRight,
  EllipsisVertical,
  ExternalLink,
  Pause,
  Play,
  Plus,
  RotateCcw,
  Store,
  Trash2,
} from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import {
  Button,
  Checkbox,
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
  StatusBadge,
} from "@godxjp/ui";
import {
  useShops,
  useDeleteShop,
  useBulkDeleteShops,
  useToggleShopStatus,
  useRestoreShop,
} from "@/hooks/api/use-shops";
import type { ShopListItem } from "@/services/shop-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { formatDate } from "@/lib/date";
import { CreateShopDialog } from "./components/create-shop-dialog";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  trashed: "",
  per_page: "25",
};

export default function ShopsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const router = useRouter();

  const [createOpen, setCreateOpen] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [confirmSingle, setConfirmSingle] = useState<ShopListItem | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);

  const deleteOne = useDeleteShop(brandSlug);
  const bulkDelete = useBulkDeleteShops(brandSlug);
  const toggleStatus = useToggleShopStatus(brandSlug);
  const restore = useRestoreShop(brandSlug);

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

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      with_trashed: showTrashed,
    }),
    [page, urlFilters.per_page, debouncedSearch, activeFilter, showTrashed]
  );

  const {
    data: response,
    isLoading,
    isError,
    error,
    refetch,
    isFetching,
  } = useShops(brandSlug, apiFilters);
  const items = useMemo<ShopListItem[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
    from: null,
    to: null,
  };

  useEffect(() => {
    setSelected(new Set());
  }, [apiFilters]);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    const selectable = items.filter((i) => !i.deleted_at);
    setSelected((prev) =>
      prev.size === selectable.length ? new Set() : new Set(selectable.map((i) => i.id))
    );
  }

  const columns: ColumnDef<ShopListItem>[] = useMemo(
    () => [
      {
        id: "select",
        size: 36,
        header: () => {
          const selectable = items.filter((i) => !i.deleted_at);
          return (
            <Checkbox
              checked={selectable.length > 0 && selected.size === selectable.length}
              onCheckedChange={toggleSelectAll}
              aria-label="Select all"
            />
          );
        },
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
        accessorKey: "name",
        header: t("common.name"),
        size: 280,
        cell: ({ row }) => {
          const s = row.original;
          return (
            <div className="flex items-center gap-2">
              <div className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                <Store className="size-3.5" />
              </div>
              <div className="flex flex-col">
                <Link
                  href={`/hq/${brandSlug}/shops/${s.slug}`}
                  className="font-medium text-primary hover:underline"
                >
                  {s.name}
                </Link>
                <span className="text-xs text-muted-foreground">{s.slug}</span>
              </div>
            </div>
          );
        },
      },
      {
        accessorKey: "brand_name",
        header: t("hq.shops.col.brand"),
        size: 180,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.brand_name ?? "—"}</span>
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
        id: "open_pos",
        header: "POS",
        size: 80,
        cell: ({ row }) =>
          row.original.deleted_at ? null : (
            <a
              href={`/shop/${row.original.slug}/dashboard`}
              target="_blank"
              rel="noopener noreferrer"
              onClick={(e) => e.stopPropagation()}
              className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs text-primary hover:bg-accent"
            >
              <ExternalLink className="size-3" /> Open
            </a>
          ),
      },
      {
        accessorKey: "updated_at",
        header: t("common.updated"),
        size: 120,
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
          const s = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {s.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(s.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem
                      onClick={() => router.push(`/hq/${brandSlug}/shops/${s.slug}`)}
                    >
                      <ArrowUpRight className="mr-2 size-3.5" /> {t("common.view_details")}
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => toggleStatus.mutate(s.id)}>
                      {s.is_active ? (
                        <>
                          <Pause className="mr-2 size-3.5" /> {t("common.deactivate")}
                        </>
                      ) : (
                        <>
                          <Play className="mr-2 size-3.5" /> {t("common.activate")}
                        </>
                      )}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(s)}
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
    [items, selected, toggleStatus, restore, t, brandSlug, router, locale, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.shops.title")}
        description={`${meta.total} ${t("hq.shops.total_suffix")}`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" /> {t("hq.shops.create")}
        </Button>
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.shops.search_placeholder")}
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
              disabled={bulkDelete.isPending}
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
              <SelectItem value="all">{t("hq.shops.filter.all_status")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isError && (
          <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
            {t("common.error_loading")}: {error instanceof Error ? error.message : ""}
          </div>
        )}

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={8} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.shops.empty")} />
        )}

        <Pagination
          meta={meta}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <CreateShopDialog brandSlug={brandSlug} open={createOpen} onOpenChange={setCreateOpen} />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle ? t("hq.shops.delete_confirm", { name: confirmSingle.name }) : ""
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
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={(open) => {
          if (!open) setConfirmBulk(false);
        }}
        description={t("common.delete_selected_confirm", { n: selected.size })}
        onConfirm={async () => {
          await bulkDelete.mutateAsync(Array.from(selected));
          setSelected(new Set());
          setConfirmBulk(false);
        }}
      />
    </>
  );
}
