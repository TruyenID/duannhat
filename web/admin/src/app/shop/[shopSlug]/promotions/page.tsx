"use client";
import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { EllipsisVertical, Eye, Pencil, Plus, Power, RotateCcw, Trash2 } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
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
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import {
  useBulkDeleteShopPromotions,
  useDeleteShopPromotion,
  useRestoreShopPromotion,
  useShopPromotions,
  useToggleShopPromotion,
} from "@/hooks/api/use-menu-promotions";
import type { MenuPromotion } from "@/services/menu-promotion-service";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";

const FILTER_DEFAULTS = {
  search: "",
  is_active: "all",
  currently_active: "0",
  trashed: "",
  per_page: "25",
};

function formatTimeWindow(
  p: MenuPromotion,
  locale: Parameters<typeof formatDate>[1],
  timezone?: string
): string {
  const dailyFrom = p.daily_time_from?.slice(0, 5) ?? null;
  const dailyTo = p.daily_time_to?.slice(0, 5) ?? null;
  if (!dailyFrom || !dailyTo) {
    return formatDate(p.valid_from, locale, timezone);
  }
  return `${dailyFrom}–${dailyTo}`;
}

export default function ShopPromotionsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { shopSlug } = useParams<{ shopSlug: string }>();
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
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [confirmSingle, setConfirmSingle] = useState<MenuPromotion | null>(null);
  const [confirmBulk, setConfirmBulk] = useState(false);

  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) setFilter("search", debouncedSearch);
  }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const apiFilters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      is_active: urlFilters.is_active === "all" ? undefined : urlFilters.is_active === "active",
      currently_active: urlFilters.currently_active === "1" || undefined,
      with_trashed: showTrashed || undefined,
      page,
      // #1960 — the rows-per-page selector rendered the chosen value but the
      // request never carried it, so the list stayed at the backend default.
      per_page: Number(urlFilters.per_page) || 25,
    }),
    [debouncedSearch, urlFilters.is_active, urlFilters.currently_active, showTrashed, page, urlFilters.per_page]
  );

  const { data, isLoading, error, refetch, isFetching } = useShopPromotions(shopSlug, apiFilters);
  const promotions = data?.data ?? [];
  const meta = data?.meta;

  const toggleMutation = useToggleShopPromotion(shopSlug);
  const deleteMutation = useDeleteShopPromotion(shopSlug);
  const restoreMutation = useRestoreShopPromotion(shopSlug);
  const bulkDeleteMutation = useBulkDeleteShopPromotions(shopSlug, (deletedIds) => {
    setSelected((prev) => {
      const next = new Set(prev);
      for (const id of deletedIds) next.delete(id);
      return next;
    });
    setConfirmBulk(false);
  });

  const toggleSelectAll = () => {
    const selectable = promotions.filter((p) => !p.deleted_at).map((p) => p.id);
    if (selected.size === selectable.length && selectable.length > 0) {
      setSelected(new Set());
    } else {
      setSelected(new Set(selectable));
    }
  };

  const toggleSelect = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const selectableCount = promotions.filter((p) => !p.deleted_at).length;

  const columns: ColumnDef<MenuPromotion>[] = [
    {
      id: "select",
      size: 36,
      header: () => (
        <Checkbox
          checked={selectableCount > 0 && selected.size === selectableCount}
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
        <span className="text-xs text-muted-foreground">{(meta?.from ?? 1) + row.index}</span>
      ),
    },
    {
      id: "name",
      header: t("shop.promotions.col.name"),
      size: 240,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/promotions/${row.original.id}`}
          className="font-medium text-primary hover:underline"
        >
          {row.original.name}
        </Link>
      ),
    },
    {
      id: "percent",
      header: t("shop.promotions.col.discount"),
      size: 90,
      cell: ({ row }) => (
        <span className="font-mono text-sm">−{row.original.discount_percent}%</span>
      ),
    },
    {
      id: "scope",
      header: t("shop.promotions.col.scope"),
      size: 120,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {t(`shop.promotions.scope.${row.original.applies_to}`)}
        </span>
      ),
    },
    {
      id: "window",
      header: t("shop.promotions.col.window"),
      size: 140,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatTimeWindow(row.original, locale, timezone)}
        </span>
      ),
    },
    {
      id: "status",
      header: t("common.status"),
      size: 100,
      cell: ({ row }) => {
        const p = row.original;
        const status = p.deleted_at ? "deleted" : p.is_active ? "active" : "inactive";
        return <StatusBadge status={status} />;
      },
    },
    {
      id: "updated_at",
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
        const p = row.original;

        if (p.deleted_at) {
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => restoreMutation.mutate(p.id)}>
                  <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
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
              <DropdownMenuItem onClick={() => router.push(`/shop/${shopSlug}/promotions/${p.id}`)}>
                <Eye className="mr-2 size-3.5" /> {t("common.view")}
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => router.push(`/shop/${shopSlug}/promotions/${p.id}/edit`)}
              >
                <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={() => toggleMutation.mutate(p.id)}>
                <Power className="mr-2 size-3.5" />{" "}
                {p.is_active
                  ? t("shop.promotions.action.deactivate")
                  : t("shop.promotions.action.activate")}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                className="text-destructive focus:text-destructive"
                onSelect={() => setConfirmSingle(p)}
              >
                <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader
        title={t("shop.promotions.title")}
        description={meta ? `${meta.total} ${t("shop.promotions.count_suffix")}` : undefined}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button asChild size="sm">
          <Link href={`/shop/${shopSlug}/promotions/new`}>
            <Plus className="mr-2 size-3.5" /> {t("shop.promotions.action.new")}
          </Link>
        </Button>
        <HelpPanel
          title={t("shop.promotions.title")}
          subtitle={t("help.panel.shop_promotions.subtitle")}
          purpose={t("help.panel.shop_promotions.purpose")}
          usage={[
            t("help.panel.shop_promotions.usage.1"),
            t("help.panel.shop_promotions.usage.2"),
            t("help.panel.shop_promotions.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_promotions.checks.1"),
            t("help.panel.shop_promotions.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_promotions.glossary.discount.term"),
              description: t("help.panel.shop_promotions.glossary.discount.desc"),
            },
            {
              term: t("help.panel.shop_promotions.glossary.scope.term"),
              description: t("help.panel.shop_promotions.glossary.scope.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("shop.promotions.search_placeholder")}
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
          <Select value={urlFilters.is_active} onValueChange={(v) => setFilter("is_active", v)}>
            <SelectTrigger data-slot="promotion-active-filter" className="h-8 w-36 text-sm">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("shop.promotions.filter.all")}</SelectItem>
              <SelectItem value="active">{t("shop.promotions.filter.active")}</SelectItem>
              <SelectItem value="inactive">{t("shop.promotions.filter.inactive")}</SelectItem>
            </SelectContent>
          </Select>
          <Select
            value={urlFilters.currently_active}
            onValueChange={(v) => setFilter("currently_active", v)}
          >
            <SelectTrigger
              data-slot="promotion-currently-active-filter"
              className="h-8 w-40 text-sm"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="0">{t("shop.promotions.filter.currently_active_all")}</SelectItem>
              <SelectItem value="1">{t("shop.promotions.filter.currently_active_yes")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {error && !isLoading && (
          <div className="py-12 text-center text-sm text-red-500">{t("common.load_error")}</div>
        )}

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={9} />
        ) : (
          <DataTable
            columns={columns}
            data={promotions}
            emptyMessage={t("shop.promotions.empty")}
          />
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
        open={confirmSingle !== null}
        onOpenChange={(open) => !open && setConfirmSingle(null)}
        title={t("shop.promotions.delete_dialog.title")}
        description={t("shop.promotions.delete_dialog.description", {
          name: confirmSingle?.name ?? "",
        })}
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          if (confirmSingle) {
            deleteMutation.mutate(confirmSingle.id, {
              onSuccess: () => {
                setSelected((prev) => {
                  const next = new Set(prev);
                  next.delete(confirmSingle.id);
                  return next;
                });
              },
              onSettled: () => setConfirmSingle(null),
            });
          }
        }}
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={(open) => !open && setConfirmBulk(false)}
        title={t("shop.promotions.bulk_delete_dialog.title")}
        description={t("shop.promotions.bulk_delete_dialog.description", { n: selected.size })}
        isPending={bulkDeleteMutation.isPending}
        onConfirm={() => bulkDeleteMutation.mutate([...selected])}
      />
    </>
  );
}
