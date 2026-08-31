"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { notFound, useParams, useRouter } from "next/navigation";
import { CheckCircle2, PlayCircle, PauseCircle, Save, Send, Trash2, XCircle } from "lucide-react";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";

import { ApiError } from "@/lib/api";
import { slugifyHyphen } from "@/lib/option-slug";
import {
  useActivateProduct,
  useApproveProduct,
  useDeactivateProduct,
  useDeleteProduct,
  useProduct,
  useRejectProduct,
  useSubmitProductForApproval,
  useUpdateProduct,
} from "@/hooks/api/use-products";
import { useProductTypeLookup } from "@/hooks/api/use-product-types";
import { useCategoryLookup } from "@/hooks/api/use-categories";
import { useTaxTypeLookup } from "@/hooks/api/use-tax-types";
import { useToppingGroupsForProduct } from "@/hooks/api/use-topping-groups";
import type { Product, ProductTranslations, UpdateProductInput } from "@/services/product-service";
import { DEFAULT_LOCALE } from "@/i18n";
import { buildI18nPayload, emptyLocaleMap } from "@/types/models/payload-helpers";
import { toast } from "sonner";

import { useTranslation } from "@/providers/app-provider";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Card } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import { BasicInfoCard } from "../components/basic-info-card";
import { ProductSidebar } from "../components/product-sidebar";
import { ProductStatusBadge } from "../components/product-status-badge";
import { VariantsDisplay } from "./components/variants-display";
import { ProductGalleryCard } from "./components/product-gallery-card";
import { ProductToppingGroupsCard } from "./components/product-topping-groups-card";
import { ProductToppingGroupsDetail } from "./components/product-topping-groups-detail";

interface FieldErrors {
  [field: string]: string[];
}

/**
 * Hydrate a TranslatableValue dict from the product's `translations` response.
 * Falls back to the top-level resolved string when translations are missing
 * (list responses, legacy rows) so the form never opens empty for an existing
 * product.
 */
function hydrateLocaleMap(
  translations: ProductTranslations | undefined,
  fallback: string | null,
  field: "name" | "description"
): TranslatableValue {
  const map = emptyLocaleMap();
  const locales: Array<keyof ProductTranslations> = ["ja", "en", "vi"];
  let touchedAny = false;
  for (const locale of locales) {
    const value = translations?.[locale]?.[field];
    if (value !== undefined && value !== null) {
      map[locale] = value;
      touchedAny = true;
    }
  }
  if (!touchedAny && fallback) {
    map[DEFAULT_LOCALE] = fallback;
  }
  return map;
}

// Hand-edited slug input only \u2014 this page never derives the slug from the name
// (that happens on create). A half-typed value must be left alone, so this uses
// the plain hyphen slugify rather than the non-empty `toResourceSlug` fallback.
function slugify(raw: string): string {
  return slugifyHyphen(raw);
}

export default function EditProductPage() {
  const router = useRouter();
  const params = useParams<{ brandSlug: string; id: string }>();
  const brandSlug = params.brandSlug;
  const id = params.id;

  const { t, locale } = useTranslation();
  const productQuery = useProduct(brandSlug, id);
  const product: Product | undefined = productQuery.data?.data;

  const toppingGroupsQuery = useToppingGroupsForProduct(brandSlug, id);
  const assignedToppingGroups = (toppingGroupsQuery.data?.data ?? []).map((g) => ({
    id: g.id,
    name: g.name,
  }));

  const update = useUpdateProduct(brandSlug);
  const deleteOne = useDeleteProduct(brandSlug);

  // Workflow transition hooks — each calls a dedicated endpoint so the BE
  // state machine (assertStatus) stays authoritative.
  const submitForApproval = useSubmitProductForApproval(brandSlug);
  const approveMut = useApproveProduct(brandSlug);
  const rejectMut = useRejectProduct(brandSlug);
  const activateMut = useActivateProduct(brandSlug);
  const deactivateMut = useDeactivateProduct(brandSlug);

  // -- Form state --------------------------------------------------------
  const [name, setName] = useState<TranslatableValue>(emptyLocaleMap);
  const [slug, setSlug] = useState("");
  const [description, setDescription] = useState<TranslatableValue>(emptyLocaleMap);
  const [productTypeId, setProductTypeId] = useState("");
  const [isHidden, setIsHidden] = useState(false);
  const [categoryIds, setCategoryIds] = useState<string[]>([]);
  // plan-043 — null tax_type_id = inherit the brand default.
  const [taxTypeId, setTaxTypeId] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [hydrated, setHydrated] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [rejectDialogOpen, setRejectDialogOpen] = useState(false);
  const [rejectReason, setRejectReason] = useState("");
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);

  // Hydrate the editable form fields once the API payload arrives. Variants
  // (options + skus) are read directly from `product.options` / `product.skus`
  // at render time by <VariantsDisplay /> — no local state needed because
  // BE `PUT /products/{id}` does not accept nested mutations anyway, so
  // there's nothing to buffer.
  useEffect(() => {
    if (!product || hydrated) return;
    setName(hydrateLocaleMap(product.translations, product.name, "name"));
    setDescription(hydrateLocaleMap(product.translations, product.description, "description"));
    setSlug(product.slug ?? "");
    setProductTypeId(product.product_type_id ?? "");
    setIsHidden(product.is_hidden);
    setCategoryIds((product.categories ?? []).map((c) => c.id));
    setTaxTypeId(product.tax_type_id ?? null);
    setHydrated(true);
  }, [product, hydrated]);

  // -- Lookups -----------------------------------------------------------
  // Hooks thread `locale` into the queryKey so a language switch invalidates
  // the cache and refetches translated labels. Bypassing them with an inline
  // useQuery (queryKey w/o locale) caused sidebar labels to stay stale.
  const productTypes = useProductTypeLookup(brandSlug);
  const categories = useCategoryLookup(brandSlug);
  const taxTypes = useTaxTypeLookup(brandSlug);

  // Resolve the "effective" name (prefer default locale, fall back to first
  // non-empty) — mirrors new/page.tsx.
  const effectiveName = useMemo(() => {
    const preferred = name[DEFAULT_LOCALE]?.trim();
    if (preferred) return preferred;
    return (
      Object.values(name)
        .find((v) => v?.trim())
        ?.trim() ?? ""
    );
  }, [name]);

  const isTrashed = !!product?.deleted_at;

  // Dirty detection — comparing against the server snapshot we hydrated from.
  // Keeps the Save button off (and gates the unsaved-changes confirm dialog)
  // until the user actually touches a field.
  const hasChanges = useMemo(() => {
    if (!hydrated || !product) return false;
    const origName = hydrateLocaleMap(product.translations, product.name, "name");
    const origDesc = hydrateLocaleMap(product.translations, product.description, "description");
    const localesEqual = (a: TranslatableValue, b: TranslatableValue) =>
      (["ja", "en", "vi"] as const).every((l) => (a[l] ?? "") === (b[l] ?? ""));
    const origCategoryIds = (product.categories ?? []).map((c) => c.id);
    const catsEqual =
      categoryIds.length === origCategoryIds.length &&
      categoryIds.every((cid) => origCategoryIds.includes(cid));
    return !(
      localesEqual(name, origName) &&
      localesEqual(description, origDesc) &&
      slug === (product.slug ?? "") &&
      productTypeId === (product.product_type_id ?? "") &&
      isHidden === product.is_hidden &&
      taxTypeId === (product.tax_type_id ?? null) &&
      catsEqual
    );
  }, [
    hydrated,
    product,
    name,
    description,
    slug,
    productTypeId,
    isHidden,
    taxTypeId,
    categoryIds,
  ]);

  const canSubmit =
    hydrated &&
    !isTrashed &&
    effectiveName.length > 0 &&
    productTypeId !== "" &&
    hasChanges &&
    !update.isPending;

  const handleRequestExit = useCallback(() => {
    if (hasChanges) {
      setConfirmExitOpen(true);
      return;
    }
    router.push(`/hq/${brandSlug}/products`);
  }, [hasChanges, router, brandSlug]);

  async function handleDelete() {
    if (!product) return;
    await deleteOne.mutateAsync(product.id);
    setDeleteDialogOpen(false);
    router.push(`/hq/${brandSlug}/products`);
  }

  // -- Workflow transitions ------------------------------------------------
  // These only fire when the badge in the sidebar matches the required "from"
  // state — the BE double-checks via assertStatus, so a stale FE state at
  // worst surfaces a 422.

  const workflowPending =
    submitForApproval.isPending ||
    approveMut.isPending ||
    rejectMut.isPending ||
    activateMut.isPending ||
    deactivateMut.isPending;

  async function handleSubmitForApproval() {
    if (!product) return;
    try {
      await submitForApproval.mutateAsync(product.id);
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleApprove() {
    if (!product) return;
    try {
      await approveMut.mutateAsync(product.id);
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleReject() {
    if (!product) return;
    const reason = rejectReason.trim();
    if (!reason) {
      toast.error(t("hq.products.reject.reason_required"));
      return;
    }
    try {
      await rejectMut.mutateAsync({ id: product.id, reason });
      setRejectDialogOpen(false);
      setRejectReason("");
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleActivate() {
    if (!product) return;
    try {
      await activateMut.mutateAsync(product.id);
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleDeactivate() {
    if (!product) return;
    try {
      await deactivateMut.mutateAsync(product.id);
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleSubmit() {
    if (!product) return;
    setFieldErrors({});

    // Build locale overrides, strip locales whose `name` is empty (same
    // NOT NULL guard as new/page.tsx + product-create-dialog.tsx).
    const i18nPayload = buildI18nPayload({
      name,
      description,
    });
    for (const locale of Object.keys(i18nPayload)) {
      if (!i18nPayload[locale as keyof typeof i18nPayload]?.name?.trim()) {
        delete i18nPayload[locale as keyof typeof i18nPayload];
      }
    }

    const effectiveDescription =
      description[DEFAULT_LOCALE]?.trim() ||
      Object.values(description)
        .find((v) => v?.trim())
        ?.trim() ||
      "";

    // status is intentionally omitted — status transitions go through the
    // workflow endpoints (submit/approve/reject/activate/deactivate), not
    // through PUT /products/{id}.
    const payload: UpdateProductInput = {
      // effectiveName (not name[DEFAULT_LOCALE]) so the top-level mirror matches
      // canSubmit's fallback: editing with only en/vi filled (ja empty) must not
      // send name:"" → NOT NULL violation. Mirrors the description fallback above.
      name: effectiveName,
      slug: slug || null,
      product_type_id: productTypeId,
      description: effectiveDescription || null,
      is_hidden: isHidden,
      // plan-043 — null = inherit brand default.
      tax_type_id: taxTypeId,
      category_ids: categoryIds,
      ...i18nPayload,
    };

    try {
      await update.mutateAsync({ id: product.id, data: payload });
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { code?: string; errors?: Record<string, string[]> };
        // An illegal lifecycle move (godx-jp/godx-tempo#124) is a coded 422, not
        // a per-field validation error — the translated toast is fired by
        // useUpdateProduct's onError, so skip setFieldErrors here.
        if (body.code === "INVALID_STATUS_TRANSITION") {
          return;
        }
        if (body.errors) {
          setFieldErrors(body.errors);
        }
      }
    }
  }

  // -- Render ------------------------------------------------------------

  // Also wait for the hydrate effect to land before rendering the form —
  // the Tiptap-backed TranslatableRichText only reads its `value` prop on
  // mount, so rendering before hydration makes the description editor
  // initialise empty and then never pick up the real HTML. Waiting one
  // extra paint keeps the editor authoritative on the server's content.
  // 404 / 403 → Next.js not-found page (id doesn't exist or no access). MUST
  // run before the loading guard below: on a missing id the query settles into
  // isError with no data, so `!product` would otherwise keep the page stuck on
  // the loading spinner forever instead of showing 404 (TC-PROD-DET2).
  if (
    productQuery.error instanceof ApiError &&
    (productQuery.error.status === 404 || productQuery.error.status === 403)
  ) {
    notFound();
  }

  // Other load errors (5xx / network) → inline error. Also runs BEFORE the
  // loading guard so a failed fetch (no `product`) doesn't spin forever.
  if (productQuery.isError) {
    return (
      <>
        <PageHeader
          title={t("hq.products.edit_title")}
          description={t("hq.products.subtitle")}
          backHref={`/hq/${brandSlug}/products`}
        >
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8"
            onClick={handleRequestExit}
          >
            {t("common.cancel")}
          </Button>
        </PageHeader>
        <PageContent>
          <div className="rounded-md border border-destructive/50 bg-destructive/5 p-4 text-sm text-destructive">
            {t("common.load_error")}
          </div>
        </PageContent>
      </>
    );
  }

  // Genuine loading — `!product` narrows `product` to defined for the render
  // below. Reaches here only when the query has not errored.
  if (productQuery.isLoading || !product || !hydrated) {
    return (
      <>
        <PageHeader
          title={t("hq.products.edit_title")}
          description={t("hq.products.subtitle")}
          backHref={`/hq/${brandSlug}/products`}
        >
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8"
            onClick={handleRequestExit}
          >
            {t("common.cancel")}
          </Button>
        </PageHeader>
        <PageContent>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("common.loading")}
          </div>
        </PageContent>
      </>
    );
  }

  // Header title follows the user's CURRENT locale — not DEFAULT_LOCALE. Using
  // effectiveName (ja-first, for the submit mirror) here made the header always
  // render Japanese even when the user was viewing VI/EN. Resolve the active
  // locale from the hydrated per-locale `name`, then fall back to any other
  // filled locale, the top-level mirror, and finally the generic edit title.
  const headerTitle =
    name[locale]?.trim() ||
    Object.values(name)
      .find((v) => v?.trim())
      ?.trim() ||
    product.name ||
    t("hq.products.edit_title");

  // Workflow affordances — the visible buttons are derived from the current
  // status so the user can only attempt legal transitions.
  const showSubmit = !isTrashed && (product.status === "draft" || product.status === "rejected");
  const showApproveReject = !isTrashed && product.status === "pending";
  const showActivate =
    !isTrashed && (product.status === "approved" || product.status === "inactive");
  const showDeactivate = !isTrashed && product.status === "active";
  const disabledAll = update.isPending || deleteOne.isPending || workflowPending;

  return (
    <>
      <PageHeader
        title={headerTitle}
        description={t("hq.products.subtitle")}
        backHref={`/hq/${brandSlug}/products`}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleRequestExit}
          disabled={disabledAll}
        >
          {t("common.cancel")}
        </Button>

        {/* Workflow transitions */}
        {showSubmit && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5"
            onClick={handleSubmitForApproval}
            disabled={disabledAll}
          >
            {submitForApproval.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <Send className="size-3.5" />
            )}
            {product.status === "rejected"
              ? t("hq.products.workflow.resubmit")
              : t("hq.products.workflow.submit")}
          </Button>
        )}
        {showApproveReject && (
          <>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 border-red-300 text-red-700 hover:bg-red-50"
              onClick={() => setRejectDialogOpen(true)}
              disabled={disabledAll}
            >
              <XCircle className="size-3.5" />
              {t("hq.products.workflow.reject")}
            </Button>
            <Button
              type="button"
              size="sm"
              className="h-8 gap-1.5"
              onClick={handleApprove}
              disabled={disabledAll}
            >
              {approveMut.isPending ? (
                <Spinner className="size-3.5" />
              ) : (
                <CheckCircle2 className="size-3.5" />
              )}
              {t("hq.products.workflow.approve")}
            </Button>
          </>
        )}
        {showActivate && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 border-green-400 text-green-700 hover:bg-green-50"
            onClick={handleActivate}
            disabled={disabledAll}
          >
            {activateMut.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <PlayCircle className="size-3.5" />
            )}
            {t("hq.products.workflow.activate")}
          </Button>
        )}
        {showDeactivate && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5"
            onClick={handleDeactivate}
            disabled={disabledAll}
          >
            {deactivateMut.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <PauseCircle className="size-3.5" />
            )}
            {t("hq.products.workflow.deactivate")}
          </Button>
        )}

        {!isTrashed && (
          <Button
            type="button"
            variant="destructive"
            size="sm"
            className="h-8 gap-1.5"
            onClick={() => setDeleteDialogOpen(true)}
            disabled={disabledAll}
          >
            <Trash2 className="size-3.5" />
            {t("hq.products.delete_action")}
          </Button>
        )}
        <Button
          type="button"
          size="sm"
          className="h-8 gap-1.5"
          onClick={handleSubmit}
          disabled={!canSubmit || disabledAll}
        >
          {update.isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
          {t("hq.products.save")}
        </Button>
      </PageHeader>

      <PageContent>
        {isTrashed && (
          <div className="mb-4 rounded-md border border-amber-500/50 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            {t("hq.products.deleted_notice")}
          </div>
        )}
        {product.status === "rejected" && product.rejection_reason && (
          <div
            role="alert"
            className="mb-4 rounded-md border border-red-300 bg-red-50 px-3 py-2.5 text-xs text-red-800"
          >
            <div className="mb-1 flex items-center gap-1.5 font-semibold">
              <XCircle className="size-3.5" />
              {t("hq.products.workflow.rejected_title")}
            </div>
            <div className="leading-relaxed whitespace-pre-wrap">
              <b>{t("hq.products.workflow.rejection_reason_label")}</b> {product.rejection_reason}
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {/* LEFT main — form + variants */}
          <div className="flex flex-col gap-4 lg:col-span-8">
            <BasicInfoCard
              name={name}
              onNameChange={setName}
              slug={slug}
              onSlugChange={(v) => setSlug(slugify(v))}
              description={description}
              onDescriptionChange={setDescription}
              showBaseSkuPrice={false}
              nameError={fieldErrors["ja.name"]?.[0] ?? fieldErrors.name?.[0]}
              descriptionError={fieldErrors.description?.[0]}
              slugError={fieldErrors.slug?.[0]}
              disabled={isTrashed}
            />
            {/* Variants — read-only display, no edit affordance. See comment
                inside `variants-display.tsx` for why: BE `PUT /products/{id}`
                does not accept nested options/skus yet. */}
            <VariantsDisplay
              brandSlug={brandSlug}
              productId={id}
              options={product.options ?? []}
              skus={product.skus ?? []}
              productName={product.name}
            />
            <ProductToppingGroupsDetail
              brandSlug={brandSlug}
              productId={id}
              groups={assignedToppingGroups}
            />
          </div>

          {/* RIGHT sidebar — gallery / type / categories / topping groups / status */}
          <div className="flex flex-col gap-4 lg:col-span-4">
            <ProductGalleryCard
              brandSlug={brandSlug}
              productId={id}
              initialImages={product.gallery ?? []}
              disabled={isTrashed}
            />
            <ProductSidebar
              mode="edit"
              status={product.status}
              isHidden={isHidden}
              onIsHiddenChange={setIsHidden}
              productTypeId={productTypeId}
              onProductTypeIdChange={setProductTypeId}
              productTypes={productTypes.data?.data ?? []}
              productTypesLoading={productTypes.isLoading}
              categoryIds={categoryIds}
              onCategoryIdsChange={setCategoryIds}
              categories={categories.data?.data ?? []}
              categoriesLoading={categories.isLoading}
              taxTypeId={taxTypeId}
              onTaxTypeIdChange={setTaxTypeId}
              taxTypes={taxTypes.data?.data ?? []}
              taxTypesLoading={taxTypes.isLoading}
              taxTypeError={fieldErrors.tax_type_id?.[0]}
              productTypeError={fieldErrors.product_type_id?.[0]}
              disabled={isTrashed}
              showStatus={false}
            />
            <ProductToppingGroupsCard brandSlug={brandSlug} productId={id} disabled={isTrashed} />
            {/* Review stats — read-only aggregate from customer reviews */}
            {Number(product.review_total_count) > 0 && (
              <Card className="p-4">
                <div className="mb-3 text-sm font-semibold">{t("hq.products.sidebar.reviews")}</div>
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">
                    {t("hq.products.sidebar.reviews_total")}
                  </span>
                  <span className="font-medium">{product.review_total_count}</span>
                </div>
                <div className="mt-1.5 flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">
                    {t("hq.products.sidebar.reviews_positive")}
                  </span>
                  <span className="font-medium">{product.review_up_count}</span>
                </div>
                <div className="mt-1.5 flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">
                    {t("hq.products.sidebar.reviews_recommend")}
                  </span>
                  <span className="font-medium">
                    {Math.round(
                      (Number(product.review_up_count) / Number(product.review_total_count)) * 100
                    )}
                    %
                  </span>
                </div>
              </Card>
            )}
            {/* Status card — least interactive (workflow-driven), placed last */}
            <Card className="p-4">
              <div className="mb-3 text-sm font-semibold">
                {t("hq.products.sidebar.publish_status")}
              </div>
              <ProductStatusBadge status={product.status} variant="panel" />
              <div className="mt-2 border-t pt-2">
                <Label
                  htmlFor="product-hidden-toggle"
                  className="flex w-full cursor-pointer items-center justify-between gap-2 text-xs"
                >
                  <span className="text-muted-foreground">
                    {t("hq.products.sidebar.hide_from_menu")}
                  </span>
                  <Checkbox
                    id="product-hidden-toggle"
                    checked={isHidden}
                    onCheckedChange={(v) => setIsHidden(v === true)}
                    disabled={isTrashed}
                  />
                </Label>
              </div>
            </Card>
          </div>
        </div>
      </PageContent>

      {/* Delete confirm dialog — UX improvement lifted from reference design,
          replaces the browser `confirm()` used elsewhere. Styling uses the
          project's canonical Dialog / Button components. */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent aria-describedby={undefined} className="sm:max-w-105">
          <DialogHeader>
            <DialogTitle>{t("hq.products.delete_action")}</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            {t("hq.products.delete_confirm", { name: headerTitle })}
          </p>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteOne.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button variant="destructive" onClick={handleDelete} disabled={deleteOne.isPending}>
              {deleteOne.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.delete")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reject dialog — reason is required by BE (min 1 char, max 1000). */}
      <Dialog
        open={rejectDialogOpen}
        onOpenChange={(open) => {
          setRejectDialogOpen(open);
          if (!open) setRejectReason("");
        }}
      >
        <DialogContent aria-describedby={undefined} className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t("hq.products.reject.title")}</DialogTitle>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <p className="text-sm text-muted-foreground">{t("hq.products.reject.desc")}</p>
            <Textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              rows={4}
              maxLength={1000}
              placeholder={t("hq.products.reject.placeholder")}
              disabled={rejectMut.isPending}
              aria-label={t("hq.products.reject.reason_aria")}
              className="field-sizing-fixed"
            />
            <div className="text-right text-[11px] text-muted-foreground">
              {rejectReason.length}/1000
            </div>
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setRejectDialogOpen(false)}
              disabled={rejectMut.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={handleReject}
              disabled={rejectMut.isPending || !rejectReason.trim()}
            >
              {rejectMut.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("hq.products.reject.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Unsaved-changes confirmation — mirrors the one on new/page.tsx so
          the user can't lose edits by mistaking "Hủy bỏ" for a plain back. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={update.isPending}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                router.push(`/hq/${brandSlug}/products`);
              }}
              disabled={update.isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                void handleSubmit();
              }}
              disabled={!canSubmit}
            >
              {update.isPending ? (
                <Spinner className="mr-1.5 size-3.5" />
              ) : (
                <Save className="mr-1.5 size-3.5" />
              )}
              {t("hq.products.unsaved.save_product")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
