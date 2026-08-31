"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import {
  EllipsisVertical,
  Pencil,
  Plus,
  QrCode,
  RefreshCw,
  ShieldOff,
  Trash2,
  Undo2,
} from "lucide-react";
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
import { DeviceAppVersionCell } from "@/components/shared/device/app-version-cell";
import { DeviceConnectionCell } from "@/components/shared/device/connection-cell";
import { formatDateTime } from "@/lib/date";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

import {
  useShopDevices,
  useCreateShopDevice,
  useUpdateShopDevice,
  useDeleteShopDevice,
  useRestoreShopDevice,
  useRegeneratePairingShop,
  useRevokeShopDevice,
} from "@/hooks/api/use-devices";
import type { Device } from "@/types/models/Device";
import { DeviceStatus, getDeviceStatusLabel } from "@/types/models/enum/DeviceStatus";
import { getDeviceTypeLabel } from "@/types/models/enum/DeviceType";
import { DeviceFormDialog } from "@/components/shared/device/device-form-dialog";
import { PairingCodeDialog } from "@/components/shared/device/pairing-code-dialog";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  trashed: "",
  per_page: "25",
};

export default function ShopDevicesPage() {
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

  const statusFilter = urlFilters.status;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Device | null>(null);
  const [pairingDevice, setPairingDevice] = useState<Device | null>(null);
  const [confirmSingle, setConfirmSingle] = useState<Device | null>(null);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      status: statusFilter === "all" ? undefined : (statusFilter as DeviceStatus),
      with_trashed: showTrashed,
      sort: "-created_at",
    }),
    [page, urlFilters.per_page, debouncedSearch, statusFilter, showTrashed]
  );

  const { data: response, isLoading, refetch, isFetching } = useShopDevices(shopSlug, apiFilters);
  const devices = useMemo(() => response?.data ?? [], [response]);

  const createMutation = useCreateShopDevice(shopSlug);
  const updateMutation = useUpdateShopDevice(shopSlug);
  const deleteMutation = useDeleteShopDevice(shopSlug);
  const restoreMutation = useRestoreShopDevice(shopSlug);
  const regenMutation = useRegeneratePairingShop(shopSlug);
  const revokeMutation = useRevokeShopDevice(shopSlug);

  const columns: ColumnDef<Device>[] = useMemo(
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
        header: t("shop.devices.col.type"),
        size: 160,
        cell: ({ row }) => (
          <span className="text-xs">{getDeviceTypeLabel(row.original.type, locale)}</span>
        ),
      },
      {
        accessorKey: "status",
        header: t("common.status"),
        size: 140,
        cell: ({ row }) => (
          <StatusBadge status={row.original.deleted_at ? "deleted" : row.original.status} />
        ),
      },
      {
        id: "pairing",
        header: t("shop.devices.col.pairing"),
        size: 120,
        cell: ({ row }) => {
          const d = row.original;
          if (d.status === "pending_activation" && d.pairing_code) {
            return (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 gap-1 font-mono text-xs"
                onClick={() => setPairingDevice(d)}
              >
                <QrCode className="size-3.5" />
                {d.pairing_code}
              </Button>
            );
          }
          if (d.status === "active") {
            return <span className="text-xs text-success">{t("shop.devices.paired")}</span>;
          }
          return <span className="text-xs text-muted-foreground">—</span>;
        },
      },
      {
        id: "connection",
        header: t("shop.devices.col.connection"),
        size: 110,
        cell: ({ row }) => <DeviceConnectionCell device={row.original} />,
      },
      {
        id: "app_version",
        header: t("shop.devices.col.app_version"),
        size: 140,
        cell: ({ row }) => <DeviceAppVersionCell device={row.original} />,
      },
      {
        accessorKey: "last_seen_at",
        header: t("shop.devices.col.last_seen"),
        size: 150,
        cell: ({ row }) =>
          row.original.last_seen_at ? (
            <span className="text-xs text-muted-foreground">
              {formatDateTime(row.original.last_seen_at, locale, timezone)}
            </span>
          ) : (
            <span className="text-xs text-muted-foreground">—</span>
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
                    {(d.status === "pending_activation" || d.status === "active") && (
                      <DropdownMenuItem
                        onClick={() =>
                          regenMutation.mutate(d.id, {
                            onSuccess: (result) => setPairingDevice(result.data),
                          })
                        }
                      >
                        <RefreshCw className="mr-2 size-3.5" />
                        {t("shop.devices.regenerate_code")}
                      </DropdownMenuItem>
                    )}
                    {d.status === "active" && (
                      <DropdownMenuItem
                        className="text-destructive"
                        onClick={() => revokeMutation.mutate(d.id)}
                      >
                        <ShieldOff className="mr-2 size-3.5" />
                        {t("shop.devices.revoke")}
                      </DropdownMenuItem>
                    )}
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
    [t, locale, restoreMutation, regenMutation, revokeMutation, response?.meta?.from]
  );

  return (
    <>
      <PageHeader
        title={t("shop.devices.title")}
        description={`${response?.meta.total ?? 0} devices`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("common.create")}
        </Button>
        <HelpPanel
          title={t("shop.devices.title")}
          subtitle={t("help.panel.shop_devices.subtitle")}
          purpose={t("help.panel.shop_devices.purpose")}
          usage={[
            t("help.panel.shop_devices.usage.1"),
            t("help.panel.shop_devices.usage.2"),
            t("help.panel.shop_devices.usage.3"),
            t("help.panel.shop_devices.usage.4"),
          ]}
          checks={[
            t("help.panel.shop_devices.checks.1"),
            t("help.panel.shop_devices.checks.2"),
            t("help.panel.shop_devices.checks.3"),
            t("help.panel.shop_devices.checks.4"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_devices.glossary.last_seen.term"),
              description: t("help.panel.shop_devices.glossary.last_seen.desc"),
            },
            {
              term: t("help.panel.shop_devices.glossary.status_chain.term"),
              description: t("help.panel.shop_devices.glossary.status_chain.desc"),
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
          <Select value={statusFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all")}</SelectItem>
              {Object.values(DeviceStatus).map((s) => (
                <SelectItem key={s} value={s}>
                  {getDeviceStatusLabel(s, locale)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={7} />
        ) : (
          <DataTable columns={columns} data={devices} emptyMessage={t("shop.devices.empty")} />
        )}

        <Pagination
          meta={response?.meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <DeviceFormDialog
        open={createOpen || !!editing}
        onOpenChange={(open) => {
          if (!open) {
            setCreateOpen(false);
            setEditing(null);
          }
        }}
        device={editing}
        showBranchSelect={false}
        onSubmit={async (data) => {
          if (editing) {
            await updateMutation.mutateAsync({ id: editing.id, data });
          } else {
            await createMutation.mutateAsync(
              data as Parameters<typeof createMutation.mutateAsync>[0]
            );
          }
        }}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={t("hq.devices.delete_confirm", { name: confirmSingle?.name ?? "" })}
        isPending={deleteMutation.isPending}
        onConfirm={async () => {
          if (!confirmSingle) return;
          await deleteMutation.mutateAsync(confirmSingle.id);
          setConfirmSingle(null);
        }}
      />

      <PairingCodeDialog device={pairingDevice} onClose={() => setPairingDevice(null)} />
    </>
  );
}
