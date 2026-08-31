"use client";

import { useEffect, useMemo, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  Check,
  Download,
  EllipsisVertical,
  ImageIcon,
  Maximize2,
  ThumbsUp,
  Pause,
  Pencil,
  Play,
  Plus,
  RotateCcw,
  Send,
  Trash2,
  Upload,
  X,
} from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { StatusBadge } from "@godxjp/ui";
import { Button, buttonVariants } from "@godxjp/ui";
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import {
  useActivateProduct,
  useApproveProduct,
  useBulkDeleteProducts,
  useDeactivateProduct,
  useDeleteProduct,
  useProducts,
  useRejectProduct,
  useRestoreProduct,
  useSubmitProductForApproval,
} from "@/hooks/api/use-products";
import { apiFetch } from "@/lib/api";
import { productService, type Product, type ProductStatus } from "@/services/product-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { ImageLightbox } from "@/components/shared/image-lightbox";
import { HelpPanel } from "@/components/shared/help-panel";
import { ProductCreateDialog } from "./components/product-create-dialog";
import { ProductImportDialog } from "./components/product-import-dialog";
import { useProductTypeLookup } from "@/hooks/api/use-product-types";
import { useCategoryLookup } from "@/hooks/api/use-categories";

import { Spinner } from "@godxjp/ui";
interface BrandResponse {
  data: { id: string; slug: string; name: string };
}

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  type: "",
  category: "",
  hidden: "all",
  trashed: "",
  per_page: "25",
};

export default function ProductsPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const router = useRouter();
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

  const statusFilter = urlFilters.status as "all" | ProductStatus;
  const productTypeId = urlFilters.type;
  const categoryId = urlFilters.category;
  const hiddenFilter = urlFilters.hidden as "all" | "visible" | "hidden";
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  // UI state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmDeleteOne, setConfirmDeleteOne] = useState<Product | null>(null);
  const [exporting, setExporting] = useState(false);

  // Data
  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      status: statusFilter === "all" ? undefined : statusFilter,
      product_type_id: productTypeId || undefined,
      category_id: categoryId || undefined,
      is_hidden: hiddenFilter === "all" ? undefined : hiddenFilter === "hidden",
      with_trashed: showTrashed,
      sort: "-updated_at",
    }),
    [
      page,
      urlFilters.per_page,
      debouncedSearch,
      statusFilter,
      productTypeId,
      categoryId,
      hiddenFilter,
      showTrashed,
    ]
  );

  // Brand info — used by create/import dialogs.
  const { data: brandResponse } = useQuery({
    queryKey: ["hq", "brand", brandSlug],
    queryFn: () => apiFetch<BrandResponse>(`/api/v1/hq/${brandSlug}`),
    staleTime: 5 * 60 * 1000,
  });
  const brandId = brandResponse?.data.id ?? "";

  // Product type + category lookups for filter dropdowns.
  // Use the typed hooks so `locale` is part of the queryKey — switching
  // language refetches these with the new Accept-Language header.
  const { data: productTypesResp } = useProductTypeLookup(brandSlug);
  const productTypes = productTypesResp?.data ?? [];

  const { data: categoriesResp } = useCategoryLookup(brandSlug);
  const categories = categoriesResp?.data ?? [];

  const { data: response, isLoading, refetch, isFetching } = useProducts(brandSlug, apiFilters);
  const items = useMemo<Product[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: null,
    to: null,
  };

  // Reset bulk selection when filters change.
  useEffect(() => {
    setSelected(new Set());
  }, [apiFilters]);

  // Mutations
  const deleteOne = useDeleteProduct(brandSlug);
  const bulkDelete = useBulkDeleteProducts(brandSlug);
  const restore = useRestoreProduct(brandSlug);

  // Workflow transitions — each dropdown action calls the dedicated endpoint
  // so the BE state machine (assertStatus) stays authoritative. "Reject"
  // opens a dialog because BE requires a reason string.
  const submitForApproval = useSubmitProductForApproval(brandSlug);
  const approveMut = useApproveProduct(brandSlug);
  const rejectMut = useRejectProduct(brandSlug);
  const activateMut = useActivateProduct(brandSlug);
  const deactivateMut = useDeactivateProduct(brandSlug);

  // Reject dialog state — `target` holds the row the menu was opened on so
  // the confirmation step knows which product to reject.
  const [rejectTarget, setRejectTarget] = useState<Product | null>(null);
  const [rejectReason, setRejectReason] = useState("");

  const [lightbox, setLightbox] = useState<{ src: string; alt: string } | null>(null);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    if (selected.size === items.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(items.map((p) => p.id)));
    }
  }

  /**
   * Four export scopes — pick what the user actually wanted:
   *   - "page":     only the rows shown on the current page (filters + pagination)
   *   - "selected": only the rows the user has checked
   *   - "filtered": everything matching the current filters (no pagination)
   *   - "all":      every product in the brand (ignores filters + pagination)
   */
  type ExportScope = "page" | "selected" | "filtered" | "all";

  async function handleExport(scope: ExportScope) {
    setExporting(true);
    try {
      let filters: Record<string, unknown>;
      switch (scope) {
        case "page":
          // Honor pagination — service strips page/per_page by default, so
          // pass them in a way it preserves. exportCsv's strip-list is only
          // applied to its own filters arg; we re-add via the URL directly.
          filters = { ...apiFilters, page, per_page: apiFilters.per_page };
          break;
        case "selected":
          // ids[] wins on the BE — all other filters are ignored.
          filters = { ids: Array.from(selected) };
          break;
        case "filtered":
          filters = { ...apiFilters };
          break;
        case "all":
        default:
          filters = {};
      }
      await productService.exportCsv(brandSlug, filters, { keepPagination: scope === "page" });
      toast.success(t("hq.products.exported"));
    } catch (e) {
      toast.error((e as Error).message || t("hq.products.export_failed"));
    } finally {
      setExporting(false);
    }
  }

  async function handleBulkDelete() {
    const ids = Array.from(selected);
    await bulkDelete.mutateAsync(ids);
    setSelected(new Set());
    setConfirmBulk(false);
  }

  const columns: ColumnDef<Product>[] = useMemo(
    () => [
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
        id: "image",
        header: t("hq.products.col.image"),
        size: 60,
        cell: ({ row }) => {
          const src = row.original.image_url;
          if (!src) {
            return (
              <div className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                <ImageIcon className="size-4 text-muted-foreground" />
              </div>
            );
          }
          return (
            <button
              type="button"
              onClick={() => setLightbox({ src, alt: row.original.name })}
              aria-label={t("hq.menus.items.enlarge_image")}
              className="group relative size-9 shrink-0 cursor-zoom-in overflow-hidden rounded bg-muted"
            >
              <Image
                src={src}
                alt={row.original.name}
                width={36}
                height={36}
                className="h-full w-full object-cover"
              />
              <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/40">
                <Maximize2 className="size-3.5 text-white opacity-0 transition-opacity group-hover:opacity-100" />
              </span>
            </button>
          );
        },
      },
      {
        id: "name",
        header: t("common.name"),
        size: 260,
        cell: ({ row }) => (
          <Link
            href={`/hq/${brandSlug}/products/${row.original.id}`}
            className="font-medium text-primary hover:underline"
          >
            {row.original.name}
          </Link>
        ),
      },
      {
        id: "type",
        header: t("common.type"),
        size: 120,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.productType?.name ?? "—"}
          </span>
        ),
      },
      {
        id: "tax_type",
        header: t("hq.products.col.tax_type"),
        size: 90,
        // plan-043 — the assigned tax type's code, or "—" when the product
        // inherits the brand default (tax_type_id null).
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.taxType?.code ?? "—"}</span>
        ),
      },
      {
        id: "status",
        header: t("common.status"),
        size: 90,
        cell: ({ row }) => (
          <StatusBadge status={row.original.deleted_at ? "deleted" : row.original.status} />
        ),
      },
      {
        id: "skus_count",
        header: t("hq.products.col.variants"),
        size: 70,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.skus_count ?? 0}</span>
        ),
      },
      {
        id: "reviews",
        header: t("hq.products.col.reviews"),
        size: 100,
        cell: ({ row }) => {
          const total = Number(row.original.review_total_count);
          if (!total) return <span className="text-xs text-muted-foreground">—</span>;
          const pct = Math.round((Number(row.original.review_up_count) / total) * 100);
          return (
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
              <ThumbsUp className="size-3" />
              {pct}%<span className="text-[11px]">({total})</span>
            </span>
          );
        },
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
          const p = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {p.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(p.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem
                      onClick={() => router.push(`/hq/${brandSlug}/products/${p.id}`)}
                    >
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>

                    {/* Workflow transitions — derived from status so the menu
                        only exposes legal next states. BE assertStatus
                        double-checks so a stale row surfaces a 422. */}
                    {(p.status === "draft" || p.status === "rejected") && (
                      <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => submitForApproval.mutate(p.id)}>
                          <Send className="mr-2 size-3.5" />
                          {p.status === "rejected"
                            ? t("hq.products.workflow.resubmit")
                            : t("hq.products.workflow.submit")}
                        </DropdownMenuItem>
                      </>
                    )}
                    {p.status === "pending" && (
                      <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => approveMut.mutate(p.id)}>
                          <Check className="mr-2 size-3.5" /> {t("hq.products.workflow.approve")}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          className="text-destructive"
                          onClick={() => {
                            setRejectTarget(p);
                            setRejectReason("");
                          }}
                        >
                          <X className="mr-2 size-3.5" /> {t("hq.products.workflow.reject")}
                        </DropdownMenuItem>
                      </>
                    )}
                    {(p.status === "approved" || p.status === "inactive") && (
                      <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => activateMut.mutate(p.id)}>
                          <Play className="mr-2 size-3.5" /> {t("hq.products.workflow.activate")}
                        </DropdownMenuItem>
                      </>
                    )}
                    {p.status === "active" && (
                      <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => deactivateMut.mutate(p.id)}>
                          <Pause className="mr-2 size-3.5" /> {t("hq.products.workflow.deactivate")}
                        </DropdownMenuItem>
                      </>
                    )}

                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => setConfirmDeleteOne(p)}
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
    [items, selected, deleteOne, restore, brandSlug, t, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.products.title")}
        description={`${meta.total} total`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="outline" size="sm" className="h-7 gap-1 text-xs" disabled={exporting}>
              {exporting ? <Spinner className="size-3.5" /> : <Download className="size-3.5" />}
              {t("common.export")}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuItem onClick={() => handleExport("page")}>
              {t("hq.products.export_scope.page", { count: items.length })}
            </DropdownMenuItem>
            <DropdownMenuItem
              disabled={selected.size === 0}
              onClick={() => handleExport("selected")}
            >
              {t("hq.products.export_scope.selected", { count: selected.size })}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={() => handleExport("filtered")}>
              {t("hq.products.export_scope.filtered", { count: meta.total })}
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => handleExport("all")}>
              {t("hq.products.export_scope.all")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
        <Button
          variant="outline"
          size="sm"
          className="h-7 gap-1 text-xs"
          onClick={() => setImportOpen(true)}
        >
          <Upload className="size-3.5" />
          {t("common.import")}
        </Button>
        {/* <Button
          variant="outline"
          size="sm"
          className="h-7 gap-1 text-xs"
          onClick={() => setCreateOpen(true)}
          title={t("hq.products.quick_add_tooltip")}
        >
          <Plus className="size-3.5" />
          {t("hq.products.quick_add")}
        </Button> */}
        <Link
          href={`/hq/${brandSlug}/products/new`}
          className={buttonVariants({ size: "sm" }) + " h-7 gap-1 text-xs"}
        >
          <Plus className="size-3.5" />
          {t("hq.products.add")}
        </Link>
        <HelpPanel
          title={t("hq.products.title")}
          subtitle={t("help.panel.hq_products.subtitle")}
          purpose={t("help.panel.hq_products.purpose")}
          usage={[
            t("help.panel.hq_products.usage.1"),
            t("help.panel.hq_products.usage.2"),
            t("help.panel.hq_products.usage.3"),
            t("help.panel.hq_products.usage.4"),
            t("help.panel.hq_products.usage.5"),
            t("help.panel.hq_products.usage.6"),
          ]}
          checks={[
            t("help.panel.hq_products.checks.1"),
            t("help.panel.hq_products.checks.2"),
            t("help.panel.hq_products.checks.3"),
            t("help.panel.hq_products.checks.4"),
            t("help.panel.hq_products.checks.5"),
            t("help.panel.hq_products.checks.6"),
          ]}
          glossary={[
            {
              term: t("help.panel.hq_products.glossary.tax_type.term"),
              description: t("help.panel.hq_products.glossary.tax_type.desc"),
            },
            {
              term: t("help.panel.hq_products.glossary.variants.term"),
              description: t("help.panel.hq_products.glossary.variants.desc"),
            },
            {
              term: t("help.panel.hq_products.glossary.reviews.term"),
              description: t("help.panel.hq_products.glossary.reviews.desc"),
            },
            {
              term: t("help.panel.hq_products.glossary.status_chain.term"),
              description: t("help.panel.hq_products.glossary.status_chain.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
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
              <SelectItem value="all">{t("common.all_statuses")}</SelectItem>
              <SelectItem value="draft">{t("status.draft")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
            </SelectContent>
          </Select>
          <Select
            value={productTypeId || "__all__"}
            onValueChange={(v) => setFilter("type", v === "__all__" ? "" : v)}
          >
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{t("common.all_types")}</SelectItem>
              {productTypes.map((pt) => (
                <SelectItem key={pt.id} value={pt.id}>
                  {pt.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select
            value={categoryId || "__all__"}
            onValueChange={(v) => setFilter("category", v === "__all__" ? "" : v)}
          >
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{t("hq.products.filter.all_categories")}</SelectItem>
              {categories.map((c) => (
                <SelectItem key={c.id} value={c.id}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={hiddenFilter} onValueChange={(v) => setFilter("hidden", v)}>
            <SelectTrigger className="h-8 w-40 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.products.filter.all_visibility")}</SelectItem>
              <SelectItem value="visible">{t("hq.products.filter.visible_only")}</SelectItem>
              <SelectItem value="hidden">{t("hq.products.filter.hidden_only")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={10} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.products.empty")} />
        )}

        <Pagination
          meta={meta}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      {/* Reject dialog — BE requires a reason (1–1000 chars). Triggered from
          the row actions menu when status is "pending". */}
      <Dialog
        open={rejectTarget !== null}
        onOpenChange={(open) => {
          if (!open) {
            setRejectTarget(null);
            setRejectReason("");
          }
        }}
      >
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t("hq.products.reject.title")}</DialogTitle>
            <DialogDescription>
              {rejectTarget ? t("hq.products.reject.desc_for", { name: rejectTarget.name }) : null}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <Textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              rows={4}
              maxLength={1000}
              className="field-sizing-fixed"
              placeholder={t("hq.products.reject.placeholder")}
              disabled={rejectMut.isPending}
              aria-label={t("hq.products.reject.reason_aria")}
            />
            <div className="text-right text-[11px] text-muted-foreground">
              {rejectReason.length}/1000
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setRejectTarget(null)}
              disabled={rejectMut.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={async () => {
                if (!rejectTarget) return;
                const reason = rejectReason.trim();
                if (!reason) return;
                try {
                  await rejectMut.mutateAsync({ id: rejectTarget.id, reason });
                  setRejectTarget(null);
                  setRejectReason("");
                } catch {
                  /* toast fired by hook */
                }
              }}
              disabled={rejectMut.isPending || !rejectReason.trim()}
            >
              {rejectMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("hq.products.reject.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* <ProductCreateDialog
        brandSlug={brandSlug}
        brandId={brandId}
        open={createOpen}
        onOpenChange={setCreateOpen}
      /> */}

      <ProductImportDialog
        brandSlug={brandSlug}
        brandId={brandId}
        open={importOpen}
        onOpenChange={setImportOpen}
      />

      <DeleteConfirmDialog
        open={!!confirmDeleteOne}
        onOpenChange={(open) => {
          if (!open) setConfirmDeleteOne(null);
        }}
        description={
          confirmDeleteOne ? t("hq.products.delete_confirm", { name: confirmDeleteOne.name }) : ""
        }
        onConfirm={() => {
          if (confirmDeleteOne) {
            deleteOne.mutate(confirmDeleteOne.id);
            setConfirmDeleteOne(null);
          }
        }}
        isPending={deleteOne.isPending}
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        title={t("hq.products.bulk_delete_title", { n: selected.size })}
        description={t("hq.products.bulk_delete_desc")}
        onConfirm={handleBulkDelete}
        isPending={bulkDelete.isPending}
      />

      <ImageLightbox
        src={lightbox?.src ?? null}
        alt={lightbox?.alt ?? ""}
        open={!!lightbox}
        onOpenChange={(o) => {
          if (!o) setLightbox(null);
        }}
      />
    </>
  );
}
