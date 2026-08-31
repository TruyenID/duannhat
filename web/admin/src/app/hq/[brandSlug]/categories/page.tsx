"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { Plus, Upload, Download, Trash2, RotateCcw, EllipsisVertical } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { StatusBadge } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import {
  useCategories,
  useBulkDeleteCategories,
  useDeleteCategory,
  useRestoreCategory,
} from "@/hooks/api/use-categories";
import { apiFetch } from "@/lib/api";
import { categoryService, type Category } from "@/services/category-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

interface BrandResponse {
  data: { id: string; slug: string; name: string };
}

import { HelpPanel } from "@/components/shared/help-panel";
import { Alert, AlertDescription } from "@godxjp/ui";
import { CategoryTreeRow } from "./components/category-tree-row";
import { CategoryFormDialog } from "./components/category-form-dialog";
import { CategoryImportDialog } from "./components/category-import-dialog";

import { Spinner } from "@godxjp/ui";
interface TreeNode {
  category: Category;
  depth: number;
  hasChildren: boolean;
  visible: boolean;
}

/**
 * Build a depth-first list of nodes from a flat array, applying expand state
 * to determine `visible`. Top-level (parent_id===null) nodes are roots.
 */
function buildTree(items: Category[], expanded: Set<string>): TreeNode[] {
  const byParent = new Map<string | null, Category[]>();
  for (const c of items) {
    const key = c.parent_id ?? null;
    if (!byParent.has(key)) byParent.set(key, []);
    byParent.get(key)!.push(c);
  }
  for (const list of byParent.values()) {
    list.sort((a, b) => a.name.localeCompare(b.name));
  }

  const result: TreeNode[] = [];
  function walk(parentId: string | null, depth: number, parentVisible: boolean) {
    const children = byParent.get(parentId) ?? [];
    for (const c of children) {
      const hasChildren = (byParent.get(c.id)?.length ?? 0) > 0;
      const isExpanded = expanded.has(c.id);
      result.push({
        category: c,
        depth,
        hasChildren,
        visible: parentVisible,
      });
      walk(c.id, depth + 1, parentVisible && isExpanded);
    }
  }
  walk(null, 0, true);
  return result;
}

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  trashed: "",
};

/**
 * #1959 — how many categories this screen loads in one go.
 *
 * The backend caps `per_page` at 100, so this is not a number we may simply
 * raise: asking for more silently returns 100 anyway. It is therefore stated
 * here and CHECKED against `meta.total`, because the failure being fixed was
 * exactly a cap nobody could see.
 *
 * A brand that outgrows it needs the tree endpoint to stop paginating, not a
 * bigger constant.
 */
const CATEGORY_TREE_CAP = 100;

export default function CategoriesPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  // URL-synced filter state
  // #1959 — `page` / `setPage` are deliberately NOT destructured. This screen
  // loads the whole tree in one request, so there is no page to be on; keeping
  // the bindings around would leave two unused symbols that read like an
  // unfinished refactor and invite someone to "wire them back up".
  const { filters: urlFilters, setFilter, resetFilters } = useSearchFilters(FILTER_DEFAULTS);
  const [search, setSearch] = useState(urlFilters.search);
  const debouncedSearch = useDebounce(search, 300);

  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]);

  const activeFilter = urlFilters.status as "all" | "active" | "inactive";
  const showTrashed = urlFilters.trashed === "1";

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  // UI state
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<{
    id: string;
    name: string;
    childrenCount?: number;
  } | null>(null);
  const [exporting, setExporting] = useState(false);

  // Data
  // #1959 — this screen renders a TREE, and a tree cannot be paginated by row.
  //
  // `buildTree` needs every node at once: page 2 would hold children whose
  // parents are on page 1, so the second page renders a forest of orphans. That
  // is why `page` was never in these filters — the omission was correct.
  //
  // What was wrong is that the screen still drew a full `<Pagination>` control
  // wired to state nothing read, so a brand with more than 100 categories saw
  // page buttons that did nothing while the tree silently stopped at 100. The
  // control is gone; the cap is now stated out loud (see CATEGORY_TREE_CAP).
  const apiFilters = useMemo(
    () => ({
      per_page: CATEGORY_TREE_CAP,
      search: debouncedSearch || undefined,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      with_trashed: showTrashed,
      sort: "name",
    }),
    [debouncedSearch, activeFilter, showTrashed]
  );

  // Brand info — needed for brand_id in create/import flows. Comes from the
  // same endpoint BrandLayout uses, so the response is already cached and this
  // query is effectively free.
  const { data: brandResponse } = useQuery({
    queryKey: ["hq", "brand", brandSlug],
    queryFn: () => apiFetch<BrandResponse>(`/api/v1/hq/${brandSlug}`),
    staleTime: 5 * 60 * 1000,
  });
  const brandId = brandResponse?.data.id ?? "";

  const { data: response, isLoading, refetch, isFetching } = useCategories(brandSlug, apiFilters);
  const items = useMemo<Category[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 100,
    from: null,
    to: null,
  };

  // Reset bulk selection when filters change
  useEffect(() => {
    setSelected(new Set());
  }, [apiFilters]);

  const tree = useMemo(() => buildTree(items, expanded), [items, expanded]);
  const visibleNodes = useMemo(() => tree.filter((n) => n.visible), [tree]);

  // Mutations
  const deleteOne = useDeleteCategory(brandSlug);
  const bulkDelete = useBulkDeleteCategories(brandSlug);
  const restore = useRestoreCategory(brandSlug);

  function toggleNode(id: string) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
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
    if (selected.size === visibleNodes.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(visibleNodes.map((n) => n.category.id)));
    }
  }

  function openCreate() {
    setCreateOpen(true);
  }

  async function handleExport() {
    setExporting(true);
    try {
      await categoryService.exportCsv(brandSlug);

      toast.success(t("hq.categories.exported"));
    } catch (e) {
      toast.error((e as Error).message || t("hq.categories.export_failed"));
    } finally {
      setExporting(false);
    }
  }

  async function handleConfirmSingle() {
    if (!confirmSingle) return;
    const id = confirmSingle.id;
    await deleteOne.mutateAsync(id);
    setSelected((prev) => {
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
    setConfirmSingle(null);
  }

  async function handleBulkDelete() {
    const ids = Array.from(selected);
    await bulkDelete.mutateAsync(ids);
    setSelected(new Set());
    setConfirmBulk(false);
  }

  function selectedChildrenCount(): number {
    let total = 0;
    for (const id of selected) {
      const node = tree.find((n) => n.category.id === id);
      if (!node) continue;
      // count descendants in the loaded tree
      const stack = [node.category.id];
      while (stack.length) {
        const cur = stack.pop()!;
        for (const t of tree) {
          if (t.category.parent_id === cur) {
            total++;
            stack.push(t.category.id);
          }
        }
      }
    }
    return total;
  }

  const columns: ColumnDef<TreeNode>[] = useMemo(
    () => [
      {
        id: "select",
        size: 36,
        header: () => (
          <Checkbox
            checked={visibleNodes.length > 0 && selected.size === visibleNodes.length}
            onCheckedChange={toggleSelectAll}
            aria-label="Select all"
          />
        ),
        cell: ({ row }) => (
          <Checkbox
            checked={selected.has(row.original.category.id)}
            onCheckedChange={() => toggleSelect(row.original.category.id)}
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
        id: "name",
        header: t("common.name"),
        size: 360,
        cell: ({ row }) => (
          <CategoryTreeRow
            category={row.original.category}
            depth={row.original.depth}
            hasChildren={row.original.hasChildren}
            isExpanded={expanded.has(row.original.category.id)}
            detailHref={`/hq/${brandSlug}/categories/${row.original.category.id}`}
            onToggle={toggleNode}
          />
        ),
      },
      {
        accessorKey: "category.sku",
        header: "SKU",
        size: 120,
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
            {row.original.category.sku || "—"}
          </code>
        ),
      },
      {
        id: "status",
        header: t("common.status"),
        size: 100,
        cell: ({ row }) => (
          <StatusBadge
            status={
              row.original.category.deleted_at
                ? "deleted"
                : row.original.category.is_active
                  ? "active"
                  : "inactive"
            }
          />
        ),
      },
      {
        id: "children",
        header: t("hq.categories.col.children"),
        size: 80,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.category.children_count ?? 0}
          </span>
        ),
      },
      {
        accessorKey: "category.updated_at",
        header: t("common.updated"),
        size: 130,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {formatDate(row.original.category.updated_at, locale, timezone)}
          </span>
        ),
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => {
          const c = row.original.category;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {c.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(c.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <DropdownMenuItem
                    className="text-destructive"
                    onClick={() =>
                      setConfirmSingle({
                        id: c.id,
                        name: c.name,
                        childrenCount: c.children_count ?? 0,
                      })
                    }
                  >
                    <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
      },
    ],
    [selected, expanded, deleteOne, restore, brandSlug, t, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.categories.title")}
        description={`${meta.total} total`}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button
          variant="outline"
          size="sm"
          className="h-7 gap-1 text-xs"
          onClick={handleExport}
          disabled={exporting}
        >
          {exporting ? <Spinner className="size-3.5" /> : <Download className="size-3.5" />}
          {t("common.export")}
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="h-7 gap-1 text-xs"
          onClick={() => setImportOpen(true)}
        >
          <Upload className="size-3.5" />
          {t("common.import")}
        </Button>
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={openCreate}>
          <Plus className="size-3.5" />
          {t("common.new")}
        </Button>
        <HelpPanel
          title={t("hq.categories.title")}
          subtitle={t("help.panel.hq_categories.subtitle")}
          purpose={t("help.panel.hq_categories.purpose")}
          usage={[
            t("help.panel.hq_categories.usage.1"),
            t("help.panel.hq_categories.usage.2"),
            t("help.panel.hq_categories.usage.3"),
            t("help.panel.hq_categories.usage.4"),
          ]}
          checks={[
            t("help.panel.hq_categories.checks.1"),
            t("help.panel.hq_categories.checks.2"),
            t("help.panel.hq_categories.checks.3"),
            t("help.panel.hq_categories.checks.4"),
          ]}
          glossary={[
            {
              term: t("help.panel.hq_categories.glossary.children.term"),
              description: t("help.panel.hq_categories.glossary.children.desc"),
            },
            {
              term: t("help.panel.hq_categories.glossary.sku.term"),
              description: t("help.panel.hq_categories.glossary.sku.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.categories.search_placeholder")}
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
          <Select value={activeFilter} onValueChange={(v) => setFilter("status", v)}>
            <SelectTrigger className="h-8 w-36 text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("common.all")}</SelectItem>
              <SelectItem value="active">{t("common.active")}</SelectItem>
              <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
            </SelectContent>
          </Select>
        </ListPageToolbar>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={8} />
        ) : (
          <DataTable
            columns={columns}
            data={visibleNodes}
            emptyMessage={t("hq.categories.empty")}
          />
        )}

        {/* #1959 — no <Pagination> here, deliberately. A hierarchy has to be
            loaded whole, so page controls could only ever lie. What replaces it
            is an honest statement when the cap actually bites. */}
        {(meta?.total ?? 0) > CATEGORY_TREE_CAP && (
          <Alert variant="destructive" className="mt-4" data-slot="category-tree-truncated">
            <AlertDescription>
              {t("hq.categories.truncated", {
                shown: CATEGORY_TREE_CAP,
                total: meta?.total ?? 0,
              })}
            </AlertDescription>
          </Alert>
        )}
      </PageContent>

      <CategoryFormDialog
        brandSlug={brandSlug}
        brandId={brandId}
        open={createOpen}
        onOpenChange={setCreateOpen}
        category={null}
        allCategories={items}
      />

      <CategoryImportDialog
        brandSlug={brandSlug}
        brandId={brandId}
        open={importOpen}
        onOpenChange={setImportOpen}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle
            ? confirmSingle.childrenCount && confirmSingle.childrenCount > 0
              ? t("hq.categories.delete_with_children", {
                  name: confirmSingle.name,
                  n: confirmSingle.childrenCount,
                })
              : t("hq.categories.delete_confirm", { name: confirmSingle.name })
            : ""
        }
        onConfirm={handleConfirmSingle}
        isPending={deleteOne.isPending}
      />

      <DeleteConfirmDialog
        open={confirmBulk}
        onOpenChange={setConfirmBulk}
        title={t("hq.categories.bulk_delete_title", { n: selected.size })}
        description={(() => {
          const cc = selectedChildrenCount();
          const base = t("hq.categories.bulk_delete_desc");
          return cc > 0 ? `${base} ${t("hq.categories.bulk_delete_orphan", { n: cc })}` : base;
        })()}
        onConfirm={handleBulkDelete}
        isPending={bulkDelete.isPending}
      />
    </>
  );
}
