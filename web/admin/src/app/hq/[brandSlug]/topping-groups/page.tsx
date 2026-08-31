"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import {
  ArrowUpDown,
  EllipsisVertical,
  GripVertical,
  Plus,
  Power,
  RotateCcw,
  Pencil,
  Trash2,
  UtensilsCrossed,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { CreateToppingGroupDialog } from "@/components/shared/create-topping-group-dialog";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { Button, Checkbox, Spinner, StatusBadge } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import {
  useToppingGroups,
  useBulkDeleteToppingGroups,
  useDeleteToppingGroup,
  useRestoreToppingGroup,
  useReorderToppingGroups,
  useToggleToppingGroupStatus,
} from "@/hooks/api/use-topping-groups";
import type { ToppingGroup } from "@/services/topping-group-service";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
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

export default function ToppingGroupsPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const router = useRouter();
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
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch, urlFilters.search, setFilter]);

  const activeFilter = urlFilters.status as "all" | "active" | "inactive";
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const [createOpen, setCreateOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<ToppingGroup | null>(null);
  const [confirmSingle, setConfirmSingle] = useState<ToppingGroup | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);

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

  const {
    data: response,
    isLoading,
    refetch,
    isFetching,
  } = useToppingGroups(brandSlug, apiFilters);
  const items = useMemo<ToppingGroup[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: null,
    to: null,
  };

  // ── Full list for reorder (no filter, no pagination) ──────────────────────
  const { data: allResponse } = useToppingGroups(brandSlug, {
    per_page: 999,
    sort: "sort_order",
    with_trashed: false,
  });

  // ── Reorder state ──────────────────────────────────────────────────────────
  const [isReordering, setIsReordering] = useState(false);
  const [reorderItems, setReorderItems] = useState<ToppingGroup[]>([]);
  const [hasReorderChanged, setHasReorderChanged] = useState(false);
  const [draggingIndex, setDraggingIndex] = useState<number | null>(null);
  const dragItemIndex = useRef<number | null>(null);
  const dragOverItemIndex = useRef<number | null>(null);
  const reorderMutation = useReorderToppingGroups(brandSlug);

  function enterReorderMode() {
    const sorted = [...(allResponse?.data ?? items)].sort((a, b) => a.sort_order - b.sort_order);
    setReorderItems(sorted);
    setHasReorderChanged(false);
    setIsReordering(true);
  }

  function exitReorderMode() {
    setIsReordering(false);
    setHasReorderChanged(false);
    setDraggingIndex(null);
  }

  function onDragStart(e: React.DragEvent<HTMLDivElement>, index: number) {
    dragItemIndex.current = index;
    setDraggingIndex(index);
    e.dataTransfer.effectAllowed = "move";
    setTimeout(() => {
      (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]")!.style.opacity = "0.4";
    }, 0);
  }

  function onDragEnter(_e: React.DragEvent<HTMLDivElement>, index: number) {
    dragOverItemIndex.current = index;
  }

  function onDragOver(e: React.DragEvent<HTMLDivElement>) {
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
  }

  function onDrop(e: React.DragEvent<HTMLDivElement>) {
    e.preventDefault();
    const from = dragItemIndex.current;
    const to = dragOverItemIndex.current;

    if (from !== null && to !== null && from !== to) {
      const updated = [...reorderItems];
      const [removed] = updated.splice(from, 1);
      updated.splice(to, 0, removed);
      setReorderItems(updated);
      setHasReorderChanged(true);
    }

    const row = (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]");
    if (row) row.style.opacity = "1";
    dragItemIndex.current = null;
    dragOverItemIndex.current = null;
    setDraggingIndex(null);
  }

  function onDragEnd(e: React.DragEvent<HTMLDivElement>) {
    const row = (e.target as HTMLElement).closest<HTMLElement>("[data-drag-row]");
    if (row) row.style.opacity = "1";
    dragItemIndex.current = null;
    dragOverItemIndex.current = null;
    setDraggingIndex(null);
  }

  function handleSaveOrder() {
    reorderMutation.mutate(
      reorderItems.map((g) => g.id),
      {
        onSuccess: () => {
          toast.success(t("hq.topping_groups.reorder_saved"));
          setHasReorderChanged(false);
          setIsReordering(false);
          refetch();
        },
      }
    );
  }

  // ── Table ──────────────────────────────────────────────────────────────────
  const deleteOne = useDeleteToppingGroup(brandSlug);
  const bulkDelete = useBulkDeleteToppingGroups(brandSlug);
  const restore = useRestoreToppingGroup(brandSlug);
  const toggleStatus = useToggleToppingGroupStatus(brandSlug);

  const [selected, setSelected] = useState<Set<string>>(new Set());
  const selectableItems = useMemo(() => items.filter((g) => !g.deleted_at), [items]);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    setSelected((prev) =>
      prev.size === selectableItems.length ? new Set() : new Set(selectableItems.map((g) => g.id))
    );
  }

  const columns: ColumnDef<ToppingGroup>[] = useMemo(
    () => [
      {
        id: "select",
        size: 36,
        header: () => (
          <Checkbox
            checked={selectableItems.length > 0 && selected.size === selectableItems.length}
            onCheckedChange={toggleSelectAll}
            aria-label="Select all"
          />
        ),
        cell: ({ row }) =>
          row.original.deleted_at ? null : (
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
        accessorKey: "name",
        header: t("common.name"),
        size: 280,
        cell: ({ row }) => (
          <button
            type="button"
            className="text-left font-medium text-primary hover:underline"
            onClick={() => router.push(`/hq/${brandSlug}/topping-groups/${row.original.id}`)}
          >
            {row.original.name}
          </button>
        ),
      },
      {
        accessorKey: "min_select",
        header: t("hq.topping_groups.col.min"),
        size: 100,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.min_select}</span>
        ),
      },
      {
        accessorKey: "max_select",
        header: t("hq.topping_groups.col.max"),
        size: 100,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.max_select ?? "—"}</span>
        ),
      },
      {
        accessorKey: "sort_order",
        header: t("hq.topping_groups.col.sort"),
        size: 80,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.sort_order}</span>
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
          const tg = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-44">
                {tg.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(tg.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem
                      onClick={() => router.push(`/hq/${brandSlug}/topping-groups/${tg.id}`)}
                    >
                      <UtensilsCrossed className="mr-2 size-3.5" />{" "}
                      {t("hq.topping_groups.actions.manage_items")}
                    </DropdownMenuItem>

                    <DropdownMenuItem onClick={() => setEditTarget(tg)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>

                    <DropdownMenuItem
                      onClick={() => toggleStatus.mutate({ id: tg.id, is_active: !tg.is_active })}
                    >
                      <Power className="mr-2 size-3.5" />{" "}
                      {tg.is_active ? t("common.deactivate") : t("common.activate")}
                    </DropdownMenuItem>

                    <DropdownMenuSeparator />

                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(tg)}
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
    [items, meta.from, brandSlug, router, restore, toggleStatus, t, selected, selectableItems]
  );

  return (
    <>
      <PageHeader
        title={t("hq.topping_groups.title")}
        description={
          isReordering
            ? t("hq.topping_groups.reorder_hint")
            : t("hq.topping_groups.header.total", { n: meta.total })
        }
        onRefresh={!isReordering ? refetch : undefined}
        isRefreshing={isFetching}
      >
        {isReordering ? (
          <>
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={exitReorderMode}
              disabled={reorderMutation.isPending}
            >
              <X className="size-3.5" />
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={handleSaveOrder}
              disabled={!hasReorderChanged || reorderMutation.isPending}
            >
              {reorderMutation.isPending && <Spinner className="size-3.5" />}
              {t("common.save_changes")}
            </Button>
          </>
        ) : (
          <>
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={enterReorderMode}
            >
              <ArrowUpDown className="size-3.5" />
              {t("hq.topping_groups.reorder_btn")}
            </Button>
            <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
              <Plus className="size-3.5" />
              {t("common.new")}
            </Button>
          </>
        )}
      </PageHeader>

      <PageContent>
        {isReordering ? (
          /* ── Reorder mode ────────────────────────────────────────────────── */
          <div className="flex flex-col gap-1">
            {reorderItems.map((group, index) => {
              const isDragging = draggingIndex === index;
              return (
                <div
                  key={group.id}
                  data-drag-row
                  draggable
                  onDragStart={(e) => onDragStart(e, index)}
                  onDragEnter={(e) => onDragEnter(e, index)}
                  onDragOver={onDragOver}
                  onDrop={onDrop}
                  onDragEnd={onDragEnd}
                  className={[
                    "flex cursor-grab items-center gap-3 rounded-md border bg-card px-3 py-2.5 select-none",
                    "transition-all active:cursor-grabbing",
                    isDragging
                      ? "border-primary/40 bg-primary/5 shadow-md"
                      : "hover:border-border/80 hover:shadow-sm",
                  ].join(" ")}
                >
                  <GripVertical className="size-4 shrink-0 text-muted-foreground/40" />

                  <span className="inline-flex size-6 shrink-0 items-center justify-center rounded bg-muted text-[11px] font-semibold text-muted-foreground tabular-nums">
                    {index + 1}
                  </span>

                  <span className="flex-1 truncate text-sm font-medium">{group.name}</span>

                  <span className="text-xs text-muted-foreground">
                    {t("hq.topping_groups.col.min")} {group.min_select}
                    {group.max_select != null ? ` / ${group.max_select}` : ""}
                  </span>

                  <span
                    className={[
                      "inline-flex h-5 shrink-0 items-center rounded px-1.5 text-[10px] font-medium",
                      group.is_active
                        ? "bg-green-50 text-green-700 dark:bg-green-950/60 dark:text-green-300"
                        : "bg-muted text-muted-foreground",
                    ].join(" ")}
                  >
                    {group.is_active ? t("common.active") : t("common.inactive")}
                  </span>
                </div>
              );
            })}

            {reorderItems.length === 0 && (
              <div className="flex h-16 items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground">
                {t("hq.topping_groups.empty")}
              </div>
            )}
          </div>
        ) : (
          /* ── Normal table mode ───────────────────────────────────────────── */
          <>
            <ListPageToolbar
              search={search}
              onSearchChange={setSearch}
              searchPlaceholder={t("hq.topping_groups.search_placeholder")}
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
                <SelectTrigger className="h-8 w-44 text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t("hq.topping_groups.filter.all_status")}</SelectItem>
                  <SelectItem value="active">{t("common.active")}</SelectItem>
                  <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
                </SelectContent>
              </Select>
            </ListPageToolbar>

            {isLoading && response === undefined ? (
              <DataTableSkeleton columns={9} />
            ) : (
              <DataTable
                columns={columns}
                data={items}
                emptyMessage={t("hq.topping_groups.empty")}
              />
            )}

            <Pagination
              meta={meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
              page={page}
              onPageChange={setPage}
              perPage={Number(urlFilters.per_page) || 25}
              onPerPageChange={(v) => setFilter("per_page", String(v))}
            />
          </>
        )}
      </PageContent>

      <CreateToppingGroupDialog
        key={createOpen ? "create-open" : "create-closed"}
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
      />

      <CreateToppingGroupDialog
        key={editTarget ? `edit-${editTarget.id}` : "edit-closed"}
        brandSlug={brandSlug}
        group={editTarget}
        open={!!editTarget}
        onOpenChange={(open) => {
          if (!open) setEditTarget(null);
        }}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle ? t("hq.topping_groups.delete_confirm", { name: confirmSingle.name }) : ""
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
        onOpenChange={(open) => {
          if (!open) setConfirmBulk(false);
        }}
        description={t("hq.topping_groups.bulk_delete_confirm", { n: selected.size })}
        onConfirm={() => {
          bulkDelete.mutate([...selected], {
            onSuccess: () => {
              setSelected(new Set());
              setConfirmBulk(false);
            },
          });
        }}
        isPending={bulkDelete.isPending}
      />
    </>
  );
}
