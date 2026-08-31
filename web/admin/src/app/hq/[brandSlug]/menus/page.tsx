"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import Link from "next/link";
import {
  ArrowUpDown,
  Check,
  Copy,
  CopyPlus,
  Crown,
  EllipsisVertical,
  GripVertical,
  Pencil,
  Play,
  Plus,
  Pause,
  RotateCcw,
  Send,
  Trash2,
  UtensilsCrossed,
  X,
} from "lucide-react";
import { toast } from "sonner";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { StatusBadge } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
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
  useMenus,
  useDeleteMenu,
  useRestoreMenu,
  useBulkDeleteMenus,
  useSubmitMenu,
  useApproveMenu,
  useRejectMenu,
  useActivateMenu,
  useDeactivateMenu,
  useSyncFromMaster,
  useCloneMenuToBranch,
  useDuplicateMenu,
} from "@/hooks/api/use-menus";
import { apiFetch } from "@/lib/api";
import { useShops } from "@/hooks/api/use-shops";
import type { Menu, MenuStatus } from "@/services/menu-service";
import { menuService } from "@/services/menu-service";
import { MenuFormDialog } from "./components/menu-form-dialog";
import { MasterMenuFormDialog } from "./components/master-menu-form-dialog";

import { Spinner } from "@godxjp/ui";
import { Pagination } from "@/components/shared/pagination";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
interface BrandResponse {
  data: { id: string; slug: string; name: string };
}

type MasterFilter = "master" | "branch";

const FILTER_DEFAULTS = {
  search: "",
  status: "",
  master: "master",
  branch: "",
  trashed: "",
  per_page: "25",
};

/**
 * Backend rules (MenuService):
 *   - delete: soft-delete allowed for all statuses (no Active restriction)
 *   - update (full): only Draft/Rejected; otherwise only metadata fields
 *   - submit: Draft/Rejected → Pending, requires ≥1 item
 *   - approve: Pending → Approved, no self-approve
 *   - reject: Pending → Rejected, requires reason
 *   - activate: Approved/Inactive → Active
 *   - deactivate: Active → Inactive
 */
function canDelete(_status: MenuStatus): boolean {
  return true;
}

// Formats an ISO date string (e.g. "2026-01-28T00:00:00Z") for badge display.
// Slices the date part directly — never constructs a Date object — so the
// displayed date is always the calendar date stored in the DB regardless of
// the viewer's local timezone (no UTC midnight → local-date shift).
// Format: "DD/MM" when same year as currentYear, "DD/MM/YY" otherwise.
function formatMenuDate(isoString: string, currentYear: number): string {
  const datePart = isoString.slice(0, 10); // "YYYY-MM-DD"
  const [yearStr, monthStr, dayStr] = datePart.split("-");
  return Number(yearStr) === currentYear
    ? `${dayStr}/${monthStr}`
    : `${dayStr}/${monthStr}/${yearStr.slice(2)}`;
}

export default function MenusPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const router = useRouter();
  const currentYear = useMemo(() => new Date().getFullYear(), []);

  const STATUS_OPTIONS = useMemo(
    () => [
      { value: "" as MenuStatus | "", label: t("common.all_statuses") },
      { value: "Draft" as MenuStatus, label: t("status.draft") },
      { value: "Pending" as MenuStatus, label: t("status.pending") },
      { value: "Approved" as MenuStatus, label: t("status.approved") },
      { value: "Active" as MenuStatus, label: t("common.active") },
      { value: "Inactive" as MenuStatus, label: t("common.inactive") },
      { value: "Rejected" as MenuStatus, label: t("status.rejected") },
    ],
    [t]
  );

  const MASTER_FILTER_OPTIONS = useMemo(
    () => [
      { value: "master" as MasterFilter, label: t("hq.menus.filter.master_only") },
      { value: "branch" as MasterFilter, label: t("hq.menus.filter.branch_only") },
    ],
    [t]
  );

  // Filter state (synced to URL search params)
  const {
    filters: urlFilters,
    page,
    setFilter,
    setPage,
    resetFilters,
  } = useSearchFilters(FILTER_DEFAULTS);
  const [search, setSearch] = useState(urlFilters.search);
  const debouncedSearch = useDebounce(search, 300);

  // Sync debounced search → URL
  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]);

  const statusFilter = urlFilters.status as MenuStatus | "";
  const masterFilter = (urlFilters.master || "master") as MasterFilter;
  const branchFilter = urlFilters.branch;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters =
    !!debouncedSearch ||
    !!statusFilter ||
    masterFilter !== "master" ||
    !!branchFilter ||
    showTrashed;

  // UI state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [formOpen, setFormOpen] = useState(false);
  const [masterFormOpen, setMasterFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Menu | null>(null);
  const [editMasterTarget, setEditMasterTarget] = useState<Menu | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<Menu | null>(null);
  const [rejectDialog, setRejectDialog] = useState<{ open: boolean; menuId: string | null }>({
    open: false,
    menuId: null,
  });
  const [rejectReason, setRejectReason] = useState("");
  const [cloneDialog, setCloneDialog] = useState<{ open: boolean; menu: Menu | null }>({
    open: false,
    menu: null,
  });
  const [cloneBranchId, setCloneBranchId] = useState("");
  const [cloneName, setCloneName] = useState("");
  // ── Inline reorder state ────────────────────────────────────────────────────
  const [reorderMode, setReorderMode] = useState<"branch" | "master" | null>(null);
  const isReordering = reorderMode !== null;
  const [reorderItems, setReorderItems] = useState<Menu[]>([]);
  const [hasReorderChanged, setHasReorderChanged] = useState(false);
  const [isSavingOrder, setIsSavingOrder] = useState(false);
  const [draggingIndex, setDraggingIndex] = useState<number | null>(null);
  const dragItemIndex = useRef<number | null>(null);
  const dragOverItemIndex = useRef<number | null>(null);

  // Brand info
  const { data: brandResponse } = useQuery({
    queryKey: ["hq", "brand", brandSlug],
    queryFn: () => apiFetch<BrandResponse>(`/api/v1/hq/${brandSlug}`),
    staleTime: 5 * 60 * 1000,
  });
  const brandId = brandResponse?.data.id ?? "";

  // Branches of this brand — used by the Clone-to-branch dialog so HQ users
  // pick from a real list instead of pasting UUIDs.
  const { data: shopsResponse, isLoading: shopsLoading } = useShops(brandSlug, {
    per_page: 100,
  });
  // Memoized so the reference is stable across renders — the branchNameById
  // map below (and any other hook deps) depend on it.
  const branches = useMemo(() => shopsResponse?.data ?? [], [shopsResponse?.data]);

  // Lookup branch name by id — the "Chi nhánh" column maps each branch menu's
  // branch_id to its name. Reuses the branches already loaded for the clone /
  // branch-filter selects; no extra request.
  const branchNameById = useMemo(() => new Map(branches.map((b) => [b.id, b.name])), [branches]);

  const filters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      status: statusFilter || undefined,
      is_master: masterFilter === "master" ? true : false,
      branch_id: masterFilter === "branch" && branchFilter ? branchFilter : undefined,
      with_trashed: showTrashed,
      sort: masterFilter === "branch" ? "priority" : "-updated_at",
    }),
    [
      page,
      urlFilters.per_page,
      debouncedSearch,
      statusFilter,
      masterFilter,
      branchFilter,
      showTrashed,
    ]
  );

  const { data: response, isLoading, refetch, isFetching } = useMenus(brandSlug, filters);
  const items = useMemo<Menu[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: 0,
    to: 0,
  };

  useEffect(() => {
    setSelected(new Set());
  }, [filters]);

  // Mutations
  const deleteOne = useDeleteMenu(brandSlug);
  const bulkDelete = useBulkDeleteMenus(brandSlug);
  const restore = useRestoreMenu(brandSlug);
  const submitMenu = useSubmitMenu(brandSlug);
  const approveMenu = useApproveMenu(brandSlug);
  const rejectMenu = useRejectMenu(brandSlug);
  const activateMenu = useActivateMenu(brandSlug);
  const deactivateMenu = useDeactivateMenu(brandSlug);
  const syncFromMaster = useSyncFromMaster(brandSlug);
  const cloneMenu = useCloneMenuToBranch(brandSlug);
  const duplicateMenu = useDuplicateMenu(brandSlug);

  async function handleDuplicate(menuId: string) {
    try {
      const copy = await duplicateMenu.mutateAsync(menuId);
      toast.success(t("hq.menus.actions.duplicate_success"));
      router.push(`/hq/${brandSlug}/menus/${copy.data.id}/items`);
    } catch {
      // duplicateMenu's onError already surfaces the toast.
    }
  }

  // Full lists for reorder — fetched when the corresponding tab is active
  const isBranchSelected = masterFilter === "branch" && !!branchFilter;
  const isMasterTab = masterFilter === "master";

  const { data: allBranchMenusResponse } = useMenus(
    brandSlug,
    isBranchSelected
      ? { branch_id: branchFilter, per_page: 999, is_master: false, sort: "priority" }
      : {}
  );
  const { data: allMasterMenusResponse } = useMenus(
    brandSlug,
    isMasterTab ? { is_master: true, per_page: 999, sort: "priority" } : {}
  );

  function enterReorderMode(mode: "branch" | "master") {
    const source =
      mode === "master"
        ? (allMasterMenusResponse?.data ?? items)
        : (allBranchMenusResponse?.data ?? items);
    const sorted = [...source].sort((a, b) => a.priority - b.priority);
    setReorderItems(sorted);
    setHasReorderChanged(false);
    setReorderMode(mode);
  }

  function exitReorderMode() {
    setReorderMode(null);
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

  async function handleSaveOrder() {
    setIsSavingOrder(true);
    try {
      const ids = reorderItems.map((m) => m.id);
      if (reorderMode === "master") {
        await menuService.reorderMasterMenus(brandSlug, { menu_ids: ids });
      } else {
        if (!branchFilter) return;
        await menuService.reorderMenus(brandSlug, { branch_id: branchFilter, menu_ids: ids });
      }
      setHasReorderChanged(false);
      setReorderMode(null);
      toast.success(t("hq.menus.reorder_saved"));
      refetch();
    } catch (e) {
      toast.error((e as Error).message || t("hq.menus.reorder_failed"));
    } finally {
      setIsSavingOrder(false);
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
    const deletableItems = items.filter((m) => !m.deleted_at && canDelete(m.status));
    if (selected.size === deletableItems.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(deletableItems.map((m) => m.id)));
    }
  }

  function openCreate() {
    setEditTarget(null);
    setFormOpen(true);
  }

  function openEdit(m: Menu) {
    // Master menus use the dedicated master dialog (name + description only).
    // Branch menus use MenuFormDialog which exposes valid_from/to in a
    // collapsible section on the edit form (create omits those fields).
    if (m.is_master) {
      setEditMasterTarget(m);
      setMasterFormOpen(true);
    } else {
      setEditTarget(m);
      setFormOpen(true);
    }
  }

  async function handleBulkDelete() {
    const ids = Array.from(selected);
    await bulkDelete.mutateAsync(ids);
    setSelected(new Set());
    setConfirmBulk(false);
  }

  function handleRejectConfirm() {
    if (rejectDialog.menuId && rejectReason.trim()) {
      rejectMenu.mutate(
        { id: rejectDialog.menuId, reason: rejectReason },
        {
          onSuccess: () => {
            setRejectDialog({ open: false, menuId: null });
            setRejectReason("");
          },
        }
      );
    }
  }

  function openCloneDialog(m: Menu) {
    setCloneDialog({ open: true, menu: m });
    setCloneBranchId("");
    setCloneName("");
  }

  function handleCloneConfirm() {
    if (!cloneDialog.menu || !cloneBranchId.trim()) return;
    cloneMenu.mutate(
      {
        masterMenuId: cloneDialog.menu.id,
        data: {
          branch_id: cloneBranchId.trim(),
          name: cloneName.trim() || undefined,
        },
      },
      {
        onSuccess: () => {
          setCloneDialog({ open: false, menu: null });
          setCloneBranchId("");
          setCloneName("");
        },
      }
    );
  }

  const columns: ColumnDef<Menu>[] = useMemo(
    () => [
      {
        id: "select",
        size: 36,
        header: () => {
          const deletableItems = items.filter((m) => !m.deleted_at && canDelete(m.status));
          return (
            <Checkbox
              checked={deletableItems.length > 0 && selected.size === deletableItems.length}
              onCheckedChange={toggleSelectAll}
              aria-label="Select all"
            />
          );
        },
        cell: ({ row }) => {
          const m = row.original;
          if (m.deleted_at) return null;
          return (
            <Checkbox
              checked={selected.has(m.id)}
              onCheckedChange={() => toggleSelect(m.id)}
              disabled={!canDelete(m.status)}
              aria-label="Select row"
            />
          );
        },
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
        size: 260,
        cell: ({ row }) => {
          const m = row.original;
          return (
            <div className="flex flex-col">
              <div className="flex items-center gap-1.5">
                <Link
                  href={`/hq/${brandSlug}/menus/${m.id}/items`}
                  className="font-medium text-primary hover:underline"
                >
                  {m.name}
                </Link>
                {m.is_master && (
                  <span
                    className="inline-flex h-4 items-center gap-0.5 rounded bg-purple-100 px-1 text-[10px] font-medium text-purple-700"
                    title="Master menu — template that can be cloned to branches"
                  >
                    <Crown className="size-2.5" /> {t("hq.menus.master_badge")}
                  </span>
                )}
              </div>
              {m.status === "Rejected" && m.rejection_reason && (
                <span className="line-clamp-2 text-xs text-red-700" title={m.rejection_reason}>
                  Lý do: {m.rejection_reason}
                </span>
              )}
            </div>
          );
        },
      },
      {
        id: "status",
        header: t("common.status"),
        size: 100,
        cell: ({ row }) => {
          const m = row.original;
          if (m.deleted_at) return <StatusBadge status="deleted" />;
          return <StatusBadge status={m.status.toLowerCase()} />;
        },
      },
      {
        id: "service_type",
        header: t("hq.menus.form.service_type"),
        size: 110,
        cell: ({ row }) => {
          const st = row.original.service_type ?? "Both";
          const cls =
            st === "Takeaway"
              ? "bg-amber-50 text-amber-700"
              : st === "DineIn"
                ? "bg-emerald-50 text-emerald-700"
                : "bg-slate-100 text-slate-600";
          const key =
            st === "Takeaway"
              ? "hq.menus.form.service_type_takeaway"
              : st === "DineIn"
                ? "hq.menus.form.service_type_dinein"
                : "hq.menus.form.service_type_both";
          return (
            <span
              className={`inline-flex h-5 items-center rounded px-1.5 text-xs font-medium ${cls}`}
            >
              {t(key)}
            </span>
          );
        },
      },
      {
        id: "priority",
        header: t("hq.menus.col.priority"),
        size: 80,
        cell: ({ row }) => (
          <span className="inline-flex h-5 items-center rounded bg-blue-50 px-1.5 text-xs font-medium text-blue-700">
            {row.original.priority}
          </span>
        ),
      },
      {
        id: "valid_period",
        header: t("hq.menus.col.valid_period"),
        size: 180,
        cell: ({ row }) => {
          const m = row.original;
          if (!m.valid_from && !m.valid_to) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <span className="text-xs text-muted-foreground">
              {m.valid_from ? formatMenuDate(m.valid_from, currentYear) : "—"}
              {" ~ "}
              {m.valid_to ? formatMenuDate(m.valid_to, currentYear) : "—"}
            </span>
          );
        },
      },
      {
        id: "menu_products_count",
        header: t("hq.menus.col.products"),
        size: 70,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.menu_products_count ?? 0}
          </span>
        ),
      },
      {
        id: "branch",
        header: t("hq.menus.col.branch"),
        size: 150,
        cell: ({ row }) => {
          const m = row.original;
          // Master menus aren't applied to a single branch — they're cloned
          // down to N branches. Show that count (cloned_menus_count) instead.
          if (m.is_master) {
            const count = m.cloned_menus_count ?? 0;
            return count > 0 ? (
              <span className="text-xs text-muted-foreground">
                {t("hq.menus.col.applied_branches", { count })}
              </span>
            ) : (
              <span className="text-xs text-muted-foreground">—</span>
            );
          }
          // Branch menu — resolve branch_id to its name via the loaded list.
          const name = m.branch_id ? branchNameById.get(m.branch_id) : null;
          if (!name) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <span className="inline-flex h-5 items-center rounded bg-emerald-50 px-1.5 text-xs font-medium text-emerald-700">
              {name}
            </span>
          );
        },
      },
      {
        accessorKey: "updated_at",
        header: t("common.updated"),
        size: 100,
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
          if (m.deleted_at) {
            return (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="size-7">
                    <EllipsisVertical className="size-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => restore.mutate(m.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            );
          }
          const productsCount = m.menu_products_count ?? 0;
          const canSubmit = (m.status === "Draft" || m.status === "Rejected") && productsCount > 0;
          const submitBlockedReason =
            productsCount === 0 ? "Cần ít nhất một sản phẩm trước khi gửi duyệt" : "";

          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem
                  onClick={() => router.push(`/hq/${brandSlug}/menus/${m.id}/items`)}
                >
                  <UtensilsCrossed className="mr-2 size-3.5" /> {t("hq.menus.actions.manage_items")}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => openEdit(m)}>
                  <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => handleDuplicate(m.id)}>
                  <CopyPlus className="mr-2 size-3.5" /> {t("hq.menus.actions.duplicate")}
                </DropdownMenuItem>

                {/* Clone master → branch — only allowed when master is Active. */}
                {m.is_master && m.status === "Active" && (
                  <DropdownMenuItem onClick={() => openCloneDialog(m)}>
                    <Copy className="mr-2 size-3.5" /> {t("hq.menus.actions.clone")}
                  </DropdownMenuItem>
                )}

                {/* Sync from master (branch menus only) */}
                {m.master_menu_id && (
                  <DropdownMenuItem onClick={() => syncFromMaster.mutate(m.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("hq.menus.actions.sync")}
                  </DropdownMenuItem>
                )}

                {/* Workflow actions — mirror backend state machine */}
                {(m.status === "Draft" || m.status === "Rejected") && (
                  <DropdownMenuItem
                    onClick={() => canSubmit && submitMenu.mutate(m.id)}
                    disabled={!canSubmit}
                    title={submitBlockedReason}
                  >
                    <Send className="mr-2 size-3.5" />
                    {t("hq.menus.actions.submit")}
                  </DropdownMenuItem>
                )}
                {m.status === "Pending" && (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onClick={() => approveMenu.mutate(m.id)}>
                      <Check className="mr-2 size-3.5" /> {t("hq.menus.actions.approve")}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() => setRejectDialog({ open: true, menuId: m.id })}
                      className="text-destructive"
                    >
                      <X className="mr-2 size-3.5" /> {t("hq.menus.actions.reject")}
                    </DropdownMenuItem>
                  </>
                )}
                {(m.status === "Approved" || m.status === "Inactive") && (
                  <DropdownMenuItem onClick={() => activateMenu.mutate(m.id)}>
                    <Play className="mr-2 size-3.5" /> {t("hq.menus.actions.activate")}
                  </DropdownMenuItem>
                )}
                {m.status === "Active" && (
                  <DropdownMenuItem onClick={() => deactivateMenu.mutate(m.id)}>
                    <Pause className="mr-2 size-3.5" /> {t("hq.menus.actions.deactivate")}
                  </DropdownMenuItem>
                )}

                {/* Delete — allowed unless Active (backend enforces) */}
                {canDelete(m.status) && (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      onClick={() => setConfirmSingle(m)}
                      className="text-destructive"
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
    [
      t,
      brandSlug,
      router,
      selected,
      deleteOne,
      restore,
      submitMenu,
      approveMenu,
      activateMenu,
      deactivateMenu,
      syncFromMaster,
      meta.from,
      branchNameById,
    ]
  );

  return (
    <>
      <PageHeader
        title={t("hq.menus.title")}
        description={isReordering ? t("hq.menus.reorder_hint") : `${meta.total} total`}
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
              disabled={isSavingOrder}
            >
              <X className="size-3.5" />
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={handleSaveOrder}
              disabled={!hasReorderChanged || isSavingOrder}
            >
              {isSavingOrder && <Spinner className="size-3.5" />}
              {t("common.save_changes")}
            </Button>
          </>
        ) : (
          <>
            {isBranchSelected && (
              <Button
                variant="outline"
                size="sm"
                className="h-7 gap-1 text-xs"
                onClick={() => enterReorderMode("branch")}
              >
                <ArrowUpDown className="size-3.5" />
                {t("hq.menus.actions.reorder")}
              </Button>
            )}
            {isMasterTab && (
              <Button
                variant="outline"
                size="sm"
                className="h-7 gap-1 text-xs"
                onClick={() => enterReorderMode("master")}
              >
                <ArrowUpDown className="size-3.5" />
                {t("hq.menus.actions.reorder")}
              </Button>
            )}
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={() => setMasterFormOpen(true)}
            >
              <Crown className="size-3.5" />
              {t("hq.menus.actions.new_master")}
            </Button>
            <Button size="sm" className="h-7 gap-1 text-xs" onClick={openCreate}>
              <Plus className="size-3.5" />
              New
            </Button>
          </>
        )}
      </PageHeader>

      <PageContent>
        {isReordering ? (
          /* ── Inline drag-and-drop list ───────────────────────────────────── */
          <div className="flex flex-col gap-1">
            {reorderItems.map((menu, index) => {
              const isDragging = draggingIndex === index;
              return (
                <div
                  key={menu.id}
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

                  {/* Position badge */}
                  <span className="inline-flex size-6 shrink-0 items-center justify-center rounded bg-muted text-[11px] font-semibold text-muted-foreground tabular-nums">
                    {index + 1}
                  </span>

                  {/* Name */}
                  <span className="flex-1 truncate text-sm font-medium">{menu.name}</span>

                  {/* Valid period */}
                  {menu.valid_from && (
                    <span className="hidden shrink-0 text-[10px] text-muted-foreground sm:block">
                      {formatMenuDate(menu.valid_from, currentYear)}
                      {menu.valid_to && <> – {formatMenuDate(menu.valid_to, currentYear)}</>}
                    </span>
                  )}

                  {/* Status badge */}
                  <StatusBadge status={menu.status.toLowerCase()} />
                </div>
              );
            })}

            {reorderItems.length === 0 && (
              <div className="flex h-16 items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground">
                {t("hq.menus.empty")}
              </div>
            )}
          </div>
        ) : (
          /* ── Normal table view ───────────────────────────────────────────── */
          <>
            <ListPageToolbar
              search={search}
              onSearchChange={setSearch}
              searchPlaceholder={t("hq.menus.search_placeholder")}
              hasActiveFilters={hasActiveFilters}
              onClearFilters={() => {
                resetFilters();
                setSearch("");
              }}
              showTrashed={showTrashed}
              onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
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
              <Select
                value={statusFilter || "__all__"}
                onValueChange={(v) => setFilter("status", v === "__all__" ? "" : v)}
              >
                <SelectTrigger className="h-8 w-40 text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {STATUS_OPTIONS.map((o) => (
                    <SelectItem key={o.value || "__all__"} value={o.value || "__all__"}>
                      {o.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={masterFilter} onValueChange={(v) => setFilter("master", v)}>
                <SelectTrigger className="h-8 w-40 text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {MASTER_FILTER_OPTIONS.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                      {o.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {masterFilter === "branch" && (
                <Select
                  value={branchFilter || "__all__"}
                  onValueChange={(v) => setFilter("branch", v === "__all__" ? "" : v)}
                >
                  <SelectTrigger className="h-8 w-40 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__all__">{t("hq.menus.filter.all_shops")}</SelectItem>
                    {branches.map((b) => (
                      <SelectItem key={b.id} value={b.id}>
                        {b.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </ListPageToolbar>

            {isLoading && response === undefined ? (
              <DataTableSkeleton columns={9} />
            ) : (
              <DataTable columns={columns} data={items} emptyMessage={t("hq.menus.empty")} />
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

      {/* Create / Edit Form Dialog */}
      <MenuFormDialog
        brandSlug={brandSlug}
        brandId={brandId}
        open={formOpen}
        onOpenChange={(v) => {
          setFormOpen(v);
          if (!v) setEditTarget(null);
        }}
        menu={editTarget}
      />

      {/* Create / Edit Master Menu Dialog */}
      <MasterMenuFormDialog
        brandSlug={brandSlug}
        open={masterFormOpen}
        onOpenChange={(v) => {
          setMasterFormOpen(v);
          if (!v) setEditMasterTarget(null);
        }}
        menu={editMasterTarget}
      />

      {/* Reject Reason Dialog — BE requires a reason (1–1000 chars). */}
      <Dialog
        open={rejectDialog.open}
        onOpenChange={(v) => {
          if (!v) {
            setRejectDialog({ open: false, menuId: null });
            setRejectReason("");
          }
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t("hq.menus.reject_dialog.title")}</DialogTitle>
            <DialogDescription>
              {(() => {
                const target = items.find((x) => x.id === rejectDialog.menuId);
                return target ? (
                  <>
                    Nhập lý do từ chối cho{" "}
                    <span className="font-semibold text-foreground">
                      &ldquo;{target.name}&rdquo;
                    </span>
                    . Người tạo sẽ thấy lý do này khi mở menu.
                  </>
                ) : (
                  "Nhập lý do từ chối. Người tạo sẽ thấy lý do này khi mở menu."
                );
              })()}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              rows={4}
              maxLength={1000}
              placeholder={t("hq.menus.reject_dialog.reason_placeholder")}
              disabled={rejectMenu.isPending}
              aria-label={t("hq.menus.reject_dialog.title")}
              className="field-sizing-fixed"
            />
            <div className="text-right text-[11px] text-muted-foreground">
              {rejectReason.length}/1000
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setRejectDialog({ open: false, menuId: null });
                setRejectReason("");
              }}
              disabled={rejectMenu.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={handleRejectConfirm}
              disabled={!rejectReason.trim() || rejectMenu.isPending}
            >
              {rejectMenu.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("hq.menus.actions.reject")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Clone to Branch Dialog */}
      <Dialog
        open={cloneDialog.open}
        onOpenChange={(v) => {
          if (!v) {
            setCloneDialog({ open: false, menu: null });
            setCloneBranchId("");
            setCloneName("");
          }
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("hq.menus.clone.title")}</DialogTitle>
            <DialogDescription>
              {t("hq.menus.clone.desc", { name: cloneDialog.menu?.name ?? "" })}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-3 py-3">
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-muted-foreground">
                {t("hq.menus.clone.target_branch")} <span className="text-destructive">*</span>
              </label>
              <Select
                value={cloneBranchId || undefined}
                onValueChange={(v) => setCloneBranchId(v)}
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
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-muted-foreground">
                {t("hq.menus.clone.name_override")}
              </label>
              <Input
                placeholder={cloneDialog.menu?.name ?? ""}
                value={cloneName}
                onChange={(e) => setCloneName(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setCloneDialog({ open: false, menu: null });
                setCloneBranchId("");
                setCloneName("");
              }}
            >
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={handleCloneConfirm}
              disabled={!cloneBranchId.trim() || cloneMenu.isPending}
            >
              {cloneMenu.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("hq.menus.actions.clone")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle ? t("hq.menus.delete_confirm", { name: confirmSingle.name }) : ""
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
        title={t("hq.menus.bulk_delete_title", { n: selected.size })}
        description={t("hq.menus.bulk_delete_desc")}
        onConfirm={handleBulkDelete}
        isPending={bulkDelete.isPending}
      />
    </>
  );
}
