"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import Link from "next/link";
import { Check, EllipsisVertical, Pencil, Plus, RotateCcw, Send, Trash2, X } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination } from "@/components/shared/pagination";
import { StatusBadge } from "@godxjp/ui";
import { Badge } from "@godxjp/ui";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
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
  useApproveRecipe,
  useBulkDeleteRecipes,
  useDeleteRecipe,
  useRecipes,
  useRestoreRecipe,
  useSubmitRecipeForApproval,
} from "@/hooks/api/use-recipes";
import { useAllergens } from "@/hooks/api/use-allergens";
import { useMaterialLookup } from "@/hooks/api/use-materials";
import type { Recipe, RecipeIngredient } from "@/services/recipe-service";
import { ApprovalStatus } from "@/types/models/enum/ApprovalStatus";
import { RejectRecipeDialog } from "./components/reject-recipe-dialog";

import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { stripHtml } from "@/lib/utils";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";

const FILTER_DEFAULTS = {
  search: "",
  status: "all",
  trashed: "",
  per_page: "25",
  // Deep-link filter from the material detail page ("recipes for this material").
  material_id: "",
};

export default function RecipesPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const router = useRouter();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;

  // Filter state
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

  const activeFilter = urlFilters.status as "all" | "active" | "inactive";
  const showTrashed = urlFilters.trashed === "1";
  const materialIdFilter = urlFilters.material_id || "";

  // Resolve the filtered material's name for the active-filter chip. Lookup only
  // runs when the deep-link param is present; falls back to the raw id.
  const materialLookup = useMaterialLookup(brandSlug, !!materialIdFilter);
  const filteredMaterialName = useMemo(
    () => materialLookup.data?.data.find((m) => m.id === materialIdFilter)?.name ?? null,
    [materialLookup.data, materialIdFilter]
  );

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  // UI state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<Recipe | null>(null);
  const [rejectTargetId, setRejectTargetId] = useState<string | null>(null);

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      search: debouncedSearch || undefined,
      is_active: activeFilter === "all" ? undefined : activeFilter === "active",
      material_id: materialIdFilter || undefined,
      with_trashed: showTrashed,
      sort: "-updated_at",
    }),
    [page, urlFilters.per_page, debouncedSearch, activeFilter, materialIdFilter, showTrashed]
  );

  const { data: response, isLoading, refetch, isFetching } = useRecipes(brandSlug, apiFilters);
  const items = useMemo<Recipe[]>(() => response?.data ?? [], [response]);
  const meta = response?.meta ?? {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 25,
    from: 0,
    to: 0,
  };

  // Drop any selections that no longer appear in the visible result set.
  useEffect(() => {
    setSelected(new Set());
  }, [apiFilters]);

  // Mutations
  const deleteOne = useDeleteRecipe(brandSlug);
  const bulkDelete = useBulkDeleteRecipes(brandSlug);
  const restore = useRestoreRecipe(brandSlug);
  const submitForApproval = useSubmitRecipeForApproval(brandSlug);
  const approveRecipe = useApproveRecipe(brandSlug);

  // Allergen name lookup for the rollup chip column.
  const allergensQuery = useAllergens(brandSlug, { per_page: 100 });
  const allergenNameById = useMemo(() => {
    const m = new Map<string, string>();
    for (const a of allergensQuery.data?.data ?? []) m.set(a.id, a.name);
    return m;
  }, [allergensQuery.data]);

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
      setSelected(new Set(items.map((r) => r.id)));
    }
  }

  function openCreate() {
    router.push(`/hq/${brandSlug}/recipes/new`);
  }

  function openEdit(r: Recipe) {
    router.push(`/hq/${brandSlug}/recipes/${r.id}`);
  }

  async function handleBulkDelete() {
    const ids = Array.from(selected);
    await bulkDelete.mutateAsync(ids);
    setSelected(new Set());
    setConfirmBulk(false);
  }

  const columns: ColumnDef<Recipe>[] = useMemo(
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
        accessorKey: "sku",
        header: "SKU",
        size: 110,
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{row.original.sku || "—"}</code>
        ),
      },
      {
        accessorKey: "name",
        header: t("common.name"),
        cell: ({ row }) => {
          const r = row.original;
          return (
            <div className="flex flex-col">
              <Link
                href={`/hq/${brandSlug}/recipes/${r.id}`}
                className="font-medium text-primary hover:underline"
              >
                {r.name}
              </Link>
              {(() => {
                // description is rich-text HTML (TranslatableRichText form
                // field). Strip tags + decode entities so the list shows clean
                // plain text instead of a literal "<p>...</p>" — mirrors the
                // materials list cell.
                const preview = stripHtml(r.description);
                return preview ? (
                  <span className="line-clamp-1 text-xs text-muted-foreground">{preview}</span>
                ) : null;
              })()}
            </div>
          );
        },
      },
      {
        id: "ingredients",
        header: t("hq.recipes.col.ingredients"),
        size: 110,
        cell: ({ row }) => {
          const ingredients = (row.original.ingredients as RecipeIngredient[]) || [];
          return (
            <span className="text-xs text-muted-foreground">
              {ingredients.length > 0
                ? t("hq.recipes.count.items", { n: ingredients.length })
                : "—"}
            </span>
          );
        },
      },
      {
        id: "output",
        header: t("hq.recipes.col.output"),
        size: 120,
        cell: ({ row }) => {
          const r = row.original;
          const qty = Number(r.output_quantity) || 0;
          return (
            <span className="text-xs">
              {qty.toLocaleString()}
              {r.output_unit ? ` ${r.output_unit}` : ""}
            </span>
          );
        },
      },
      {
        id: "linked_skus",
        header: t("hq.recipes.col.linked_variants"),
        size: 200,
        cell: ({ row }) => {
          const skus = row.original.skus || [];
          if (skus.length === 0) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          if (skus.length === 1) {
            const v = skus[0];
            const productName = v.product?.name;
            const displayName =
              productName && v.name !== productName
                ? `${productName} — ${v.name}`
                : productName || v.name;
            return (
              <div className="flex flex-col">
                <span className="text-xs">{displayName}</span>
                <span className="text-[11px] text-muted-foreground">{v.sku}</span>
              </div>
            );
          }
          return (
            <span className="text-xs text-muted-foreground">
              {t("hq.recipes.count.items", { n: skus.length })}
            </span>
          );
        },
      },
      {
        id: "approval_status",
        header: t("hq.recipes.col.approval_status"),
        size: 120,
        cell: ({ row }) => {
          const s = row.original.approval_status ?? ApprovalStatus.Draft;
          const colorMap: Record<
            ApprovalStatus,
            "primary" | "warning" | "success" | "destructive"
          > = {
            [ApprovalStatus.Draft]: "primary",
            [ApprovalStatus.Pending]: "warning",
            [ApprovalStatus.Approved]: "success",
            [ApprovalStatus.Rejected]: "destructive",
          };
          const variantMap: Record<
            ApprovalStatus,
            "secondary" | "soft" | "default" | "destructive"
          > = {
            [ApprovalStatus.Draft]: "secondary",
            [ApprovalStatus.Pending]: "soft",
            [ApprovalStatus.Approved]: "soft",
            [ApprovalStatus.Rejected]: "destructive",
          };
          return (
            <Badge variant={variantMap[s]} color={colorMap[s]}>
              {t(`hq.recipes.status.${s}`)}
            </Badge>
          );
        },
      },
      {
        id: "allergens",
        header: t("hq.recipes.col.allergens"),
        size: 180,
        cell: ({ row }) => {
          const ids = row.original.allergen_rollup ?? [];
          if (ids.length === 0) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          const labels = ids.map((id) => allergenNameById.get(id) ?? id);
          const visible = labels.slice(0, 3);
          const hidden = labels.slice(3);
          return (
            <div className="flex flex-wrap items-center gap-1">
              {visible.map((name, i) => (
                <Badge key={`${name}-${i}`} variant="soft" color="warning">
                  {name}
                </Badge>
              ))}
              {hidden.length > 0 && (
                <TooltipProvider>
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Badge variant="outline">{`+${hidden.length}`}</Badge>
                    </TooltipTrigger>
                    <TooltipContent>{hidden.join(", ")}</TooltipContent>
                  </Tooltip>
                </TooltipProvider>
              )}
            </div>
          );
        },
      },
      {
        id: "status",
        header: t("common.status"),
        size: 100,
        cell: ({ row }) => (
          <StatusBadge
            status={
              row.original.deleted_at ? "deleted" : row.original.is_active ? "active" : "inactive"
            }
          />
        ),
      },
      {
        accessorKey: "updated_at",
        header: t("common.updated"),
        size: 120,
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
          const r = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {r.deleted_at ? (
                  <DropdownMenuItem onClick={() => restore.mutate(r.id)}>
                    <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                  </DropdownMenuItem>
                ) : (
                  <>
                    <DropdownMenuItem onClick={() => openEdit(r)}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </DropdownMenuItem>
                    {(r.approval_status === ApprovalStatus.Draft ||
                      r.approval_status === ApprovalStatus.Rejected) && (
                      <DropdownMenuItem onClick={() => submitForApproval.mutate(r.id)}>
                        <Send className="mr-2 size-3.5" /> {t("hq.recipes.actions.submit")}
                      </DropdownMenuItem>
                    )}
                    {r.approval_status === ApprovalStatus.Pending && (
                      <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => approveRecipe.mutate(r.id)}>
                          <Check className="mr-2 size-3.5" /> {t("hq.recipes.actions.approve")}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          className="text-destructive"
                          onClick={() => setRejectTargetId(r.id)}
                        >
                          <X className="mr-2 size-3.5" /> {t("hq.recipes.actions.reject")}
                        </DropdownMenuItem>
                      </>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="text-destructive"
                      onClick={() => {
                        setConfirmSingle(r);
                      }}
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
    [t, selected, deleteOne, restore, submitForApproval, approveRecipe, allergenNameById, meta.from]
  );

  return (
    <>
      <PageHeader
        title={t("hq.recipes.title")}
        description={t("hq.recipes.header.total", { n: meta.total })}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={openCreate}>
          <Plus className="size-3.5" />
          {t("common.new")}
        </Button>
      </PageHeader>

      <PageContent>
        <ListPageToolbar
          search={search}
          onSearchChange={setSearch}
          searchPlaceholder={t("hq.recipes.search_placeholder")}
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

        {materialIdFilter && (
          <div className="mb-3">
            <Badge variant="soft" color="info" className="gap-1.5 py-1 pr-1 pl-2.5">
              <span className="text-xs">
                {t("hq.recipes.filtered_by_material")}:{" "}
                <span className="font-medium">{filteredMaterialName ?? materialIdFilter}</span>
              </span>
              <button
                type="button"
                aria-label={t("common.remove")}
                className="inline-flex size-4 items-center justify-center rounded-full hover:bg-black/10"
                onClick={() => setFilter("material_id", "")}
              >
                <X className="size-3" />
              </button>
            </Badge>
          </div>
        )}

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={12} />
        ) : (
          <DataTable columns={columns} data={items} emptyMessage={t("hq.recipes.empty")} />
        )}

        <Pagination
          meta={meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      </PageContent>

      <RejectRecipeDialog
        brandSlug={brandSlug}
        recipeId={rejectTargetId}
        open={rejectTargetId !== null}
        onOpenChange={(v) => {
          if (!v) setRejectTargetId(null);
        }}
      />

      <DeleteConfirmDialog
        open={!!confirmSingle}
        onOpenChange={(open) => {
          if (!open) setConfirmSingle(null);
        }}
        description={
          confirmSingle ? t("hq.recipes.delete_confirm", { name: confirmSingle.name }) : ""
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
        title={t("hq.recipes.bulk_delete_title", { n: selected.size })}
        description={t("hq.recipes.bulk_delete_desc")}
        onConfirm={handleBulkDelete}
        isPending={bulkDelete.isPending}
      />
    </>
  );
}
