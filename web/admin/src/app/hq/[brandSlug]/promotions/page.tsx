"use client";
import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import {
  Badge,
  Card,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  StatusBadge,
} from "@godxjp/ui";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { useHqPromotions } from "@/hooks/api/use-menu-promotions";
import type { MenuPromotion } from "@/services/menu-promotion-service";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { BranchFilterSelect } from "../orders/components/branch-filter-select";

const FILTER_DEFAULTS = {
  search: "",
  branch: "all",
  currently_active: "0",
  sort: "total_discount_applied",
  trashed: "",
  per_page: "25",
};

export default function HqPromotionsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { brandSlug } = useParams<{ brandSlug: string }>();
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

  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  // Plan-019 — wire `search` into apiFilters (was previously a dead UI input
  // that displayed but never reached the backend).
  const apiFilters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      branch_id: urlFilters.branch === "all" ? undefined : urlFilters.branch,
      currently_active: urlFilters.currently_active === "1" || undefined,
      sort: urlFilters.sort as "total_discount_applied" | "created_at" | "name",
      with_trashed: showTrashed,
      page,
      // #1960 — the rows-per-page selector rendered the chosen value but the
      // request never carried it, so the list stayed at the backend default.
      per_page: Number(urlFilters.per_page) || 25,
    }),
    [
      debouncedSearch,
      urlFilters.branch,
      urlFilters.currently_active,
      urlFilters.sort,
      showTrashed,
      page,
      urlFilters.per_page,
    ]
  );

  const { data, isLoading, error, refetch, isFetching } = useHqPromotions(brandSlug, apiFilters);
  const promotions = data?.data ?? [];
  const meta = data?.meta;

  // KPI tiles — computed from current page. For brands with > per_page
  // promotions, the live/scheduled counts are page-local; total discount
  // sums the page's report aggregate. Acceptable for the typical case
  // (≤ 25 promotions per brand); follow-up Phase B can add a `/summary`
  // endpoint for brand-wide truth.
  const kpis = useMemo(() => {
    const live = promotions.filter((p) => p.currently_active).length;
    const scheduled = promotions.filter((p) => p.is_active && !p.currently_active).length;
    const inactive = promotions.filter((p) => !p.is_active).length;
    const totalDiscount = promotions.reduce(
      (sum, p) => sum + (p.report?.total_discount_applied ?? 0),
      0
    );
    return { live, scheduled, inactive, totalDiscount };
  }, [promotions]);

  const [previewPromotion, setPreviewPromotion] = useState<MenuPromotion | null>(null);

  const columns: ColumnDef<MenuPromotion>[] = [
    {
      id: "stt",
      header: t("hq.products.col.stt"),
      size: 50,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{(meta?.from ?? 1) + row.index}</span>
      ),
    },
    {
      id: "shop",
      header: t("hq.promotions.col.shop"),
      cell: ({ row }) => (
        <span className="text-sm">
          {row.original.branch_name ?? row.original.branch_id.slice(0, 8)}
        </span>
      ),
    },
    {
      id: "name",
      header: t("hq.promotions.col.name"),
      cell: ({ row }) => (
        <button
          type="button"
          onClick={() => setPreviewPromotion(row.original)}
          className="text-left text-sm font-medium text-primary hover:underline"
        >
          {row.original.name}
        </button>
      ),
    },
    {
      id: "percent",
      header: t("hq.promotions.col.discount"),
      cell: ({ row }) => (
        <span className="font-mono text-sm">−{row.original.discount_percent}%</span>
      ),
    },
    {
      id: "scope",
      header: t("hq.promotions.col.scope"),
      cell: ({ row }) => (
        <Badge variant="outline">{t(`shop.promotions.scope.${row.original.applies_to}`)}</Badge>
      ),
    },
    {
      id: "currently_active",
      header: t("common.status"),
      size: 120,
      cell: ({ row }) => {
        const p = row.original;
        if (p.deleted_at) return <StatusBadge status="deleted" />;
        if (p.currently_active) return <StatusBadge status="active" />;
        if (p.is_active) {
          return (
            <Badge variant="outline" className="border-amber-400 text-amber-600">
              {t("hq.promotions.status.scheduled_or_off_hour")}
            </Badge>
          );
        }
        return <StatusBadge status="inactive" />;
      },
    },
    {
      id: "total",
      header: t("hq.promotions.col.total_discount"),
      cell: ({ row }) => (
        <span className="text-sm tabular-nums">
          {(row.original.report?.total_discount_applied ?? 0).toLocaleString(locale)}
        </span>
      ),
    },
    {
      id: "items",
      header: t("hq.promotions.col.items"),
      cell: ({ row }) => (
        <span className="text-sm tabular-nums">
          {row.original.report?.items_with_promotion_count ?? 0}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("hq.promotions.title")}
        description={meta ? `${meta.total} ${t("shop.promotions.count_suffix")}` : undefined}
        onRefresh={refetch}
        isRefreshing={isFetching}
      />
      <PageContent>
        {/* KPI tiles — brand-wide quick glance.
            Computed from the current page; documented limitation. */}
        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
          <KpiTile label={t("hq.promotions.kpi.live")} value={kpis.live} tone="emerald" />
          <KpiTile label={t("hq.promotions.kpi.scheduled")} value={kpis.scheduled} tone="amber" />
          <KpiTile label={t("hq.promotions.kpi.inactive")} value={kpis.inactive} tone="slate" />
          <KpiTile
            label={t("hq.promotions.kpi.total_discount")}
            value={kpis.totalDiscount.toLocaleString(locale)}
            tone="primary"
          />
        </div>

        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.promotions.search_placeholder")}
          showTrashed={showTrashed}
          onShowTrashedChange={(v) => setFilter("trashed", v ? "1" : "")}
          hasActiveFilters={hasActiveFilters}
          onClearFilters={() => {
            resetFilters();
            setSearch("");
          }}
          isLoading={isLoading && data === undefined}
        >
          <BranchFilterSelect
            brandSlug={brandSlug}
            value={urlFilters.branch}
            onValueChange={(v) => setFilter("branch", v)}
          />
          <Select
            value={urlFilters.currently_active}
            onValueChange={(v) => setFilter("currently_active", v)}
          >
            <SelectTrigger
              data-slot="hq-promotion-currently-active-filter"
              className="h-8 w-40 text-sm"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="0">{t("hq.promotions.filter.currently_active_all")}</SelectItem>
              <SelectItem value="1">{t("hq.promotions.filter.currently_active_yes")}</SelectItem>
            </SelectContent>
          </Select>
          <Select value={urlFilters.sort} onValueChange={(v) => setFilter("sort", v)}>
            <SelectTrigger data-slot="hq-promotion-sort" className="h-8 w-44 text-sm">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="total_discount_applied">
                {t("hq.promotions.sort.total_discount_applied")}
              </SelectItem>
              <SelectItem value="created_at">{t("hq.promotions.sort.created_at")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">{t("common.load_error")}</div>
        )}

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={7} />
        ) : (
          <DataTable columns={columns} data={promotions} emptyMessage={t("hq.promotions.empty")} />
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

      <PromotionPreviewSheet
        promotion={previewPromotion}
        onClose={() => setPreviewPromotion(null)}
      />
    </>
  );
}

interface KpiTileProps {
  label: string;
  value: string | number;
  tone: "emerald" | "amber" | "slate" | "primary";
}

function KpiTile({ label, value, tone }: KpiTileProps) {
  const toneClass = {
    emerald: "text-emerald-600",
    amber: "text-amber-600",
    slate: "text-slate-600",
    primary: "text-primary",
  }[tone];
  return (
    <Card className="p-3" data-slot="hq-promotion-kpi">
      <div className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</div>
      <div className={`mt-1 text-2xl font-semibold tabular-nums ${toneClass}`}>{value}</div>
    </Card>
  );
}

/**
 * Read-only inline preview Sheet for an HQ-listed promotion. HQ users
 * have `viewAnyHq` permission via MenuPromotionPolicy but the per-shop
 * detail page enforces shop-scoped policies — so navigating to
 * `/shop/{slug}/promotions/{id}` could 403 for org-staff or HQ users
 * without shop access. The Sheet shows the same overview info inline
 * with no Edit/Toggle/Delete actions (those remain shop-manager only
 * per the plan-019 authorization matrix).
 */
function PromotionPreviewSheet({
  promotion,
  onClose,
}: {
  promotion: MenuPromotion | null;
  onClose: () => void;
}) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  return (
    <Sheet open={promotion !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="right" className="w-full sm:max-w-md">
        {promotion && (
          <>
            <SheetHeader>
              <SheetTitle className="flex items-center gap-2">
                {promotion.name}
                {promotion.deleted_at ? (
                  <StatusBadge status="deleted" />
                ) : promotion.currently_active ? (
                  <StatusBadge status="active" />
                ) : promotion.is_active ? (
                  <Badge variant="outline" className="border-amber-400 text-amber-600">
                    {t("hq.promotions.status.scheduled_or_off_hour")}
                  </Badge>
                ) : (
                  <StatusBadge status="inactive" />
                )}
              </SheetTitle>
              <SheetDescription>
                {promotion.branch_name ?? promotion.branch_id.slice(0, 8)}
              </SheetDescription>
            </SheetHeader>

            <div className="mt-4 flex flex-col gap-3 px-4 pb-4 text-sm">
              <PreviewRow label={t("shop.promotions.field.discount_percent")}>
                <span className="font-mono">−{promotion.discount_percent}%</span>
              </PreviewRow>
              <PreviewRow label={t("shop.promotions.col.scope")}>
                <Badge variant="outline">
                  {t(`shop.promotions.scope.${promotion.applies_to}`)}
                </Badge>
              </PreviewRow>
              <PreviewRow label={t("shop.promotions.col.stacking")}>
                <Badge
                  variant={
                    promotion.stacking_mode === "stackable_with_coupons" ? "default" : "outline"
                  }
                >
                  {promotion.stacking_mode === "stackable_with_coupons"
                    ? t("shop.promotions.stacking.stackable")
                    : t("shop.promotions.stacking.exclusive")}
                </Badge>
              </PreviewRow>
              {promotion.daily_time_from && promotion.daily_time_to && (
                <PreviewRow label={t("shop.promotions.field.daily_time_from")}>
                  {promotion.daily_time_from.slice(0, 5)}–{promotion.daily_time_to.slice(0, 5)}
                </PreviewRow>
              )}
              <PreviewRow label={t("shop.promotions.field.valid_from")}>
                {formatDate(promotion.valid_from, locale, timezone)}
              </PreviewRow>
              <PreviewRow label={t("shop.promotions.field.valid_until")}>
                {formatDate(promotion.valid_until, locale, timezone)}
              </PreviewRow>
              <div className="my-2 border-t" />
              <PreviewRow label={t("hq.promotions.col.total_discount")}>
                <span className="font-semibold">
                  {(promotion.report?.total_discount_applied ?? 0).toLocaleString(locale)}
                </span>
              </PreviewRow>
              <PreviewRow label={t("hq.promotions.col.items")}>
                {promotion.report?.items_with_promotion_count ?? 0}
              </PreviewRow>
              <p className="mt-2 text-[11px] text-muted-foreground">
                {t("hq.promotions.preview.readonly_note")}
              </p>
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}

function PreviewRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span>{children}</span>
    </div>
  );
}
