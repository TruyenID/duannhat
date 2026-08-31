"use client";

import { useState } from "react";
import Link from "next/link";
import { notFound, useParams, useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { EllipsisVertical, Folder, FolderPlus, Pencil, Percent, Trash2, RotateCcw } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Button } from "@godxjp/ui";
import { StatusBadge } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { ApiError, apiFetch } from "@/lib/api";
import {
  useCategories,
  useCategory,
  useDeleteCategory,
  useRestoreCategory,
  useBulkDeleteCategories,
} from "@/hooks/api/use-categories";

import { ApplyTaxTypeDialog } from "../components/apply-tax-type-dialog";
import { CategoryFormDialog } from "../components/category-form-dialog";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";

import { Spinner } from "@godxjp/ui";
interface BrandResponse {
  data: { id: string; slug: string; name: string };
}

/**
 * Category detail page.
 *
 * Shows metadata for a single category plus the list of its direct children.
 * Edit lives in a Dialog opened from the header — the row click on the
 * parent list page navigates here instead of opening an edit drawer
 * (see debug-001).
 */
export default function CategoryDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string; id: string }>();
  const router = useRouter();
  const brandSlug = params.brandSlug;
  const id = params.id;

  const { data: brandResponse } = useQuery({
    queryKey: ["hq", "brand", brandSlug],
    queryFn: () => apiFetch<BrandResponse>(`/api/v1/hq/${brandSlug}`),
    staleTime: 5 * 60 * 1000,
  });
  const brandId = brandResponse?.data.id ?? "";

  const { data: categoryResponse, isLoading, isError, error } = useCategory(brandSlug, id);
  const category = categoryResponse?.data;

  // Fetch direct children of the current category using the parent_id filter.
  const { data: childrenResponse } = useCategories(brandSlug, { parent_id: id, per_page: 100 });
  const children = childrenResponse?.data ?? [];

  // All categories — needed for the parent select in the edit/create dialogs.
  const { data: listResponse } = useCategories(brandSlug, { per_page: 100 });
  const allCategories = listResponse?.data ?? [];

  const deleteOne = useDeleteCategory(brandSlug);
  const restore = useRestoreCategory(brandSlug);
  const bulkDelete = useBulkDeleteCategories(brandSlug);

  const [editOpen, setEditOpen] = useState(false);
  const [applyTaxOpen, setApplyTaxOpen] = useState(false);
  const [createChildOpen, setCreateChildOpen] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [confirmBulk, setConfirmBulk] = useState(false);
  const [confirmSingle, setConfirmSingle] = useState<{
    id: string;
    name: string;
    childrenCount?: number;
    isParent?: boolean;
  } | null>(null);

  const selectableChildren = children.filter((c) => !c.deleted_at);

  function toggleSelect(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    if (selected.size === selectableChildren.length && selectableChildren.length > 0) {
      setSelected(new Set());
    } else {
      setSelected(new Set(selectableChildren.map((c) => c.id)));
    }
  }

  function selectedChildrenCount(): number {
    let total = 0;
    for (const id of selected) {
      const child = children.find((c) => c.id === id);
      if (child?.children_count) total += child.children_count;
    }
    return total;
  }

  async function handleBulkDelete() {
    await bulkDelete.mutateAsync(Array.from(selected));
    setSelected(new Set());
    setConfirmBulk(false);
  }

  function handleDeleteChild(childId: string, childName: string, childrenCount?: number) {
    setConfirmSingle({ id: childId, name: childName, childrenCount, isParent: false });
  }

  function handleDelete() {
    if (!category) return;
    setConfirmSingle({
      id: category.id,
      name: category.name,
      childrenCount: category.children_count,
      isParent: true,
    });
  }

  async function handleConfirmSingle() {
    if (!confirmSingle) return;
    await deleteOne.mutateAsync(confirmSingle.id);
    if (confirmSingle.isParent) {
      router.push(`/hq/${brandSlug}/categories`);
    } else {
      setSelected((prev) => {
        const next = new Set(prev);
        next.delete(confirmSingle.id);
        return next;
      });
    }
    setConfirmSingle(null);
  }

  // 404 / 403 → Next.js not-found page (id doesn't exist or no access). MUST
  // run before the loading guard: on a missing id the query settles isError
  // with no data, so `!category` would otherwise keep the page stuck on the
  // loading spinner forever instead of showing 404 (TC-CAT-DET2).
  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }

  // Other load errors (5xx / network) → inline error. Also runs before the
  // loading guard so a failed fetch (no `category`) doesn't spin forever.
  if (isError) {
    return (
      <>
        <PageHeader
          title={t("hq.categories.detail.title_loading")}
          backHref={`/hq/${brandSlug}/categories`}
        />
        <PageContent>
          <div className="rounded-md border border-destructive/50 bg-destructive/5 p-4 text-sm text-destructive">
            {t("common.load_error")}
          </div>
        </PageContent>
      </>
    );
  }

  if (isLoading || !category) {
    return (
      <>
        <PageHeader
          title={t("hq.categories.detail.title_loading")}
          backHref={`/hq/${brandSlug}/categories`}
        />
        <PageContent>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("common.loading")}
          </div>
        </PageContent>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={category.name}
        description={category.sku || undefined}
        backHref={
          category.parent_id
            ? `/hq/${brandSlug}/categories/${category.parent_id}`
            : `/hq/${brandSlug}/categories`
        }
      >
        {category.deleted_at ? (
          <Button
            variant="outline"
            size="sm"
            className="h-7 gap-1 text-xs"
            onClick={() => restore.mutate(category.id)}
          >
            <RotateCcw className="size-3.5" />
            {t("common.restore")}
          </Button>
        ) : (
          <>
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={() => setCreateChildOpen(true)}
            >
              <FolderPlus className="size-3.5" />
              {t("hq.categories.detail.add_child")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={() => setEditOpen(true)}
            >
              <Pencil className="size-3.5" />
              {t("common.edit")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={() => setApplyTaxOpen(true)}
            >
              <Percent className="size-3.5" />
              {t("hq.categories.apply_tax.button")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={handleDelete}
            >
              <Trash2 className="size-3.5" />
              {t("common.delete")}
            </Button>
          </>
        )}
      </PageHeader>

      <PageContent>
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
          {/* Left: children list */}
          <section className="overflow-hidden rounded-lg border bg-card">
            {/* Section header */}
            <div className="flex min-h-[44px] items-center gap-2 border-b bg-muted/30 px-4">
              {selected.size > 0 ? (
                <>
                  <Button
                    variant="destructive"
                    size="sm"
                    className="h-7 gap-1 text-xs"
                    onClick={() => setConfirmBulk(true)}
                  >
                    <Trash2 className="size-3.5" />
                    {t("common.delete_selected", { n: selected.size })}
                  </Button>
                  <span className="text-xs text-muted-foreground">
                    {t("common.selected_count", { n: selected.size })}
                  </span>
                </>
              ) : (
                <>
                  {children.length > 0 && (
                    <Checkbox
                      checked={
                        selectableChildren.length > 0 && selected.size === selectableChildren.length
                      }
                      onCheckedChange={toggleSelectAll}
                      aria-label="Select all"
                    />
                  )}
                  <h2 className="flex-1 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                    {t("hq.categories.detail.children_heading", { n: children.length })}
                  </h2>
                </>
              )}
            </div>

            {/* Empty state */}
            {children.length === 0 ? (
              <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
                <div className="flex size-10 items-center justify-center rounded-full bg-muted">
                  <Folder className="size-5 text-muted-foreground" />
                </div>
                <p className="text-sm text-muted-foreground">
                  {t("hq.categories.detail.no_children")}
                </p>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 gap-1 text-xs"
                  onClick={() => setCreateChildOpen(true)}
                >
                  <FolderPlus className="size-3.5" />
                  {t("hq.categories.detail.add_first_child")}
                </Button>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <ul className="min-w-[480px] divide-y">
                  {children.map((child) => (
                    <li
                      key={child.id}
                      className="flex items-center gap-2 px-4 transition-colors hover:bg-muted/40"
                    >
                      {!child.deleted_at ? (
                        <Checkbox
                          checked={selected.has(child.id)}
                          onCheckedChange={() => toggleSelect(child.id)}
                          aria-label={`Select ${child.name}`}
                        />
                      ) : (
                        <div className="size-4 shrink-0" />
                      )}
                      <Link
                        href={`/hq/${brandSlug}/categories/${child.id}`}
                        className="flex flex-1 items-center justify-between gap-3 py-2.5 text-sm"
                      >
                        <span className="flex min-w-0 items-center gap-2">
                          <Folder className="size-3.5 shrink-0 text-muted-foreground" />
                          <span className="font-medium">{child.name}</span>
                          {child.sku && (
                            <code className="hidden shrink-0 rounded bg-muted px-1.5 py-0.5 text-xs sm:inline">
                              {child.sku}
                            </code>
                          )}
                        </span>
                        <span className="flex shrink-0 items-center gap-2">
                          <StatusBadge
                            status={
                              child.deleted_at ? "deleted" : child.is_active ? "active" : "inactive"
                            }
                          />
                          {child.children_count !== undefined && child.children_count > 0 && (
                            <span className="hidden text-xs text-muted-foreground sm:inline">
                              {t("hq.categories.detail.children_suffix", {
                                n: child.children_count,
                              })}
                            </span>
                          )}
                        </span>
                      </Link>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="size-7 shrink-0">
                            <EllipsisVertical className="size-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          {child.deleted_at ? (
                            <DropdownMenuItem onClick={() => restore.mutate(child.id)}>
                              <RotateCcw className="mr-2 size-3.5" />
                              {t("common.restore")}
                            </DropdownMenuItem>
                          ) : (
                            <DropdownMenuItem
                              className="text-destructive"
                              onClick={() =>
                                handleDeleteChild(child.id, child.name, child.children_count)
                              }
                            >
                              <Trash2 className="mr-2 size-3.5" />
                              {t("common.delete")}
                            </DropdownMenuItem>
                          )}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </section>

          {/* Right: metadata sidebar */}
          <aside className="overflow-hidden rounded-lg border bg-card">
            <div className="border-b bg-muted/30 px-4 py-2.5">
              <h2 className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
                {t("common.details")}
              </h2>
            </div>
            <dl className="divide-y">
              <Detail label={t("common.status")}>
                <StatusBadge
                  status={
                    category.deleted_at ? "deleted" : category.is_active ? "active" : "inactive"
                  }
                />
              </Detail>
              <Detail label={t("product.sku")}>
                {category.sku ? (
                  <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{category.sku}</code>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </Detail>
              <Detail label={t("common.slug")}>
                {category.slug ? (
                  <code className="rounded bg-muted px-1.5 py-0.5 text-xs break-all">
                    {category.slug}
                  </code>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </Detail>
              <Detail label={t("common.parent")}>
                {category.parent ? (
                  <Link
                    href={`/hq/${brandSlug}/categories/${category.parent.id}`}
                    className="text-primary hover:underline"
                  >
                    {category.parent.name}
                  </Link>
                ) : (
                  <span className="text-muted-foreground">{t("common.top_level")}</span>
                )}
              </Detail>
              <Detail label={t("common.description")}>
                {category.description ? (
                  <p className="leading-relaxed">{category.description}</p>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </Detail>
              <Detail label={t("common.updated")}>
                <span className="text-muted-foreground">
                  {formatDateTime(category.updated_at, locale, timezone)}
                </span>
              </Detail>
            </dl>
          </aside>
        </div>
      </PageContent>

      <CategoryFormDialog
        brandSlug={brandSlug}
        brandId={brandId}
        open={editOpen}
        onOpenChange={setEditOpen}
        category={category}
        allCategories={allCategories}
      />
      <ApplyTaxTypeDialog
        brandSlug={brandSlug}
        categoryId={id}
        categoryName={category?.name ?? ""}
        open={applyTaxOpen}
        onOpenChange={setApplyTaxOpen}
      />
      <CategoryFormDialog
        brandSlug={brandSlug}
        brandId={brandId}
        open={createChildOpen}
        onOpenChange={setCreateChildOpen}
        allCategories={allCategories}
        initialParentId={id}
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

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex gap-3 px-4 py-2.5">
      <dt className="w-20 shrink-0 pt-0.5 text-xs text-muted-foreground">{label}</dt>
      <dd className="min-w-0 flex-1 text-sm">{children}</dd>
    </div>
  );
}
