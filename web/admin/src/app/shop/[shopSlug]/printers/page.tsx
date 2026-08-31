"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import { EllipsisVertical, Pencil, Plus, Trash2, Undo2 } from "lucide-react";
import {
  Button,
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
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { formatDateTime } from "@/lib/date";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

import {
  useShopPrinters,
  useCreateShopPrinter,
  useUpdateShopPrinter,
  useDeleteShopPrinter,
  useRestoreShopPrinter,
} from "@/hooks/api/use-printers";
import type { Printer } from "@/types/models/Printer";
import { PrinterRole, getPrinterRoleLabel } from "@/types/models/enum/PrinterRole";
import { getPrinterConnectionTypeLabel } from "@/types/models/enum/PrinterConnectionType";
import type { PrinterConnectionType } from "@/types/models/enum/PrinterConnectionType";
import { PrinterFormDialog } from "@/components/shared/printer/printer-form-dialog";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";

const FILTER_DEFAULTS = {
  search: "",
  role: "all",
  trashed: "",
  per_page: "25",
};

export default function ShopPrintersPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
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

  const roleFilter = urlFilters.role;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Printer | null>(null);
  const [confirmSingle, setConfirmSingle] = useState<Printer | null>(null);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      role: roleFilter === "all" ? undefined : (roleFilter as PrinterRole),
      with_trashed: showTrashed,
      sort: "-created_at",
    }),
    [page, urlFilters.per_page, debouncedSearch, roleFilter, showTrashed]
  );

  const { data: response, isLoading, refetch, isFetching } = useShopPrinters(shopSlug, apiFilters);
  const printers = useMemo(() => response?.data ?? [], [response]);

  const createMutation = useCreateShopPrinter(shopSlug);
  const updateMutation = useUpdateShopPrinter(shopSlug);
  const deleteMutation = useDeleteShopPrinter(shopSlug);
  const restoreMutation = useRestoreShopPrinter(shopSlug);

  const columns: ColumnDef<Printer>[] = useMemo(
    () => [
      {
        id: "stt",
        header: t("hq.products.col.stt"),
        size: 50,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {(response?.meta?.from ?? 1) + row.index}
          </span>
        ),
      },
      {
        accessorKey: "name",
        header: t("common.name"),
        size: 180,
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
        accessorKey: "roles",
        header: t("printer.roles"),
        size: 220,
        cell: ({ row }) => {
          const roles = Array.isArray(row.original.roles) ? row.original.roles : [];
          if (roles.length === 0) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <div className="flex flex-wrap gap-1">
              {roles.map((role) => (
                <span
                  key={String(role)}
                  className="rounded border border-border bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                >
                  {getPrinterRoleLabel(role as PrinterRole, locale)}
                </span>
              ))}
            </div>
          );
        },
      },
      {
        accessorKey: "address",
        header: t("common.address"),
        size: 180,
        cell: ({ row }) => (
          <div className="flex flex-col">
            <span className="font-mono text-xs">{row.original.address || "—"}</span>
            <span className="text-[11px] text-muted-foreground">
              {getPrinterConnectionTypeLabel(
                row.original.connection_type as PrinterConnectionType,
                locale
              )}
            </span>
          </div>
        ),
      },
      {
        accessorKey: "paper_width",
        header: t("printer.paper_width"),
        size: 90,
        cell: ({ row }) => <span className="text-xs">{row.original.paper_width} mm</span>,
      },
      {
        accessorKey: "is_active",
        header: t("printer.status"),
        size: 110,
        cell: ({ row }) => (
          <StatusBadge
            status={
              row.original.deleted_at ? "deleted" : row.original.is_active ? "active" : "inactive"
            }
          />
        ),
      },
      {
        accessorKey: "last_seen_at",
        header: t("printer.last_seen"),
        size: 150,
        cell: ({ row }) =>
          row.original.last_seen_at ? (
            <span className="text-xs text-muted-foreground">
              {formatDateTime(row.original.last_seen_at, locale, timezone)}
            </span>
          ) : (
            <span className="text-xs text-muted-foreground">{t("printer.never_seen")}</span>
          ),
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => {
          const p = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {p.deleted_at ? (
                  <DropdownMenuItem onClick={() => restoreMutation.mutate(p.id)}>
                    <Undo2 className="mr-2 size-3.5" />
                    {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem onClick={() => setEditing(p)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(p)}
                    >
                      <Trash2 className="mr-2 size-3.5" />
                      {t("common.delete")}
                    </DropdownMenuItem>
                  </>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
      },
    ],
    [t, locale, timezone, restoreMutation, response?.meta?.from]
  );

  return (
    <>
      <PageHeader
        title={t("printer.title")}
        description={t("printer.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("printer.add")}
        </Button>
        <HelpPanel
          title={t("printer.title")}
          subtitle={t("help.panel.shop_printers.subtitle")}
          purpose={t("help.panel.shop_printers.purpose")}
          usage={[t("help.panel.shop_printers.usage.1"), t("help.panel.shop_printers.usage.2")]}
          checks={[
            t("help.panel.shop_printers.checks.1"),
            t("help.panel.shop_printers.checks.2"),
            t("help.panel.shop_printers.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_printers.glossary.roles.term"),
              description: t("help.panel.shop_printers.glossary.roles.desc"),
            },
            {
              term: t("help.panel.shop_printers.glossary.connection.term"),
              description: t("help.panel.shop_printers.glossary.connection.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("printer.search_placeholder")}
          showTrashed={showTrashed}
          onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && response === undefined}
        >
          <Select value={roleFilter} onValueChange={(v) => setFilter("role", v)}>
            <SelectTrigger className="h-8 w-44 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("printer.all_roles")}</SelectItem>
              {Object.values(PrinterRole).map((r) => (
                <SelectItem key={r} value={r}>
                  {getPrinterRoleLabel(r, locale)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={8} />
        ) : (
          <DataTable columns={columns} data={printers} emptyMessage={t("printer.empty")} />
        )}

        <Pagination
          meta={response?.meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <PrinterFormDialog
        open={createOpen || !!editing}
        onOpenChange={(open) => {
          if (!open) {
            setCreateOpen(false);
            setEditing(null);
          }
        }}
        printer={editing}
        onSubmit={async (data) => {
          if (editing) {
            await updateMutation.mutateAsync({ id: editing.id, data });
          } else {
            await createMutation.mutateAsync(data);
          }
        }}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={t("printer.delete_confirm")}
        isPending={deleteMutation.isPending}
        onConfirm={async () => {
          if (!confirmSingle) return;
          await deleteMutation.mutateAsync(confirmSingle.id);
          setConfirmSingle(null);
        }}
      />
    </>
  );
}
