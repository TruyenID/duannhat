"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ChevronRight, Clock, RefreshCw, Search, X } from "lucide-react";
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
  StatusBadge,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { Pagination } from "@/components/shared/pagination";
import { useDebounce } from "@/hooks/use-debounce";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useTranslation } from "@/providers/app-provider";
import {
  useShopFloatingSections,
  useSyncShopFloatingSection,
} from "@/hooks/api/use-shop-floating-sections";
import type { FloatingSection } from "@/types/models/FloatingSection";

const STATUS_OPTIONS = ["active", "inactive", "all"] as const;
type StatusFilter = (typeof STATUS_OPTIONS)[number];

const FILTER_DEFAULTS = {
  search: "",
  status: "active",
  per_page: "25",
};

export default function ShopFloatingSectionsPage() {
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const { t } = useTranslation();

  const { filters: urlFilters, page, setFilter, setPage } = useSearchFilters(FILTER_DEFAULTS);
  const [searchInput, setSearchInput] = useState(urlFilters.search);
  const debouncedSearch = useDebounce(searchInput, 300);

  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch]);

  const statusFilter = urlFilters.status as StatusFilter;
  const perPage = Number(urlFilters.per_page) || 25;
  const apiFilters = useMemo(
    () => ({
      page,
      per_page: perPage,
      search: debouncedSearch || undefined,
      is_active: statusFilter === "all" ? undefined : statusFilter === "active",
    }),
    [page, perPage, debouncedSearch, statusFilter]
  );

  const sectionsQuery = useShopFloatingSections(shopSlug, apiFilters);
  const sections = useMemo<FloatingSection[]>(
    () => sectionsQuery.data?.data ?? [],
    [sectionsQuery.data]
  );
  const meta = sectionsQuery.data?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: perPage,
    from: null,
    to: null,
  };

  const isLoading = sectionsQuery.isLoading;
  const isEmpty = !isLoading && sections.length === 0;

  return (
    <>
      <PageHeader
        title={t("hq.floating_sections.title")}
        description={
          isLoading
            ? t("common.loading")
            : t("hq.floating_sections.header.total", { n: meta.total })
        }
        onRefresh={sectionsQuery.refetch}
        isRefreshing={sectionsQuery.isFetching}
      >
        <HelpPanel
          title={t("hq.floating_sections.title")}
          subtitle={t("help.panel.shop_floating_sections.subtitle")}
          purpose={t("help.panel.shop_floating_sections.purpose")}
          usage={[
            t("help.panel.shop_floating_sections.usage.1"),
            t("help.panel.shop_floating_sections.usage.2"),
            t("help.panel.shop_floating_sections.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_floating_sections.checks.1"),
            t("help.panel.shop_floating_sections.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_floating_sections.glossary.window.term"),
              description: t("help.panel.shop_floating_sections.glossary.window.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <div className="mb-4 flex flex-wrap items-center gap-2">
          <div className="relative max-w-md min-w-55 flex-1">
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder={t("hq.floating_sections.search_placeholder")}
              className="h-8 pr-8 pl-8 text-sm"
            />
            {searchInput && (
              <button
                type="button"
                onClick={() => setSearchInput("")}
                className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                aria-label="Clear search"
              >
                {sectionsQuery.isFetching ? (
                  <Spinner className="size-3.5" />
                ) : (
                  <X className="size-3.5" />
                )}
              </button>
            )}
          </div>

          <Select value={statusFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-32 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {STATUS_OPTIONS.map((s) => (
                <SelectItem key={s} value={s}>
                  {t(`shop.floating_sections.status_${s}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {isLoading && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("common.loading")}
          </div>
        )}

        {isEmpty && (
          <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-10 text-center">
            <Clock className="size-8 text-muted-foreground" />
            <p className="mt-3 text-sm font-medium">{t("shop.floating_sections.empty_title")}</p>
            <p className="mt-1 text-xs text-muted-foreground">
              {t("shop.floating_sections.empty_desc")}
            </p>
          </div>
        )}

        {!isLoading && sections.length > 0 && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {sections.map((section) => (
              <FloatingSectionCard key={section.id} shopSlug={shopSlug} section={section} />
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

function FloatingSectionCard({
  shopSlug,
  section,
}: {
  shopSlug: string;
  section: FloatingSection;
}) {
  const { t } = useTranslation();
  const router = useRouter();
  const href = `/shop/${shopSlug}/floating-sections/${section.id}`;
  const syncMutation = useSyncShopFloatingSection(shopSlug, section.id);
  const [confirmSyncOpen, setConfirmSyncOpen] = useState(false);
  const canSync = Boolean(section.master_section_id);

  return (
    <>
      <Card
        role="link"
        tabIndex={0}
        onClick={() => router.push(href)}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            router.push(href);
          }
        }}
        className="group cursor-pointer transition-colors hover:border-primary/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      >
        <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0 px-3 pt-3 pb-1">
          <div className="min-w-0">
            <CardTitle className="truncate text-sm font-semibold">
              <Link
                href={href}
                onClick={(e) => e.stopPropagation()}
                className="hover:underline focus-visible:outline-none"
              >
                {section.name}
              </Link>
            </CardTitle>
          </div>
          <ChevronRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
        </CardHeader>
        <CardContent className="flex items-center justify-between gap-2 px-3 pt-1 pb-3 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1">
            <Clock className="size-3" />
            {t("hq.floating_sections.col.products")}: {section.products_count ?? 0}
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
                disabled={syncMutation.isPending}
              >
                {syncMutation.isPending ? (
                  <Spinner className="size-3" />
                ) : (
                  <RefreshCw className="size-3" />
                )}
                {t("shop.floating_sections.sync")}
              </Button>
            )}
            <StatusBadge status={section.is_active ? "active" : "inactive"} />
          </div>
        </CardContent>
      </Card>

      <Dialog open={confirmSyncOpen} onOpenChange={setConfirmSyncOpen}>
        <DialogContent className="sm:max-w-md" onClick={(e) => e.stopPropagation()}>
          <DialogHeader>
            <DialogTitle>{t("shop.floating_sections.sync_confirm_title")}</DialogTitle>
            <DialogDescription>
              {t("shop.floating_sections.sync_confirm_description")}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setConfirmSyncOpen(false)}
              disabled={syncMutation.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={() =>
                syncMutation.mutate(undefined, {
                  onSettled: () => setConfirmSyncOpen(false),
                })
              }
              disabled={syncMutation.isPending}
            >
              {syncMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
