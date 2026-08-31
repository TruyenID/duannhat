"use client";
import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { EllipsisVertical, Eye, Pause, Pencil, Play, Plus, RotateCcw, Trash2 } from "lucide-react";
import { useRouter } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import {
  Badge,
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
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { HelpPanel } from "@/components/shared/help-panel";
import {
  useBulkDeleteCoupons,
  useCoupons,
  useDeleteCoupon,
  usePauseCoupon,
  useRestoreCoupon,
  useResumeCoupon,
} from "@/hooks/api/use-coupons";
import type { Coupon, CouponComputedStatus } from "@/services/coupon-service";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { BranchFilterSelect } from "../orders/components/branch-filter-select";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  branch: "all",
  trashed: "",
  per_page: "25",
};

const STATUS_OPTIONS: CouponComputedStatus[] = [
  "draft",
  "scheduled",
  "active",
  "paused",
  "expired",
  "exhausted",
];

type StatusVariant = "default" | "secondary" | "destructive" | "outline";

function statusBadgeVariant(s: CouponComputedStatus | undefined): StatusVariant {
  switch (s) {
    case "active":
      return "default";
    case "scheduled":
      return "outline";
    case "expired":
    case "exhausted":
      return "destructive";
    default:
      return "secondary";
  }
}

function formatDiscount(c: Coupon, locale: string): string {
  if (c.discount_type === "percent") {
    const cap = c.max_discount_cap ? ` (≤ ${c.max_discount_cap.toLocaleString(locale)})` : "";
    return `${c.discount_value}%${cap}`;
  }
  return `${c.discount_value.toLocaleString(locale)}`;
}

function formatWindow(
  c: Coupon,
  locale: Parameters<typeof formatDate>[1],
  timezone?: string
): string {
  return `${formatDate(c.valid_from, locale, timezone)} → ${formatDate(c.valid_until, locale, timezone)}`;
}

function formatUsage(c: Coupon): string {
  if (c.usage_limit_total == null) return `${c.times_used} / ∞`;
  return `${c.times_used} / ${c.usage_limit_total}`;
}

export default function HqCouponsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { brandSlug } = useParams<{ brandSlug: string }>();
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
  }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

  const status = urlFilters.status as CouponComputedStatus | "all";
  const branchId = urlFilters.branch;
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const apiFilters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      status: status === "all" ? undefined : status,
      branch_id: branchId === "all" ? undefined : branchId,
      with_trashed: showTrashed,
      page,
      // #1960 — see the customers screen: the selector rendered the chosen
      // value while the request kept asking for 25.
      per_page: Number(urlFilters.per_page) || 25,
    }),
    [debouncedSearch, status, branchId, showTrashed, page, urlFilters.per_page]
  );

  const { data, isLoading, error, refetch, isFetching } = useCoupons(brandSlug, apiFilters);
  const coupons = data?.data ?? [];
  const meta = data?.meta;

  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [pendingDelete, setPendingDelete] = useState<Coupon | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);

  const pauseMutation = usePauseCoupon(brandSlug);
  const resumeMutation = useResumeCoupon(brandSlug);
  const deleteMutation = useDeleteCoupon(brandSlug);
  const restoreMutation = useRestoreCoupon(brandSlug);
  const bulkDeleteMutation = useBulkDeleteCoupons(brandSlug);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    const deletableCoupons = coupons.filter((c) => !c.deleted_at && c.times_used === 0);
    if (selected.size === deletableCoupons.length && deletableCoupons.length > 0) {
      setSelected(new Set());
    } else {
      setSelected(new Set(deletableCoupons.map((c) => c.id)));
    }
  }

  const deletableCoupons = coupons.filter((c) => !c.deleted_at && c.times_used === 0);
  const allDeletableSelected =
    deletableCoupons.length > 0 && selected.size === deletableCoupons.length;

  const columns: ColumnDef<Coupon>[] = [
    {
      id: "select",
      size: 36,
      header: () => (
        <Checkbox
          checked={allDeletableSelected}
          onCheckedChange={toggleSelectAll}
          aria-label="Select all"
        />
      ),
      cell: ({ row }) => {
        const c = row.original;
        if (c.deleted_at || c.times_used > 0) return null;
        return (
          <Checkbox
            checked={selected.has(c.id)}
            onCheckedChange={() => toggleSelect(c.id)}
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
        <span className="text-xs text-muted-foreground">{(meta?.from ?? 1) + row.index}</span>
      ),
    },
    {
      id: "code",
      header: t("hq.coupons.col.code"),
      size: 140,
      cell: ({ row }) => (
        <Link
          href={`/hq/${brandSlug}/coupons/${row.original.id}`}
          className="font-mono font-medium text-primary hover:underline"
        >
          {row.original.code}
        </Link>
      ),
    },
    {
      id: "name",
      header: t("hq.coupons.col.name"),
      size: 220,
      cell: ({ row }) => <span className="text-sm">{row.original.name}</span>,
    },
    {
      id: "type",
      header: t("hq.coupons.col.type"),
      size: 90,
      cell: ({ row }) => (
        <Badge variant="outline" className="font-mono">
          {row.original.discount_type}
        </Badge>
      ),
    },
    {
      id: "value",
      header: t("hq.coupons.col.value"),
      size: 120,
      cell: ({ row }) => <span className="text-sm">{formatDiscount(row.original, locale)}</span>,
    },
    {
      id: "window",
      header: t("hq.coupons.col.window"),
      size: 180,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatWindow(row.original, locale, timezone)}
        </span>
      ),
    },
    {
      id: "usage",
      header: t("hq.coupons.col.usage"),
      size: 100,
      cell: ({ row }) => <span className="text-xs">{formatUsage(row.original)}</span>,
    },
    {
      id: "status",
      header: t("hq.coupons.col.status"),
      size: 110,
      cell: ({ row }) => {
        const c = row.original;
        if (c.deleted_at) {
          return <StatusBadge status="deleted" />;
        }
        const s = c.computed_status ?? c.status;
        return (
          <Badge variant={statusBadgeVariant(s as CouponComputedStatus)}>
            {t(`hq.coupons.status.${s}`) || s}
          </Badge>
        );
      },
    },
    {
      id: "actions",
      size: 50,
      header: t("common.action"),
      cell: ({ row }) => {
        const c = row.original;

        if (c.deleted_at) {
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => restoreMutation.mutate(c.id)}>
                  <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          );
        }

        const s = c.computed_status ?? c.status;
        const canDelete = c.times_used === 0;

        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-7">
                <EllipsisVertical className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuItem onClick={() => router.push(`/hq/${brandSlug}/coupons/${c.id}`)}>
                <Eye className="mr-2 size-3.5" /> {t("common.view")}
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => router.push(`/hq/${brandSlug}/coupons/${c.id}/edit`)}
              >
                <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
              </DropdownMenuItem>
              {s !== "paused" ? (
                <DropdownMenuItem onSelect={() => pauseMutation.mutate(c.id)}>
                  <Pause className="mr-2 size-3.5" /> {t("hq.coupons.action.pause")}
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem onSelect={() => resumeMutation.mutate(c.id)}>
                  <Play className="mr-2 size-3.5" /> {t("hq.coupons.action.resume")}
                </DropdownMenuItem>
              )}
              {canDelete && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    className="text-destructive focus:text-destructive"
                    onClick={() => setPendingDelete(c)}
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
  ];

  return (
    <>
      <PageHeader
        title={t("hq.coupons.title")}
        description={meta ? `${meta.total} ${t("hq.coupons.count_suffix")}` : undefined}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button asChild size="sm">
          <Link href={`/hq/${brandSlug}/coupons/new`}>
            <Plus className="mr-2 size-3.5" /> {t("hq.coupons.action.new")}
          </Link>
        </Button>
        <HelpPanel
          title={t("hq.coupons.title")}
          subtitle={t("help.panel.hq_coupons.subtitle")}
          purpose={t("help.panel.hq_coupons.purpose")}
          usage={[
            t("help.panel.hq_coupons.usage.1"),
            t("help.panel.hq_coupons.usage.2"),
            t("help.panel.hq_coupons.usage.3"),
          ]}
          checks={[
            t("help.panel.hq_coupons.checks.1"),
            t("help.panel.hq_coupons.checks.2"),
            t("help.panel.hq_coupons.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.hq_coupons.glossary.usage.term"),
              description: t("help.panel.hq_coupons.glossary.usage.desc"),
            },
            {
              term: t("help.panel.hq_coupons.glossary.value.term"),
              description: t("help.panel.hq_coupons.glossary.value.desc"),
            },
            {
              term: t("help.panel.hq_coupons.glossary.window.term"),
              description: t("help.panel.hq_coupons.glossary.window.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.coupons.search_placeholder")}
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
              disabled={bulkDeleteMutation.isPending}
            >
              <Trash2 className="size-3.5" />
              {t("common.delete_selected", { n: selected.size })}
            </Button>
          }
        >
          <Select value={status} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger data-slot="coupon-status-filter" className="h-8 w-40 text-sm">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.coupons.filter.all_statuses")}</SelectItem>
              {STATUS_OPTIONS.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`hq.coupons.status.${s}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <BranchFilterSelect
            brandSlug={brandSlug}
            value={branchId}
            onValueChange={(v) => setFilter("branch", v)}
          />
        </ListPageToolbar>

        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">{t("common.load_error")}</div>
        )}

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={10} />
        ) : (
          <DataTable columns={columns} data={coupons} emptyMessage={t("hq.coupons.empty")} />
        )}

        <Pagination
          meta={
            meta ?? {
              current_page: 1,
              last_page: 1,
              total: 0,
              per_page: Number(urlFilters.per_page) || 25,
            }
          }
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <DeleteConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && setPendingDelete(null)}
        title={t("hq.coupons.delete_dialog.title")}
        description={t("hq.coupons.delete_dialog.description", {
          code: pendingDelete?.code ?? "",
        })}
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          if (pendingDelete) {
            deleteMutation.mutate(pendingDelete.id, {
              onSuccess: () => {
                setSelected((prev) => {
                  const next = new Set(prev);
                  next.delete(pendingDelete.id);
                  return next;
                });
              },
              onSettled: () => setPendingDelete(null),
            });
          }
        }}
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={(open) => !open && setConfirmBulk(false)}
        title={t("common.delete_selected", { n: selected.size })}
        description={t("hq.coupons.bulk_delete_dialog.description", { n: selected.size })}
        isPending={bulkDeleteMutation.isPending}
        onConfirm={() => {
          bulkDeleteMutation.mutate([...selected], {
            onSuccess: () => setSelected(new Set()),
            onSettled: () => setConfirmBulk(false),
          });
        }}
      />
    </>
  );
}
