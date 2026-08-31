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
import { useTranslation } from "@/providers/app-provider";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

import {
  useShopPeripheralDevices,
  useCreateShopPeripheralDevice,
  useUpdateShopPeripheralDevice,
  useDeleteShopPeripheralDevice,
  useRestoreShopPeripheralDevice,
} from "@/hooks/api/use-peripheral-devices";
import type { PeripheralDevice, PeripheralDeviceType } from "@/types/models/PeripheralDevice";
import { isNetworkPeripheralType } from "@/types/models/PeripheralDevice";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { PeripheralDeviceFormDialog } from "./components/peripheral-device-form-dialog";

const FILTER_DEFAULTS = {
  search: "",
  type: "all",
  trashed: "",
  per_page: "25",
};

const TYPE_OPTIONS: PeripheralDeviceType[] = [
  "payment_terminal",
  "coin_changer",
  "receipt_printer",
  "kitchen_printer",
  "bar_printer",
];

export default function ShopPeripheralDevicesPage() {
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

  const typeFilter = urlFilters.type;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<PeripheralDevice | null>(null);
  const [confirmSingle, setConfirmSingle] = useState<PeripheralDevice | null>(null);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      type: typeFilter === "all" ? undefined : (typeFilter as PeripheralDeviceType),
      with_trashed: showTrashed,
      sort: "-created_at",
    }),
    [page, urlFilters.per_page, debouncedSearch, typeFilter, showTrashed]
  );

  const {
    data: response,
    isLoading,
    refetch,
    isFetching,
  } = useShopPeripheralDevices(shopSlug, apiFilters);
  const devices = useMemo(() => response?.data ?? [], [response]);

  const createMutation = useCreateShopPeripheralDevice(shopSlug);
  const updateMutation = useUpdateShopPeripheralDevice(shopSlug);
  const deleteMutation = useDeleteShopPeripheralDevice(shopSlug);
  const restoreMutation = useRestoreShopPeripheralDevice(shopSlug);

  const columns: ColumnDef<PeripheralDevice>[] = useMemo(
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
        size: 200,
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
        accessorKey: "type",
        header: t("shop.peripherals.col.type"),
        size: 160,
        cell: ({ row }) => (
          <span className="text-xs">{t(`shop.peripherals.type.${row.original.type}`)}</span>
        ),
      },
      {
        id: "address",
        header: t("shop.peripherals.col.address"),
        size: 180,
        cell: ({ row }) => {
          const d = row.original;
          if (!isNetworkPeripheralType(d.type)) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          const host = d.metadata?.host;
          if (!host) {
            return (
              <span className="text-xs text-destructive">{t("shop.peripherals.no_host")}</span>
            );
          }
          return (
            <span className="text-xs">
              {d.type === "coin_changer" && d.metadata?.model ? (
                <span className="mr-1 font-medium">{d.metadata.model}</span>
              ) : null}
              <span className="font-mono">
                {host}
                {d.metadata?.port ? `:${d.metadata.port}` : ""}
              </span>
            </span>
          );
        },
      },
      {
        id: "accepts",
        header: t("shop.peripherals.col.accepts"),
        size: 140,
        cell: ({ row }) => {
          const d = row.original;
          if (!isNetworkPeripheralType(d.type)) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          const accepts = d.metadata?.accepts;
          if (!accepts || accepts.length === 0) {
            return (
              <span className="text-xs text-muted-foreground">
                {t("shop.peripherals.accepts_none")}
              </span>
            );
          }
          return (
            <span className="text-xs" title={accepts.join(", ")}>
              <span className="font-mono">{accepts[0]}</span>
              {accepts.length > 1 && (
                <span className="ml-1 text-muted-foreground">+{accepts.length - 1}</span>
              )}
            </span>
          );
        },
      },
      {
        accessorKey: "is_active",
        header: t("common.status"),
        size: 120,
        cell: ({ row }) => (
          <StatusBadge
            status={
              row.original.deleted_at ? "deleted" : row.original.is_active ? "active" : "inactive"
            }
          />
        ),
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => {
          const d = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {d.deleted_at ? (
                  <DropdownMenuItem onClick={() => restoreMutation.mutate(d.id)}>
                    <Undo2 className="mr-2 size-3.5" />
                    {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem onClick={() => setEditing(d)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmSingle(d)}
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
    [t, restoreMutation, response?.meta?.from]
  );

  return (
    <>
      <PageHeader
        title={t("shop.peripherals.title")}
        description={t("shop.peripherals.count", { total: response?.meta.total ?? 0 })}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("shop.peripherals.register")}
        </Button>
        <HelpPanel
          title={t("shop.peripherals.title")}
          subtitle={t("help.panel.shop_peripherals.subtitle")}
          purpose={t("help.panel.shop_peripherals.purpose")}
          usage={[
            t("help.panel.shop_peripherals.usage.1"),
            t("help.panel.shop_peripherals.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_peripherals.checks.1"),
            t("help.panel.shop_peripherals.checks.2"),
            t("help.panel.shop_peripherals.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_peripherals.glossary.network_types.term"),
              description: t("help.panel.shop_peripherals.glossary.network_types.desc"),
            },
            {
              term: t("help.panel.shop_peripherals.glossary.accepts.term"),
              description: t("help.panel.shop_peripherals.glossary.accepts.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("common.search") + "..."}
          showTrashed={showTrashed}
          onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && response === undefined}
        >
          <Select value={typeFilter} onValueChange={(v) => setFilter("type", v)}>
            <SelectTrigger className="h-8 w-44 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all")}</SelectItem>
              {TYPE_OPTIONS.map((pt) => (
                <SelectItem key={pt} value={pt}>
                  {t(`shop.peripherals.type.${pt}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={7} />
        ) : (
          <DataTable columns={columns} data={devices} emptyMessage={t("shop.peripherals.empty")} />
        )}

        <Pagination
          meta={response?.meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <PeripheralDeviceFormDialog
        shopSlug={shopSlug}
        open={createOpen || !!editing}
        onOpenChange={(open) => {
          if (!open) {
            setCreateOpen(false);
            setEditing(null);
          }
        }}
        device={editing}
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
        description={t("shop.peripherals.delete_confirm", { name: confirmSingle?.name ?? "" })}
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
