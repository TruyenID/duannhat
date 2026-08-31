"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import { EllipsisVertical, Eye, Pause, Play, Trash2 } from "lucide-react";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination, type PaginationMeta } from "@/components/shared/pagination";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";
import {
  useHqMaterialLots,
  useBulkDeleteMaterialLots,
  useQuarantineLot,
  useReleaseLot,
  useDisposeLot,
} from "@/hooks/api/use-material-lots";
import type { MaterialLot, MaterialLotStatus } from "@/services/material-lot-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import {
  Button,
  Checkbox,
  StatusBadge,
  Spinner,
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Textarea,
} from "@godxjp/ui";

const LOT_STATUSES: MaterialLotStatus[] = [
  "active",
  "quarantined",
  "depleted",
  "expired",
  "disposed",
];

const FILTER_DEFAULTS = {
  search: "",
  status: "",
  trashed: "",
  per_page: "25",
};

export default function HqMaterialLotsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const router = useRouter();

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

  const showTrashed = urlFilters.trashed === "1";
  const statusFilter = urlFilters.status as MaterialLotStatus | "";
  const perPage = Number(urlFilters.per_page) || 25;

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([k, v]) => v !== FILTER_DEFAULTS[k as keyof typeof FILTER_DEFAULTS]
  );

  const { data, isLoading, isFetching, refetch } = useHqMaterialLots(brandSlug, {
    page,
    per_page: perPage,
    search: debouncedSearch || undefined,
    status: statusFilter || undefined,
    with_trashed: showTrashed || undefined,
  });

  const meta: PaginationMeta = data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: perPage,
    from: null,
    to: null,
  };

  // Selection
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const items = data?.data ?? [];

  function toggleSelectAll() {
    setSelected((prev) =>
      prev.size === items.length ? new Set() : new Set(items.map((i) => i.id))
    );
  }

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  // Mutations
  const bulkDelete = useBulkDeleteMaterialLots(brandSlug);
  const quarantine = useQuarantineLot(brandSlug);
  const release = useReleaseLot(brandSlug);
  const dispose = useDisposeLot(brandSlug);

  // Dialog states
  const [confirmBulk, setConfirmBulk] = useState(false);

  // Quarantine dialog — reason required (min:3, max:1000)
  const [quarantineTarget, setQuarantineTarget] = useState<MaterialLot | null>(null);
  const [quarantineReason, setQuarantineReason] = useState("");

  // Release dialog — note optional (max:1000)
  const [releaseTarget, setReleaseTarget] = useState<MaterialLot | null>(null);
  const [releaseNote, setReleaseNote] = useState("");

  // Dispose confirm dialog — reason optional (max:1000)
  const [disposeTarget, setDisposeTarget] = useState<MaterialLot | null>(null);
  const [disposeReason, setDisposeReason] = useState("");

  function openQuarantine(lot: MaterialLot) {
    setQuarantineTarget(lot);
    setQuarantineReason("");
  }

  function openRelease(lot: MaterialLot) {
    setReleaseTarget(lot);
    setReleaseNote("");
  }

  function openDispose(lot: MaterialLot) {
    setDisposeTarget(lot);
    setDisposeReason("");
  }

  const columns: ColumnDef<MaterialLot>[] = [
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
      accessorKey: "lot_code",
      header: t("material_lot.col_lot_code"),
      cell: ({ row }) => (
        <Link
          href={`/hq/${brandSlug}/material-lots/${row.original.id}`}
          className="font-medium text-primary hover:underline"
        >
          {row.original.lot_code}
        </Link>
      ),
    },
    {
      accessorKey: "material",
      header: t("material_lot.col_material"),
      cell: ({ row }) => (
        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
          {row.original.material?.sku ?? row.original.material_id}
        </code>
      ),
    },
    {
      accessorKey: "warehouse",
      header: t("material_lot.col_warehouse"),
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      accessorKey: "qty_on_hand",
      header: t("material_lot.col_qty_on_hand"),
      size: 110,
      cell: ({ row }) => (
        <span className="text-sm tabular-nums">
          {Number(row.original.qty_on_hand).toLocaleString(locale)} {row.original.unit}
        </span>
      ),
    },
    {
      accessorKey: "expiry_date",
      header: t("material_lot.col_expiry"),
      size: 120,
      cell: ({ row }) =>
        row.original.expiry_date ? (
          <span className="text-xs text-muted-foreground">
            {formatDate(row.original.expiry_date, locale, timezone)}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      accessorKey: "supplier_name",
      header: t("material_lot.col_supplier"),
      cell: ({ row }) => row.original.supplier_name ?? "—",
    },
    {
      id: "status",
      header: t("common.status"),
      size: 110,
      cell: ({ row }) => (
        <StatusBadge status={row.original.deleted_at ? "deleted" : row.original.status} />
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
        const lot = row.original;

        // Trashed → view only
        if (lot.deleted_at) {
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem
                  onClick={() => router.push(`/hq/${brandSlug}/material-lots/${lot.id}`)}
                >
                  <Eye className="mr-2 size-3.5" /> {t("common.view_details")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          );
        }

        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-7">
                <EllipsisVertical className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
              {/* 1. Navigate */}
              <DropdownMenuItem
                onClick={() => router.push(`/hq/${brandSlug}/material-lots/${lot.id}`)}
              >
                <Eye className="mr-2 size-3.5" /> {t("common.view_details")}
              </DropdownMenuItem>

              <DropdownMenuSeparator />

              {/* Workflow: Quarantine (active only) */}
              {lot.status === "active" && (
                <DropdownMenuItem onClick={() => openQuarantine(lot)}>
                  <Pause className="mr-2 size-3.5" /> {t("material_lot.action_quarantine")}
                </DropdownMenuItem>
              )}

              {/* Workflow: Release (quarantined only) */}
              {lot.status === "quarantined" && (
                <DropdownMenuItem onClick={() => openRelease(lot)}>
                  <Play className="mr-2 size-3.5" /> {t("material_lot.action_release")}
                </DropdownMenuItem>
              )}

              {/* Destructive: Dispose */}
              {(lot.status === "active" ||
                lot.status === "quarantined" ||
                lot.status === "expired") && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem className="text-destructive" onClick={() => openDispose(lot)}>
                    <Trash2 className="mr-2 size-3.5" /> {t("material_lot.action_dispose")}
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader
        title={t("material_lot.list_title")}
        description={`${meta.total}`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      />
      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("material_lot.search_placeholder")}
          showTrashed={showTrashed}
          onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && data === undefined}
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
          <Select
            value={statusFilter || "__all__"}
            onValueChange={(v) => setFilter("status", v === "__all__" ? "" : v)}
          >
            <SelectTrigger className="h-8 w-36 text-xs" aria-label={t("material_lot.col_status")}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{t("common.all_statuses")}</SelectItem>
              {LOT_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`material_lot.status.${s}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading ? (
          <DataTableSkeleton columns={columns.length} rows={8} />
        ) : (
          <>
            <DataTable columns={columns} data={items} />
            <Pagination
              meta={meta}
              page={page}
              onPageChange={setPage}
              perPage={perPage}
              onPerPageChange={(v) => setFilter("per_page", String(v))}
            />
          </>
        )}
      </PageContent>

      {/* ── Bulk delete confirm ── */}
      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        onConfirm={() => {
          bulkDelete.mutate([...selected], {
            onSuccess: () => setSelected(new Set()),
          });
          setConfirmBulk(false);
        }}
        description={t("material_lot.bulk_delete_desc", { n: selected.size })}
        isPending={bulkDelete.isPending}
      />

      {/* ── Quarantine dialog — reason required min:3 max:1000 ── */}
      <Dialog
        open={!!quarantineTarget}
        onOpenChange={(v) => {
          if (!v) {
            setQuarantineTarget(null);
            setQuarantineReason("");
          }
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t("material_lot.action_quarantine")}</DialogTitle>
            <DialogDescription>
              {quarantineTarget && (
                <>
                  {t("material_lot.quarantine_dialog_desc")}{" "}
                  <span className="font-semibold text-foreground">
                    &ldquo;{quarantineTarget.lot_code}&rdquo;
                  </span>
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Textarea
              value={quarantineReason}
              onChange={(e) => setQuarantineReason(e.target.value)}
              placeholder={t("material_lot.quarantine_reason_placeholder")}
              rows={4}
              maxLength={1000}
              className="field-sizing-fixed"
              disabled={quarantine.isPending}
              aria-label={t("material_lot.quarantine_reason")}
            />
            <div className="text-right text-[11px] text-muted-foreground">
              {quarantineReason.length}/1000
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setQuarantineTarget(null);
                setQuarantineReason("");
              }}
              disabled={quarantine.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              disabled={quarantineReason.trim().length < 3 || quarantine.isPending}
              onClick={() => {
                if (!quarantineTarget) return;
                quarantine.mutate(
                  { id: quarantineTarget.id, input: { reason: quarantineReason } },
                  {
                    onSuccess: () => {
                      setQuarantineTarget(null);
                      setQuarantineReason("");
                    },
                  }
                );
              }}
            >
              {quarantine.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("material_lot.action_quarantine")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Release dialog — note optional max:1000 ── */}
      <Dialog
        open={!!releaseTarget}
        onOpenChange={(v) => {
          if (!v) {
            setReleaseTarget(null);
            setReleaseNote("");
          }
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t("material_lot.action_release")}</DialogTitle>
            <DialogDescription>
              {releaseTarget && (
                <>
                  {t("material_lot.release_dialog_desc")}{" "}
                  <span className="font-semibold text-foreground">
                    &ldquo;{releaseTarget.lot_code}&rdquo;
                  </span>
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Textarea
              value={releaseNote}
              onChange={(e) => setReleaseNote(e.target.value)}
              placeholder={t("material_lot.release_note_placeholder")}
              rows={3}
              maxLength={1000}
              className="field-sizing-fixed"
              disabled={release.isPending}
              aria-label={t("material_lot.release_note_label")}
            />
            <div className="text-right text-[11px] text-muted-foreground">
              {releaseNote.length}/1000
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setReleaseTarget(null);
                setReleaseNote("");
              }}
              disabled={release.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              disabled={release.isPending}
              onClick={() => {
                if (!releaseTarget) return;
                release.mutate(
                  { id: releaseTarget.id, input: { note: releaseNote || undefined } },
                  {
                    onSuccess: () => {
                      setReleaseTarget(null);
                      setReleaseNote("");
                    },
                  }
                );
              }}
            >
              {release.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("material_lot.action_release")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Dispose dialog — reason optional max:1000 ── */}
      <Dialog
        open={!!disposeTarget}
        onOpenChange={(v) => {
          if (!v) {
            setDisposeTarget(null);
            setDisposeReason("");
          }
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t("material_lot.action_dispose")}</DialogTitle>
            <DialogDescription>
              {disposeTarget &&
                t("material_lot.dispose_confirm_description", {
                  qty: Number(disposeTarget.qty_on_hand).toLocaleString(locale),
                  unit: disposeTarget.unit ?? "",
                })}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Textarea
              value={disposeReason}
              onChange={(e) => setDisposeReason(e.target.value)}
              placeholder={t("material_lot.dispose_reason_placeholder")}
              rows={3}
              maxLength={1000}
              className="field-sizing-fixed"
              disabled={dispose.isPending}
              aria-label={t("material_lot.dispose_reason_label")}
            />
            <div className="text-right text-[11px] text-muted-foreground">
              {disposeReason.length}/1000
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setDisposeTarget(null);
                setDisposeReason("");
              }}
              disabled={dispose.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              disabled={dispose.isPending}
              onClick={() => {
                if (!disposeTarget) return;
                dispose.mutate(
                  {
                    id: disposeTarget.id,
                    input: { force: true, reason: disposeReason || undefined },
                  },
                  {
                    onSuccess: () => {
                      setDisposeTarget(null);
                      setDisposeReason("");
                    },
                  }
                );
              }}
            >
              {dispose.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("material_lot.action_dispose")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
