"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import type { ColumnDef } from "@tanstack/react-table";
import { EllipsisVertical, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { StatusBadge } from "@godxjp/ui";
import { Badge } from "@godxjp/ui";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@godxjp/ui";
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
  useBulkDeleteMaterials,
  useDeleteMaterial,
  useMaterials,
  useRestoreMaterial,
} from "@/hooks/api/use-materials";
import type { Material } from "@/services/material-service";
import { stripHtml } from "@/lib/utils";

import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  kind: "all",
  trashed: "",
  per_page: "25",
};

export default function MaterialsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string }>();
  const router = useRouter();
  const brandSlug = params.brandSlug;

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

  // Sync debounced search -> URL
  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]);

  // UI state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<Material | null>(null);

  const activeFilter = urlFilters.status as "all" | "active" | "inactive";
  const kindFilter = urlFilters.kind as "all" | "raw" | "produced";
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
      kind: kindFilter === "all" ? undefined : kindFilter,
      with_trashed: showTrashed,
      sort: "-updated_at",
    }),
    [page, urlFilters.per_page, debouncedSearch, activeFilter, kindFilter, showTrashed]
  );

  const { data: response, isLoading, refetch, isFetching } = useMaterials(brandSlug, apiFilters);
  const items = useMemo<Material[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: 0,
    to: 0,
  };

  // Drop selections when data changes
  useEffect(() => {
    setSelected(new Set());
  }, [apiFilters]);

  // Mutations
  const deleteOne = useDeleteMaterial(brandSlug);
  const bulkDelete = useBulkDeleteMaterials(brandSlug);
  const restore = useRestoreMaterial(brandSlug);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    if (selected.size === items.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(items.map((m) => m.id)));
    }
  }

  function openEdit(m: Material) {
    router.push(`/hq/${brandSlug}/materials/${m.id}`);
  }

  async function handleBulkDelete() {
    const ids = Array.from(selected);
    await bulkDelete.mutateAsync(ids);
    setSelected(new Set());
    setConfirmBulk(false);
  }

  const columns: ColumnDef<Material>[] = useMemo(
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
        accessorKey: "sku",
        header: "SKU",
        size: 110,
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{row.original.sku || "—"}</code>
        ),
      },
      {
        accessorKey: "name",
        header: t("common.name"),
        cell: ({ row }) => {
          const m = row.original;
          return (
            <div className="flex flex-col">
              <Link
                href={`/hq/${brandSlug}/materials/${m.id}`}
                className="font-medium text-primary hover:underline"
              >
                {m.name}
              </Link>
              {(() => {
                const preview = stripHtml(m.description);
                return preview ? (
                  <span className="line-clamp-1 text-xs text-muted-foreground">{preview}</span>
                ) : null;
              })()}
            </div>
          );
        },
      },
      {
        id: "kind",
        header: t("hq.materials.col.kind"),
        size: 100,
        cell: ({ row }) => {
          // Plan-022 — Raw (NVL nhập từ supplier, no BOM) vs Produced (BTP
          // có recipe, nhập kho qua MaterialBatch.complete()).
          const kind = row.original.kind ?? (row.original.yield_unit === null ? "raw" : "produced");
          return kind === "produced" ? (
            <Badge variant="soft" color="info">
              {t("hq.materials.kind.produced")}
            </Badge>
          ) : (
            <Badge variant="outline">{t("hq.materials.kind.raw")}</Badge>
          );
        },
      },
      {
        id: "recipes",
        header: t("hq.materials.col.recipes"),
        size: 110,
        cell: ({ row }) => {
          // Plan-022 T4.4 — recipe count replaces the legacy "components"
          // column. Zero means raw material; non-zero means it has at least
          // one active recipe and is batch-producible.
          const count = row.original.active_recipes_count ?? 0;
          return (
            <span className="text-xs text-muted-foreground">
              {count > 0 ? `${count} recipe${count === 1 ? "" : "s"}` : "—"}
            </span>
          );
        },
      },
      {
        id: "yield",
        header: t("hq.materials.col.yield"),
        size: 120,
        cell: ({ row }) => {
          const m = row.original;
          const qty = Number(m.yield_quantity) || 0;
          return (
            <span className="text-xs">
              {qty.toLocaleString()}
              {m.yield_unit ? ` ${m.yield_unit}` : ""}
            </span>
          );
        },
      },
      {
        accessorKey: "calculated_cost",
        header: t("hq.materials.col.cost"),
        size: 110,
        cell: ({ row }) => (
          <span className="text-right text-xs tabular-nums">
            {Number(row.original.calculated_cost).toLocaleString(undefined, {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            })}
          </span>
        ),
      },
      {
        id: "allergens",
        header: t("hq.materials.col.allergens"),
        size: 180,
        cell: ({ row }) => {
          const allergens = row.original.allergens ?? [];
          if (allergens.length === 0) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          const visible = allergens.slice(0, 3);
          const hidden = allergens.slice(3);
          return (
            <div className="flex flex-wrap items-center gap-1">
              {visible.map((a) => (
                <Badge key={a.id} variant="soft" color="warning">
                  {a.name}
                </Badge>
              ))}
              {hidden.length > 0 && (
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Badge variant="outline">{`+${hidden.length}`}</Badge>
                    </TooltipTrigger>
                    <TooltipContent>{hidden.map((a) => a.name).join(", ")}</TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              )}
            </div>
          );
        },
      },
      {
        id: "active_lots",
        header: t("hq.materials.col.active_lots"),
        size: 120,
        cell: ({ row }) => {
          const active = row.original.active_lots_count ?? 0;
          const expiring = row.original.expiring_soon_lots_count ?? 0;
          if (active === 0) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <div className="flex flex-wrap items-center gap-1">
              <Badge variant="outline">{active}</Badge>
              {expiring > 0 ? (
                <Badge className="bg-amber-100 text-amber-800">
                  {t("hq.materials.expiring_soon", { n: expiring })}
                </Badge>
              ) : null}
            </div>
          );
        },
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
          const m = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {m.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(m.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem onClick={() => openEdit(m)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(m)}
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
    [t, selected, deleteOne, restore, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.materials.title")}
        description={`${meta.total} total`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Link
          href={`/hq/${brandSlug}/materials/new`}
          className="inline-flex h-7 items-center gap-1 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground shadow-sm hover:bg-primary/90"
        >
          <Plus className="size-3.5" />
          {t("common.new")}
        </Link>
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.materials.search_placeholder")}
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
          <Select value={kindFilter} onValueChange={(v) => setFilter("kind", v)}>
            <SelectTrigger className="h-8 w-36 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.materials.kind.all")}</SelectItem>
              <SelectItem value="raw">{t("hq.materials.kind.raw")}</SelectItem>
              <SelectItem value="produced">{t("hq.materials.kind.produced")}</SelectItem>
            </SelectContent>
          </Select>
          <Select value={activeFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-36 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={12} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.materials.empty")} />
        )}

        <Pagination
          meta={meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle ? t("hq.materials.delete_confirm", { name: confirmSingle.name }) : ""
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
        title={t("hq.materials.bulk_delete_title", { n: selected.size })}
        description={t("hq.materials.bulk_delete_desc")}
        onConfirm={handleBulkDelete}
        isPending={bulkDelete.isPending}
      />
    </>
  );
}
