"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { Copy, CopyPlus, EllipsisVertical, Pencil, Plus, Power, Trash2 } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { toast } from "sonner";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Pagination } from "@/components/shared/pagination";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { FloatingSectionFormDialog } from "./components/floating-section-form-dialog";
import {
  Button,
  Checkbox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";

import { formatDate } from "@/lib/date";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { useShops } from "@/hooks/api/use-shops";
import {
  useFloatingSections,
  useDeleteFloatingSection,
  useBulkDeleteFloatingSections,
  useUpdateFloatingSection,
  useCloneFloatingSectionToBranch,
  useDuplicateFloatingSection,
} from "@/hooks/api/use-floating-sections";
import type { FloatingSection } from "@/types/models/FloatingSection";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  per_page: "25",
};

export default function FloatingSectionsPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch]);

  const activeFilter = urlFilters.status as "all" | "active" | "inactive";
  const hasActiveFilters = Object.entries(urlFilters).some(
    ([k, v]) => v !== FILTER_DEFAULTS[k as keyof typeof FILTER_DEFAULTS]
  );

  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);
  const [editSection, setEditSection] = useState<FloatingSection | null>(null);
  const [confirmSingle, setConfirmSingle] = useState<FloatingSection | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [cloneDialog, setCloneDialog] = useState<FloatingSection | null>(null);
  const [cloneBranchId, setCloneBranchId] = useState("");

  // Branches of this brand — for the Clone-to-branch dialog.
  const { data: shopsResponse, isLoading: shopsLoading } = useShops(brandSlug, { per_page: 100 });
  const branches = useMemo(() => shopsResponse?.data ?? [], [shopsResponse?.data]);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
    }),
    [page, urlFilters.per_page, debouncedSearch, activeFilter]
  );

  const {
    data: response,
    isLoading,
    refetch,
    isFetching,
  } = useFloatingSections(brandSlug, apiFilters);
  const items = useMemo<FloatingSection[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: null,
    to: null,
  };

  useEffect(() => {
    setSelected(new Set());
  }, [apiFilters]);

  const deleteOne = useDeleteFloatingSection(brandSlug);
  const bulkDelete = useBulkDeleteFloatingSections(brandSlug);
  const updateMutation = useUpdateFloatingSection(brandSlug);
  const cloneMutation = useCloneFloatingSectionToBranch(brandSlug);
  const duplicateMutation = useDuplicateFloatingSection(brandSlug);

  function closeCloneDialog() {
    setCloneDialog(null);
    setCloneBranchId("");
  }

  async function handleCloneConfirm() {
    if (!cloneDialog || !cloneBranchId.trim()) return;
    try {
      await cloneMutation.mutateAsync({ id: cloneDialog.id, data: { branch_id: cloneBranchId } });
      toast.success(t("hq.floating_sections.clone.success"));
      closeCloneDialog();
    } catch {
      // cloneMutation's onError already surfaces the toast (e.g. "already cloned").
    }
  }

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
      return new Set(items.map((s) => s.id));
    });
  }

  const columns: ColumnDef<FloatingSection>[] = useMemo(
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
        id: "name",
        header: t("common.name"),
        size: 280,
        cell: ({ row }) => (
          <Link
            href={`/hq/${brandSlug}/floating-sections/${row.original.id}`}
            className="font-medium text-primary hover:underline"
          >
            {row.original.name}
          </Link>
        ),
      },
      {
        id: "products_count",
        header: t("hq.floating_sections.col.products"),
        size: 100,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.products_count ?? 0}</span>
        ),
      },
      {
        id: "status",
        header: t("common.status"),
        size: 90,
        cell: ({ row }) => <StatusBadge status={row.original.is_active ? "active" : "inactive"} />,
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
          const s = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuItem onClick={() => setEditSection(s)}>
                  <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() =>
                    updateMutation.mutate({ id: s.id, data: { is_active: !s.is_active } })
                  }
                >
                  <Power className="mr-2 size-3.5" />{" "}
                  {s.is_active ? t("common.deactivate") : t("common.activate")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() => duplicateMutation.mutate(s.id)}
                  disabled={duplicateMutation.isPending}
                >
                  <CopyPlus className="mr-2 size-3.5" /> {t("hq.floating_sections.actions.duplicate")}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => setCloneDialog(s)}>
                  <Copy className="mr-2 size-3.5" /> {t("hq.floating_sections.clone.action")}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem className="text-destructive" onClick={() => setConfirmSingle(s)}>
                  <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [t, locale, timezone, brandSlug, updateMutation, duplicateMutation, selected, items, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.floating_sections.title")}
        description={t("hq.floating_sections.header.total", { n: meta.total })}
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
          searchPlaceholder={t("hq.floating_sections.search_placeholder")}
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
              <SelectItem value="all">{t("common.all")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={7} />
        ) : (
          <DataTable
            columns={columns}
            data={items}
            emptyMessage={t("hq.floating_sections.empty")}
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

      <FloatingSectionFormDialog
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
      />

      <FloatingSectionFormDialog
        brandSlug={brandSlug}
        open={!!editSection}
        onOpenChange={(open) => {
          if (!open) setEditSection(null);
        }}
        section={editSection}
      />

      {/* Clone to Branch Dialog */}
      <Dialog
        open={!!cloneDialog}
        onOpenChange={(v) => {
          if (!v) closeCloneDialog();
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("hq.floating_sections.clone.title")}</DialogTitle>
            <DialogDescription>
              {t("hq.floating_sections.clone.desc", { name: cloneDialog?.name ?? "" })}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-1 py-3">
            <label className="text-xs font-medium text-muted-foreground">
              {t("hq.floating_sections.clone.target_branch")}
              <span className="ml-0.5 text-destructive">*</span>
            </label>
            <Select
              value={cloneBranchId || undefined}
              onValueChange={setCloneBranchId}
              disabled={shopsLoading}
            >
              <SelectTrigger className="h-9 w-full text-sm">
                <SelectValue
                  placeholder={
                    shopsLoading
                      ? t("hq.menus.form.loading_branches")
                      : t("hq.menus.form.select_branch")
                  }
                />
              </SelectTrigger>
              <SelectContent>
                {branches.map((b) => (
                  <SelectItem key={b.id} value={b.id}>
                    {b.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={closeCloneDialog}>
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={handleCloneConfirm}
              disabled={!cloneBranchId.trim() || cloneMutation.isPending}
            >
              {cloneMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("hq.floating_sections.clone.action")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        title={t("hq.floating_sections.bulk_delete_title", { n: selected.size })}
        description={t("hq.floating_sections.bulk_delete_desc")}
        onConfirm={() => {
          bulkDelete.mutate(Array.from(selected));
          setSelected(new Set());
          setConfirmBulk(false);
        }}
        isPending={bulkDelete.isPending}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle
            ? t("hq.floating_sections.delete_confirm", { name: confirmSingle.name })
            : ""
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
    </>
  );
}
