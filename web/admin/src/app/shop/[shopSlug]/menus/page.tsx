"use client";

/**
 * Shop Menu list page — `/shop/{shopSlug}/menu`.
 *
 * Lists the Active branch menus that HQ has cloned down to this shop.
 * Clicking a card drills into the per-menu detail page where shop staff
 * toggle availability and override selling price (see
 * `./[menuId]/page.tsx` and plan-004 DESIGN.md Screen 1).
 *
 * Patterns copied verbatim from `tables/page.tsx`:
 *   - "use client" + useParams + useTranslation
 *   - PageHeader / PageContent shell
 *   - Card grid with compact JP density (text-sm, gap-3)
 *   - Loader2 + empty state with lucide icon
 */

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ChevronRight, RefreshCw, Search, UtensilsCrossed, X } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { Pagination } from "@/components/shared/pagination";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useShopMenus, useSyncShopMenuFromMaster } from "@/hooks/api/use-shop-menus";
import type { MenuStatus, ShopMenuResource } from "@/types/shop";

// Sentinel: backend treats empty `status` as "Active by default", so we hide
// the "All" option and keep an explicit per-status filter.
const STATUS_OPTIONS: MenuStatus[] = [
  "Active",
  "Inactive",
  "Approved",
  "Pending",
  "Draft",
  "Rejected",
];

const FILTER_DEFAULTS = {
  search: "",
  status: "Active",
  per_page: "25",
};

export default function ShopMenuListPage() {
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const { t } = useTranslation();

  // ---- Filters (URL-synced via useSearchFilters) ----
  const { filters: urlFilters, page, setFilter, setPage } = useSearchFilters(FILTER_DEFAULTS);

  // Local search input trails the URL via `useDebounce`. The debounce source
  // is the input; the URL only updates once the user pauses typing.
  const [searchInput, setSearchInput] = useState(urlFilters.search);
  const debouncedSearch = useDebounce(searchInput.trim(), 400);

  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]);

  const statusFilter = urlFilters.status as MenuStatus;
  const perPage = Number(urlFilters.per_page) || 25;

  const menusQuery = useShopMenus(shopSlug, {
    status: statusFilter,
    search: debouncedSearch || undefined,
    page,
    per_page: perPage,
  });

  const menus = useMemo<ShopMenuResource[]>(() => menusQuery.data?.data ?? [], [menusQuery.data]);
  const meta = menusQuery.data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: perPage,
    from: null,
    to: null,
  };

  const isLoading = menusQuery.isLoading;
  const isFetching = menusQuery.isFetching;
  const refetch = menusQuery.refetch;
  const isEmpty = !isLoading && menus.length === 0;

  return (
    <>
      <PageHeader
        title={t("shop.menu.title")}
        description={
          isLoading ? t("shop.menu.loading") : t("shop.menu.summary", { count: menus.length })
        }
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("shop.menu.title")}
          subtitle={t("help.panel.shop_menus.subtitle")}
          purpose={t("help.panel.shop_menus.purpose")}
          usage={[
            t("help.panel.shop_menus.usage.1"),
            t("help.panel.shop_menus.usage.2"),
            t("help.panel.shop_menus.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_menus.checks.1"),
            t("help.panel.shop_menus.checks.2"),
            t("help.panel.shop_menus.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_menus.glossary.master.term"),
              description: t("help.panel.shop_menus.glossary.master.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        {/* Filter bar — server-side via shopMenu list endpoint */}
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <div className="relative max-w-md min-w-55 flex-1">
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={searchInput}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) => setSearchInput(e.target.value)}
              placeholder={t("shop.menu.search_placeholder")}
              className="h-8 pr-8 pl-8 text-sm"
              data-testid="shop-menu-list-search"
            />
            {searchInput && (
              <button
                type="button"
                onClick={() => setSearchInput("")}
                className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                aria-label="Clear search"
              >
                {isFetching ? <Spinner className="size-3.5" /> : <X className="size-3.5" />}
              </button>
            )}
          </div>

          <Select value={statusFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-36 text-xs" data-testid="shop-menu-list-status">
              <SelectValue placeholder={t("shop.menu.status_label")} />
            </SelectTrigger>
            <SelectContent>
              {STATUS_OPTIONS.map((s) => (
                <SelectItem key={s} value={s}>
                  {s}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* HQ-managed banner — shop staff can only edit price + variant
            visibility once they drill into a menu. */}
        {isLoading && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("shop.menu.loading")}
          </div>
        )}

        {isEmpty && (
          <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-10 text-center">
            <UtensilsCrossed className="size-8 text-muted-foreground" />
            <p className="mt-3 text-sm font-medium">{t("shop.menu.empty_title")}</p>
            <p className="mt-1 text-xs text-muted-foreground">{t("shop.menu.empty_description")}</p>
          </div>
        )}

        {!isLoading && menus.length > 0 && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {menus.map((menu) => (
              <MenuCard key={menu.id} shopSlug={shopSlug} menu={menu} />
            ))}
          </div>
        )}

        <Pagination
          meta={meta}
          page={page}
          onPageChange={setPage}
          perPage={perPage}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>
    </>
  );
}

// ---------------------------------------------------------------------------
// Menu card
// ---------------------------------------------------------------------------

interface MenuCardProps {
  shopSlug: string;
  menu: ShopMenuResource;
}

function MenuCard({ shopSlug, menu }: MenuCardProps) {
  const { t } = useTranslation();
  const router = useRouter();
  const href = `/shop/${shopSlug}/menus/${menu.id}`;
  const syncMut = useSyncShopMenuFromMaster(shopSlug, menu.id);
  const [confirmSyncOpen, setConfirmSyncOpen] = useState(false);

  const canSync = Boolean(menu.master_menu_id);

  const handleCardClick = () => {
    router.push(href);
  };

  const handleCardKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      router.push(href);
    }
  };

  return (
    <>
      <Card
        role="link"
        tabIndex={0}
        onClick={handleCardClick}
        onKeyDown={handleCardKeyDown}
        className="group cursor-pointer transition-colors hover:border-primary/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        data-testid={`shop-menu-card-${menu.id}`}
      >
        <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0 px-3 pt-3 pb-1">
          <div className="min-w-0">
            <CardTitle className="truncate text-sm font-semibold">
              <Link
                href={href}
                onClick={(e) => e.stopPropagation()}
                className="hover:underline focus-visible:outline-none"
              >
                {menu.name}
              </Link>
            </CardTitle>
          </div>
          <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
        </CardHeader>
        <CardContent className="flex items-center justify-between gap-2 px-3 pt-1 pb-3 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1">
            <UtensilsCrossed className="size-3" />
            {t("shop.menu.items_count", {
              count: menu.menu_products_count ?? 0,
            })}
          </span>
          <div className="flex items-center gap-2">
            {canSync && (
              <Button
                variant="outline"
                size="sm"
                className="h-6 gap-1 px-2 text-[10px]"
                onClick={(e) => {
                  e.stopPropagation();
                  setConfirmSyncOpen(true);
                }}
                disabled={syncMut.isPending}
                data-testid={`shop-menu-card-sync-${menu.id}`}
              >
                {syncMut.isPending ? (
                  <Spinner className="size-3" />
                ) : (
                  <RefreshCw className="size-3" />
                )}
                {t("shop.menu.detail.sync")}
              </Button>
            )}
            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
              {menu.status}
            </span>
          </div>
        </CardContent>
      </Card>
      <Dialog open={confirmSyncOpen} onOpenChange={setConfirmSyncOpen}>
        <DialogContent className="sm:max-w-md" onClick={(e) => e.stopPropagation()}>
          <DialogHeader>
            <DialogTitle>{t("shop.menu.sync_confirm_title")}</DialogTitle>
            <DialogDescription>{t("shop.menu.sync_confirm_description")}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setConfirmSyncOpen(false)}
              disabled={syncMut.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={() => {
                syncMut.mutate(undefined, {
                  onSettled: () => setConfirmSyncOpen(false),
                });
              }}
              disabled={syncMut.isPending}
              data-testid={`shop-menu-card-sync-confirm-${menu.id}`}
            >
              {syncMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
