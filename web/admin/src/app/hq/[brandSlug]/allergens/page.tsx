"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { EllipsisVertical, Pencil, Plus, Power, RotateCcw, Trash2 } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Pagination } from "@/components/shared/pagination";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { AllergenFormDialog } from "./components/allergen-form-dialog";
import { Badge, Button, Checkbox, StatusBadge } from "@godxjp/ui";
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
import {
  useAllergens,
  useBulkDeleteAllergens,
  useDeleteAllergen,
  useRestoreAllergen,
  useToggleAllergenStatus,
} from "@/hooks/api/use-allergens";
import type { Allergen } from "@/services/allergen-service";
import {
  type AllergenJurisdiction,
  AllergenJurisdictionValues,
} from "@/types/models/enum/AllergenJurisdiction";
import { AllergenSeverity, AllergenSeverityValues } from "@/types/models/enum/AllergenSeverity";

const FILTER_DEFAULTS = {
  search: "",
  jurisdiction: "all",
  severity: "all",
  status: "all",
  trashed: "",
  per_page: "25",
};

export default function AllergensPage() {
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
  }, [debouncedSearch]);

  const activeFilter = urlFilters.status as "all" | "active" | "inactive";
  const jurisdiction = urlFilters.jurisdiction as "all" | AllergenJurisdiction;
  const severity = urlFilters.severity as "all" | AllergenSeverity;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([k, v]) => v !== FILTER_DEFAULTS[k as keyof typeof FILTER_DEFAULTS]
  );

  // UI state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Allergen | null>(null);
  const [confirmSingle, setConfirmSingle] = useState<Allergen | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      jurisdiction: jurisdiction === "all" ? undefined : jurisdiction,
      severity: severity === "all" ? undefined : severity,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      with_trashed: showTrashed,
      sort: "code",
    }),
    [page, urlFilters.per_page, debouncedSearch, jurisdiction, severity, activeFilter, showTrashed]
  );

  const { data: response, isLoading, refetch, isFetching } = useAllergens(brandSlug, apiFilters);
  const items = useMemo<Allergen[]>(() => response?.data ?? [], [response]);
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

  useEffect(() => {
    setSearch("");
    setSelected(new Set());
  }, [brandSlug]);

  const deleteOne = useDeleteAllergen(brandSlug);
  const bulkDelete = useBulkDeleteAllergens(brandSlug);
  const restore = useRestoreAllergen(brandSlug);
  const toggleStatus = useToggleAllergenStatus(brandSlug);

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
      return new Set(items.map((a) => a.id));
    });
  }

  const columns: ColumnDef<Allergen>[] = useMemo(
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
        cell: ({ row }) => <span className="text-xs text-muted-foreground">{row.index + 1}</span>,
      },
      {
        id: "name",
        header: t("common.name"),
        size: 240,
        cell: ({ row }) => (
          <button
            type="button"
            className="text-left font-medium text-primary hover:underline"
            onClick={() => setEditing(row.original)}
          >
            {row.original.name}
          </button>
        ),
      },
      {
        id: "code",
        header: t("hq.allergens.col.code"),
        size: 140,
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{row.original.code}</code>
        ),
      },
      {
        id: "jurisdiction",
        header: t("hq.allergens.col.jurisdiction"),
        size: 120,
        cell: ({ row }) => (
          <Badge variant="secondary" className="text-[10px] uppercase">
            {t(`hq.allergens.jurisdiction.${row.original.jurisdiction}`)}
          </Badge>
        ),
      },
      {
        id: "severity",
        header: t("hq.allergens.col.severity"),
        size: 130,
        cell: ({ row }) => (
          <Badge
            variant={
              row.original.severity === AllergenSeverity.Mandatory ? "destructive" : "outline"
            }
            className="text-[10px]"
          >
            {t(`hq.allergens.severity.${row.original.severity}`)}
          </Badge>
        ),
      },
      {
        id: "status",
        header: t("common.status"),
        size: 90,
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
          const a = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {a.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(a.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem onClick={() => setEditing(a)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() =>
                        toggleStatus.mutate({ id: a.id, currentIsActive: a.is_active })
                      }
                    >
                      <Power className="mr-2 size-3.5" />{" "}
                      {a.is_active ? t("common.deactivate") : t("common.activate")}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(a)}
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [t, locale, brandSlug, restore, toggleStatus, selected, items]
  );

  return (
    <>
      <PageHeader
        title={t("hq.allergens.title")}
        description={t("hq.allergens.header.total", { n: meta.total })}
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
          searchPlaceholder={t("hq.allergens.search_placeholder")}
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
          <Select value={jurisdiction} onValueChange={(v) => setFilter("jurisdiction", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.allergens.filter.all_jurisdictions")}</SelectItem>
              {AllergenJurisdictionValues.map((j) => (
                <SelectItem key={j} value={j}>
                  {t(`hq.allergens.jurisdiction.${j}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={severity} onValueChange={(v) => setFilter("severity", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.allergens.filter.all_severities")}</SelectItem>
              {AllergenSeverityValues.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`hq.allergens.severity.${s}`)}
                </SelectItem>
              ))}
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
          <DataTableSkeleton columns={9} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.allergens.empty")} />
        )}

        <Pagination
          meta={meta}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      {/* Create dialog */}
      <AllergenFormDialog
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
        allergen={null}
      />

      {/* Edit dialog */}
      <AllergenFormDialog
        brandSlug={brandSlug}
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        allergen={editing}
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        title={t("hq.allergens.bulk_delete_title", { n: selected.size })}
        description={t("hq.allergens.bulk_delete_desc")}
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
          confirmSingle ? t("hq.allergens.delete_confirm", { name: confirmSingle.name }) : ""
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
