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
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { DeviceAppVersionCell } from "@/components/shared/device/app-version-cell";
import { DeviceConnectionCell } from "@/components/shared/device/connection-cell";
import { formatDateTime } from "@/lib/date";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

import {
  useHqDevices,
  useCreateHqDevice,
  useUpdateHqDevice,
  useDeleteHqDevice,
  useRestoreHqDevice,
  useRegeneratePairingHq,
  useRevokeHqDevice,
  useBulkDeleteHqDevices,
} from "@/hooks/api/use-devices";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { useShops } from "@/hooks/api/use-shops";
import type { Device } from "@/types/models/Device";
import { DeviceStatus } from "@/types/models/enum/DeviceStatus";
import { getDeviceStatusLabel } from "@/types/models/enum/DeviceStatus";
import { DeviceType, getDeviceTypeLabel } from "@/types/models/enum/DeviceType";
import { DeviceFormDialog } from "@/components/shared/device/device-form-dialog";
import { PairingCodeDialog } from "@/components/shared/device/pairing-code-dialog";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  type: "all",
  branch_id: "all",
  trashed: "",
  per_page: "25",
};

export default function HqDevicesPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
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

  const statusFilter = urlFilters.status;
  const typeFilter = urlFilters.type;
  const branchIdFilter = urlFilters.branch_id;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  // Dialog state
  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Device | null>(null);
  const [pairingDevice, setPairingDevice] = useState<Device | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<Device | null>(null);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      status: statusFilter === "all" ? undefined : (statusFilter as DeviceStatus),
      type: typeFilter === "all" ? undefined : (typeFilter as DeviceType),
      branch_id: branchIdFilter === "all" ? undefined : branchIdFilter,
      with_trashed: showTrashed,
      sort: "-created_at",
    }),
    [
      page,
      urlFilters.per_page,
      debouncedSearch,
      statusFilter,
      typeFilter,
      branchIdFilter,
      showTrashed,
    ]
  );

  const { data: response, isLoading, refetch, isFetching } = useHqDevices(brandSlug, apiFilters);
  const devices = useMemo(() => response?.data ?? [], [response]);

  // Selection state — declared after devices so selectableDevices can reference it
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const selectableDevices = useMemo(() => devices.filter((d) => !d.deleted_at), [devices]);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    setSelected((prev) =>
      prev.size === selectableDevices.length
        ? new Set()
        : new Set(selectableDevices.map((d) => d.id))
    );
  }

  // Branches for form select
  const { data: shopsResponse } = useShops(brandSlug, { per_page: 100 });
  const branches = useMemo(
    () => (shopsResponse?.data ?? []).map((s) => ({ id: s.id, name: s.name })),
    [shopsResponse]
  );

  // Mutations
  const createMutation = useCreateHqDevice(brandSlug);
  const updateMutation = useUpdateHqDevice(brandSlug);
  const deleteMutation = useDeleteHqDevice(brandSlug);
  const restoreMutation = useRestoreHqDevice(brandSlug);
  const regenMutation = useRegeneratePairingHq(brandSlug);
  const revokeMutation = useRevokeHqDevice(brandSlug);
  const bulkDeleteMutation = useBulkDeleteHqDevices(brandSlug);

  // Columns
  const columns: ColumnDef<Device>[] = useMemo(
    () => [
      {
        id: "select",
        size: 36,
        header: () => (
          <Checkbox
            checked={selectableDevices.length > 0 && selected.size === selectableDevices.length}
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
          <span className="text-xs text-muted-foreground">
            {(response?.meta.from ?? 1) + row.index}
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
        id: "branch",
        header: "Branch",
        size: 150,
        cell: ({ row }) => {
          const branchName = (row.original.branch as { name?: string } | undefined)?.name;
          return <span className="text-xs">{branchName ?? "—"}</span>;
        },
      },
      {
        accessorKey: "type",
        header: "Type",
        size: 130,
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
        header: "Pairing",
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
          return <span className="text-xs text-muted-foreground">-</span>;
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
        header: "Last Seen",
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
    [
      t,
      locale,
      branches,
      selected,
      selectableDevices,
      deleteMutation,
      restoreMutation,
      regenMutation,
      revokeMutation,
      response?.meta?.from,
    ]
  );

  return (
    <>
      <PageHeader
        title={t("hq.devices.title")}
        description={`${response?.meta.total ?? 0} devices`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("common.create")}
        </Button>
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
          selectedCount={selected.size}
          bulkActions={
            <Button
              variant="destructive"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={() => setConfirmBulk(true)}
              disabled={bulkDeleteMutation.isPending}
            >
              <Trash2 className="size-3.5" />
              {t("common.delete_selected", { n: selected.size })}
            </Button>
          }
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

          <Select value={typeFilter} onValueChange={(v) => setFilter("type", v)}>
            <SelectTrigger className="h-8 w-44 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all")}</SelectItem>
              {Object.values(DeviceType).map((type) => (
                <SelectItem key={type} value={type}>
                  {getDeviceTypeLabel(type, locale)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={branchIdFilter} onValueChange={(v) => setFilter("branch_id", v)}>
            <SelectTrigger className="h-8 w-44 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all")}</SelectItem>
              {branches.map((b) => (
                <SelectItem key={b.id} value={b.id}>
                  {b.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={8} />
        ) : (
          <DataTable columns={columns} data={devices} emptyMessage={t("hq.devices.empty")} />
        )}

        <Pagination
          meta={response?.meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      {/* Create / Edit Dialog */}
      <DeviceFormDialog
        open={createOpen || !!editing}
        onOpenChange={(open) => {
          if (!open) {
            setCreateOpen(false);
            setEditing(null);
          }
        }}
        device={editing}
        showBranchSelect
        branches={branches}
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

      {/* Single Delete Confirm Dialog */}
      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={t("hq.devices.delete_confirm", { name: confirmSingle?.name ?? "" })}
        isPending={deleteMutation.isPending}
        onConfirm={async () => {
          if (!confirmSingle) return;
          const id = confirmSingle.id;
          await deleteMutation.mutateAsync(id);
          setSelected((prev) => {
            const next = new Set(prev);
            next.delete(id);
            return next;
          });
          setConfirmSingle(null);
        }}
      />

      {/* Bulk Delete Confirm Dialog */}
      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        description={t("hq.devices.bulk_delete_confirm", { n: selected.size })}
        isPending={bulkDeleteMutation.isPending}
        onConfirm={async () => {
          await bulkDeleteMutation.mutateAsync([...selected]);
          setSelected(new Set());
          setConfirmBulk(false);
        }}
      />

      {/* Pairing Code Dialog */}
      <PairingCodeDialog device={pairingDevice} onClose={() => setPairingDevice(null)} />
    </>
  );
}
